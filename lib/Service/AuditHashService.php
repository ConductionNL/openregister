<?php

/**
 * Service for cryptographic hash chaining on audit trail entries.
 *
 * Provides SHA-256 hash computation, chain verification, and genesis hash management
 * for the immutable audit trail system.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\AuditTrail;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Handles cryptographic hash chaining for audit trail entries.
 *
 * @package OCA\OpenRegister\Service
 *
 * @psalm-suppress UnusedClass
 *
 * Complexity is over phpmd's threshold (49 before the self-ownership guard in
 * acquireSealLock(), 50 with it; the sweep pass, its backlog counter and the
 * re-chain repair take it higher again). Suppressed rather than restructured:
 * the concerns here — canonical hashing, chain verification, the seal lock, the
 * sweep and the re-chain — are one cohesive job, and splitting an
 * audit-INTEGRITY class risks the property it exists to guarantee for no
 * behavioural gain. If it grows further it wants decomposing properly, not a
 * wider threshold.
 *
 * Length is over the 1000-line threshold for the same reason and with the same
 * caveat. The two long methods it used to carry are gone — rechainAll() and
 * verifyChain() now delegate their per-window work to rechainWindow() and
 * readChainWindow() — so what remains is breadth, not depth: one class holding
 * one property. The honest next step if it grows again is moving the
 * operator-initiated re-chain repair out to its own service, since a
 * destructive one-off is genuinely a different concern from the continuous
 * hash/seal/verify path. That is a refactor, not a threshold change.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 *
 * @spec openspec/specs/audit-hash-chain/spec.md
 */
class AuditHashService
{
    /**
     * The genesis seed used for the first entry in the hash chain.
     *
     * @var string
     */
    private const GENESIS_SEED = 'openregister-genesis-v1';

    /**
     * Well-known advisory lock key serializing ALL seal passes.
     *
     * The methods sealRow() and sealRows() race each other (and themselves
     * across requests): both read a predecessor hash and then UPDATE, so two
     * interleaved passes can chain a boundary row over a stale predecessor —
     * a false tamper alarm on the next verifyChain(). A single exclusive
     * lock on this key makes seal passes strictly sequential.
     *
     * @var string
     */
    private const SEAL_LOCK_KEY = 'openregister/audit-seal';

    /**
     * Number of acquisition attempts before giving up on the seal lock.
     *
     * @var int
     */
    private const SEAL_LOCK_ATTEMPTS = 3;

    /**
     * Delay between seal-lock acquisition attempts, in microseconds.
     *
     * @var int
     */
    private const SEAL_LOCK_RETRY_DELAY_USEC = 50000;

    /**
     * Rows sealed per sweep pass.
     *
     * Bounded so one cron tick cannot hold the seal lock for an unbounded time
     * while a large backlog drains — the sweep is resumable by construction,
     * since it always selects the oldest unsealed rows.
     *
     * @var integer
     */
    private const SWEEP_BATCH_SIZE = 500;

    /**
     * Most rows one sweep will re-chain in a single pass.
     *
     * The sweep cannot seal a gap in place. If a row was left unsealed and LATER
     * rows were then sealed inline, those later rows chained onto the newest
     * SEALED row — skipping the gap. Filling the gap afterwards gives it that
     * same predecessor, so two rows share one parent: a fan-out, which is
     * exactly the corruption this whole subsystem exists to detect. Observed
     * live: rows 455956 and 455957 both chained onto 455955.
     *
     * So the sweep re-chains from the oldest gap FORWARD, rewriting the tail.
     * That is only acceptable while the tail is small, hence this bound. A gap
     * with more rows after it than this is a backlog rather than a hiccup, and
     * belongs to the deliberate operator repair, not to a five-minute cron.
     *
     * @var integer
     */
    private const MAX_SWEEP_RECHAIN = 5000;

    /**
     * App-config key recording when the seal lock was last acquired.
     *
     * @var string
     */
    private const SEAL_LOCK_SINCE_KEY = 'audit_seal_lock_since';

    /**
     * Age past which a held seal lock is treated as abandoned, in seconds.
     *
     * A genuine pass is bounded by MAX_SWEEP_RECHAIN rows and finishes in
     * seconds, so fifteen minutes is far beyond anything legitimate while still
     * leaving a wide margin on a loaded instance.
     *
     * @var integer
     */
    private const STALE_SEAL_LOCK_SECONDS = 900;

    /**
     * Whether THIS service instance currently holds the seal lock.
     *
     * The seal lock is not re-entrant: acquiring it twice without releasing
     * throws LockedException (verified against DBLockingProvider). So when a
     * batch seal is in progress and a nested audit insert reaches sealRow(),
     * the acquisition cannot succeed — the holder is us, and we are further up
     * our own call stack, so waiting is waiting for ourselves.
     *
     * Tracking ownership lets acquireSealLock() answer that case immediately
     * instead of sleeping through its whole retry budget first.
     *
     * @var boolean
     */
    private bool $holdsSealLock = false;

    /**
     * Constructor for AuditHashService.
     *
     * @param IDBConnection    $db              The database connection
     * @param ILockingProvider $lockingProvider Advisory lock provider serializing seal passes
     * @param LoggerInterface  $logger          Logger for fail-soft lock warnings
     * @param IAppConfig       $appConfig       Records when the seal lock was taken, to detect an abandoned one
     */
    public function __construct(
        private readonly IDBConnection $db,
        private readonly ILockingProvider $lockingProvider,
        private readonly LoggerInterface $logger,
        private readonly IAppConfig $appConfig
    ) {
    }//end __construct()

    /**
     * Compute the genesis hash (used as previousHash for the first entry).
     *
     * @return string The SHA-256 hex digest of the genesis seed
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    public function getGenesisHash(): string
    {
        return hash('sha256', self::GENESIS_SEED);
    }//end getGenesisHash()

    /**
     * Get the canonical JSON representation of an audit trail entry for hashing.
     *
     * Excludes the `hash` and `previousHash` fields and uses sorted keys
     * with no whitespace (compact canonical form).
     *
     * @param AuditTrail $entry The audit trail entry
     *
     * @return string The canonical JSON string
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    public function getCanonicalJson(AuditTrail $entry): string
    {
        $data = $entry->jsonSerialize();

        // Remove hash chain fields from the canonical representation.
        unset($data['hash'], $data['previousHash']);

        // Sort keys for deterministic output.
        ksort($data);

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }//end getCanonicalJson()

    /**
     * Compute the SHA-256 hash for an audit trail entry.
     *
     * @param AuditTrail $entry        The audit trail entry to hash
     * @param string     $previousHash The hash of the previous entry in the chain
     *
     * @return string The SHA-256 hex digest
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    public function computeHash(AuditTrail $entry, string $previousHash): string
    {
        $canonicalJson = $this->getCanonicalJson(entry: $entry);

        return hash('sha256', $previousHash.$canonicalJson);
    }//end computeHash()

    /**
     * Get the hash of the most recent audit trail entry.
     *
     * Returns the genesis hash if no entries exist or the last entry has no hash.
     *
     * @return string The hash of the last entry or the genesis hash
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    public function getLastHash(): string
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('hash')
            ->from('openregister_audit_trails')
            ->orderBy('id', 'DESC')
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        if ($row === false || $row['hash'] === null || $row['hash'] === '') {
            return $this->getGenesisHash();
        }

        return $row['hash'];
    }//end getLastHash()

    /**
     * Seal an already-inserted audit-trail row into the hash chain.
     *
     * Reads the row by id, derives `previousHash` from the nearest PRIOR
     * SEALED row (or genesis), and computes `hash` over the row using the SAME
     * `mapRowToEntity()` + `hydrate()` + `getCanonicalJson()` path that
     * {@see verifyChain()} uses — guaranteeing the stored hash re-verifies
     * exactly. The `hash` / `previous_hash` values are then written directly.
     * Call this AFTER inserting the row so the id and all persisted (DB-typed)
     * field values are final.
     *
     * The critical section (predecessor read + hash write) runs under the
     * exclusive {@see self::SEAL_LOCK_KEY} advisory lock shared with
     * {@see sealRows()}. Fail-soft on contention: when the lock cannot be
     * acquired within a short bounded wait the row is left unsealed (a later
     * seal pass picks it up — {@see getHashBefore()} chains over unsealed
     * rows exactly like verifyChain() does) rather than blocking the write
     * path.
     *
     * @param int $id The audit-trail row id to seal.
     *
     * @return bool True when the row was found and sealed.
     *
     * @spec openspec/specs/audit-hash-chain/spec.md
     */
    public function sealRow(int $id): bool
    {
        if ($this->acquireSealLock() === false) {
            $this->logger->warning(
                '[AuditHashService] seal lock unavailable, leaving audit row '.$id.' unsealed (a later seal pass will chain it)'
            );

            return false;
        }

        try {
            return $this->sealRowLocked(id: $id);
        } finally {
            $this->releaseSealLock();
        }
    }//end sealRow()

    /**
     * Seal a single row — body of {@see sealRow()}, caller holds the seal lock.
     *
     * @param int $id The audit-trail row id to seal.
     *
     * @return bool True when the row was found and sealed.
     */
    private function sealRowLocked(int $id): bool
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('openregister_audit_trails')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        if ($row === false) {
            return false;
        }

        $previousHash = $this->getHashBefore(id: $id);
        if ($previousHash === null) {
            $previousHash = $this->getGenesisHash();
        }

        $entry = new AuditTrail();
        $entry->hydrate(object: $this->mapRowToEntity(row: $row));

        $hash = $this->computeHash(entry: $entry, previousHash: $previousHash);

        $update = $this->db->getQueryBuilder();
        $update->update('openregister_audit_trails')
            ->set('hash', $update->createNamedParameter($hash))
            ->set('previous_hash', $update->createNamedParameter($previousHash))
            ->where($update->expr()->eq('id', $update->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        $update->executeStatement();

        return true;

    }//end sealRowLocked()

    /**
     * Seal a batch of already-inserted audit-trail rows into the hash chain.
     *
     * Batched counterpart of {@see sealRow()} for bulk audit inserts: instead
     * of 3 queries per row (row SELECT + previous-hash SELECT + UPDATE) it
     * runs one range SELECT, one previous-hash SELECT, and one CASE-based
     * UPDATE for the whole batch. To keep the chain verifiable when foreign
     * rows interleave with the batch (concurrent writers), ALL unsealed rows
     * in the id range [min(ids), max(ids)] are sealed — the hash computed for
     * any row is deterministic (same canonical JSON + same predecessor), so
     * sealing an interleaved row here yields the identical value its own
     * sealRow() call would produce. Already-sealed rows are left untouched
     * and only contribute their stored hash as the chain link.
     *
     * Seal passes are serialized under the exclusive
     * {@see self::SEAL_LOCK_KEY} advisory lock shared with {@see sealRow()}:
     * without it a concurrent writer could seal a boundary row between our
     * getHashBefore() read and our UPDATE (or vice versa), chaining one link
     * over a stale predecessor — a false tamper alarm on the next
     * verifyChain(). Fail-soft on contention: when the lock cannot be
     * acquired within a short bounded wait the rows are left unsealed (a
     * later seal pass chains them; unsealed rows are skipped by both
     * verifyChain() and getHashBefore()) rather than blocking the write
     * path. Rows inserted by a concurrent uncommitted transaction that land
     * inside the range after our range SELECT stay unsealed too — harmless
     * for the same reason.
     *
     * @param int[] $ids The audit-trail row ids to seal.
     *
     * @return int Number of rows sealed.
     *
     * @spec openspec/specs/audit-hash-chain/spec.md
     */
    public function sealRows(array $ids): int
    {
        $ids = array_values(
            array_filter(
                array_map('intval', $ids),
                static function (int $id): bool {
                    return $id > 0;
                }
            )
        );

        if ($ids === []) {
            return 0;
        }

        if ($this->acquireSealLock() === false) {
            $this->logger->warning(
                '[AuditHashService] seal lock unavailable, leaving '.count($ids).' audit rows unsealed (a later seal pass will chain them)'
            );

            return 0;
        }

        try {
            return $this->sealRowsLocked(ids: $ids);
        } finally {
            $this->releaseSealLock();
        }
    }//end sealRows()

    /**
     * Seal rows that were left unsealed, oldest first.
     *
     * This is the "later seal pass" that sealRow() and sealRows() have always
     * promised in their fail-soft log messages. Until now it did not exist:
     * nothing swept unsealed rows, so every fail-soft skip was permanent. On the
     * instance this was written against, 49,123 of 308,937 audit rows — 15.9% —
     * had no hash, and never would have.
     *
     * That matters more than it sounds. The chain's whole purpose is to make
     * tampering DETECTABLE; a row with no hash is a row nobody can vouch for.
     *
     * Works oldest-first and in id order so each batch chains onto an already
     * settled predecessor, and delegates to sealRows() so a batch derives its
     * predecessor once and chains forward, rather than re-deriving it per row
     * the way sealRow() must.
     *
     * @param int $limit Maximum rows to seal in one pass.
     *
     * @return int The number of rows sealed.
     *
     * @spec openspec/specs/audit-hash-chain/spec.md
     */
    public function sealUnsealed(int $limit=self::SWEEP_BATCH_SIZE): int
    {
        if ($limit < 1) {
            return 0;
        }

        $fromId = $this->getEarliestUnsealedId();
        if ($fromId === null) {
            return 0;
        }

        // Re-chaining the tail is bounded, because this runs on a five-minute
        // cron and must not turn into a 300k-row rewrite. A gap older than the
        // window is a backlog, not a hiccup, and belongs to the operator repair.
        $tail = $this->countRowsFrom(fromId: $fromId);
        if ($tail > self::MAX_SWEEP_RECHAIN) {
            $this->logger->warning(
                message: sprintf(
                    '[AuditHashService] Oldest unsealed audit row is id %d, with %d row(s) after it — '
                    .'beyond the %d the sweep may rewrite in one pass. Sealing it in place would fan the '
                    .'chain out, so the sweep is standing down. Run occ openregister:rechain-audit-trail.',
                    $fromId,
                    $tail,
                    self::MAX_SWEEP_RECHAIN
                ),
                context: ['file' => __FILE__, 'line' => __LINE__, 'app' => 'openregister']
            );

            return 0;
        }

        if ($this->acquireSealLock() === false) {
            $this->logger->warning(
                message: '[AuditHashService] Seal sweep skipped: seal lock unavailable.',
                context: ['file' => __FILE__, 'line' => __LINE__, 'app' => 'openregister']
            );

            return 0;
        }

        try {
            $previousHash = $this->getHashBefore(id: $fromId);
            if ($previousHash === null) {
                $previousHash = $this->getGenesisHash();
            }

            $sealed  = 0;
            $afterId = ($fromId - 1);

            while (true) {
                $qb = $this->db->getQueryBuilder();
                $qb->select('*')
                    ->from('openregister_audit_trails')
                    ->where($qb->expr()->gt('id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
                    ->orderBy('id', 'ASC')
                    ->setMaxResults($limit);

                $result = $qb->executeQuery();
                $rows   = $result->fetchAll();
                $result->closeCursor();

                if ($rows === []) {
                    break;
                }

                $window       = $this->rechainWindow(rows: $rows, previousHash: $previousHash);
                $previousHash = $window['previousHash'];
                $sealed      += $window['rechained'];
                $afterId      = $window['lastId'];
            }//end while

            return $sealed;
        } finally {
            $this->releaseSealLock();
        }//end try

    }//end sealUnsealed()

    /**
     * The id of the oldest row still missing a hash.
     *
     * @return int|null The id, or null when every row is sealed.
     *
     * @spec openspec/specs/audit-hash-chain/spec.md
     */
    private function getEarliestUnsealedId(): ?int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from('openregister_audit_trails')
            ->where($qb->expr()->isNull('hash'))
            ->orderBy('id', 'ASC')
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $id     = $result->fetchOne();
        $result->closeCursor();

        if ($id === false || $id === null) {
            return null;
        }

        return (int) $id;

    }//end getEarliestUnsealedId()

    /**
     * How many rows sit at or after the given id.
     *
     * @param int $fromId The inclusive lower bound.
     *
     * @return int The row count.
     *
     * @spec openspec/specs/audit-hash-chain/spec.md
     */
    private function countRowsFrom(int $fromId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('id', 'tail'))
            ->from('openregister_audit_trails')
            ->where($qb->expr()->gte('id', $qb->createNamedParameter($fromId, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $count  = $result->fetchOne();
        $result->closeCursor();

        return (int) $count;

    }//end countRowsFrom()

    /**
     * Count rows still awaiting a seal.
     *
     * Exposed so the sweep job can report progress and so an operator can see
     * whether the backlog is draining. A backlog that only grows means sealing
     * is failing somewhere, which is exactly the condition that went unnoticed
     * for as long as no sweeper existed.
     *
     * @return int The number of unsealed rows.
     *
     * @spec openspec/specs/audit-hash-chain/spec.md
     */
    public function countUnsealed(): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('id', 'unsealed'))
            ->from('openregister_audit_trails')
            ->where($qb->expr()->isNull('hash'));

        $result = $qb->executeQuery();
        $count  = $result->fetchOne();
        $result->closeCursor();

        return (int) $count;

    }//end countUnsealed()

    /**
     * A cheap, page-load-safe summary of chain health.
     *
     * Deliberately NOT a verification. {@see verifyChain()} reads every row and
     * recomputes every hash — on the instance this was written against that is
     * 308,937 SHA-256 computations, far too slow to run because somebody opened
     * a settings page. This is three COUNT/MAX queries, so an admin sees the one
     * number that actually rots over time — how many rows the chain cannot vouch
     * for — without paying for a full walk.
     *
     * Verification stays an explicit, operator-initiated action. The distinction
     * matters: "no rows are unsealed" says the sweeper is keeping up, and says
     * nothing at all about whether the hashes that ARE stored still agree with
     * their rows. Only verifyChain() can answer that, and the UI must not let
     * the cheap number stand in for the expensive one.
     *
     * @return array{total: int, sealed: int, unsealed: int, coverage: float, lastSealedId: int|null}
     *
     * @spec openspec/specs/audit-hash-chain/spec.md
     */
    public function getIntegrityStatus(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('id', 'total'))
            ->from('openregister_audit_trails');
        $result = $qb->executeQuery();
        $total  = (int) $result->fetchOne();
        $result->closeCursor();

        $unsealed = $this->countUnsealed();
        $sealed   = ($total - $unsealed);

        $qb = $this->db->getQueryBuilder();
        // The max() function builder takes only the field — unlike count(), it
        // has no alias parameter. A second argument is silently swallowed by
        // PHP rather than aliasing anything, so it reads as intent that never
        // happened. fetchOne() takes the first column regardless.
        $qb->select($qb->func()->max('id'))
            ->from('openregister_audit_trails')
            ->where($qb->expr()->isNotNull('hash'));
        $result     = $qb->executeQuery();
        $lastSealed = $result->fetchOne();
        $result->closeCursor();

        $coverage = 100.0;
        if ($total > 0) {
            $coverage = round((($sealed / $total) * 100), 2);
        }

        $lastSealedId = null;
        if ($lastSealed !== false && $lastSealed !== null) {
            $lastSealedId = (int) $lastSealed;
        }

        return [
            'total'        => $total,
            'sealed'       => $sealed,
            'unsealed'     => $unsealed,
            'coverage'     => $coverage,
            'lastSealedId' => $lastSealedId,
        ];

    }//end getIntegrityStatus()

    /**
     * Re-chain EVERY row from genesis, replacing any stored hash.
     *
     * A deliberate, destructive repair — the only operation here that rewrites
     * hashes that already exist. It is exposed as an occ command and never runs
     * on a schedule, because "something rewrote the audit hashes" is precisely
     * the event the chain is built to make suspicious. An operator must ask for
     * it, and the run is logged at warning level so the rewrite is itself part
     * of the record.
     *
     * It exists because sealing predates the seal lock. Before
     * {@see self::SEAL_LOCK_KEY} was introduced, concurrent passes could each
     * read the same predecessor and then write, so many rows ended up chained
     * onto ONE predecessor — 5,314 such rows over 2,413 predecessors on the
     * instance this was written against, one of them shared by 442 rows. That is
     * a fan-out, not a chain, and verifyChain() rightly calls it broken.
     * sealUnsealed() cannot repair it: it seals rows with NO hash, not rows with
     * a WRONG one.
     *
     * Walks in id order under the seal lock, deriving each row's previousHash
     * from the row actually before it, so the result is a single chain by
     * construction rather than by luck.
     *
     * Re-chains every row that CAN be re-chained, including rows that never had
     * a hash, so a single run repairs both failure modes at once: the gaps the
     * sweeper exists to close, and the mis-chained rows it cannot touch.
     *
     * The one exception is a retention tombstone. Its payload was lawfully
     * destroyed, so it cannot re-hash; its stored hash is carried forward as the
     * next row's predecessor and left untouched, exactly as verifyChain() treats
     * it. Rewriting it would hash the emptied row and destroy the only evidence
     * the tombstone still carries.
     *
     * @param int $batchSize Rows re-chained per query window.
     *
     * @return array{rechained: int, tombstonesPreserved: int} Rows rewritten, and tombstones left alone.
     *
     * @spec openspec/specs/audit-hash-chain/spec.md
     */
    public function rechainAll(int $batchSize=self::SWEEP_BATCH_SIZE): array
    {
        $this->logger->warning(
            message: '[AuditHashService] FULL RE-CHAIN STARTED — every audit hash will be recomputed. '
                .'This rewrites stored hashes and must only ever run as a deliberate operator repair.',
            context: ['file' => __FILE__, 'line' => __LINE__, 'app' => 'openregister']
        );

        if ($this->acquireSealLock() === false) {
            $this->logger->error(
                message: '[AuditHashService] Re-chain aborted: seal lock unavailable. '
                    .'Refusing to re-chain while another seal pass may be writing.',
                context: ['file' => __FILE__, 'line' => __LINE__, 'app' => 'openregister']
            );

            return [
                'rechained'           => 0,
                'tombstonesPreserved' => 0,
            ];
        }

        try {
            $previousHash        = $this->getGenesisHash();
            $rechained           = 0;
            $tombstonesPreserved = 0;
            $afterId = 0;

            while (true) {
                $qb = $this->db->getQueryBuilder();
                $qb->select('*')
                    ->from('openregister_audit_trails')
                    ->where($qb->expr()->gt('id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
                    ->orderBy('id', 'ASC')
                    ->setMaxResults($batchSize);

                $result = $qb->executeQuery();
                $rows   = $result->fetchAll();
                $result->closeCursor();

                if ($rows === []) {
                    break;
                }

                $window = $this->rechainWindow(rows: $rows, previousHash: $previousHash);

                $previousHash         = $window['previousHash'];
                $rechained           += $window['rechained'];
                $tombstonesPreserved += $window['tombstonesPreserved'];
                $afterId = $window['lastId'];
            }//end while

            $this->logger->warning(
                message: sprintf(
                    '[AuditHashService] FULL RE-CHAIN COMPLETE — %d audit row(s) re-chained from genesis, '
                    .'%d retention tombstone(s) left untouched.',
                    $rechained,
                    $tombstonesPreserved
                ),
                context: ['file' => __FILE__, 'line' => __LINE__, 'app' => 'openregister']
            );

            return [
                'rechained'           => $rechained,
                'tombstonesPreserved' => $tombstonesPreserved,
            ];
        } finally {
            $this->releaseSealLock();
        }//end try

    }//end rechainAll()

    /**
     * Re-chain one window of rows, carrying the chain across it.
     *
     * Split out of {@see rechainAll()} so the outer method reads as the repair's
     * shape — acquire the lock, walk windows, report — and this one holds the
     * per-row rule. The caller holds the seal lock.
     *
     * @param array<int, array<string, mixed>> $rows         One window, in id order.
     * @param string                           $previousHash Hash of the row before this window.
     *
     * @return array{previousHash: string, rechained: int, tombstonesPreserved: int, lastId: int}
     *
     * @spec openspec/specs/audit-hash-chain/spec.md
     */
    private function rechainWindow(array $rows, string $previousHash): array
    {
        $rechained           = 0;
        $tombstonesPreserved = 0;
        $lastId = 0;

        foreach ($rows as $row) {
            $lastId = (int) $row['id'];

            // A retention tombstone must NOT be re-chained. Its payload was
            // lawfully destroyed, so recomputing its hash would hash the emptied
            // row and silently replace the one piece of evidence the tombstone
            // still carries — under a banner reading "repair". Its stored hash
            // is also the link the next row committed to, so carrying it forward
            // keeps the chain continuous: verifyChain() treats a tombstone
            // exactly this way, and the two must agree or a re-chain would
            // manufacture the break it was run to fix.
            if (($row['purged_at'] ?? null) !== null) {
                $storedHash = ($row['hash'] ?? null);
                if ($storedHash !== null && $storedHash !== '') {
                    $previousHash = $storedHash;
                }

                $tombstonesPreserved++;
                continue;
            }

            $entry = new AuditTrail();
            $entry->hydrate(object: $this->mapRowToEntity(row: $row));

            $hash = $this->computeHash(entry: $entry, previousHash: $previousHash);

            $update = $this->db->getQueryBuilder();
            $update->update('openregister_audit_trails')
                ->set('hash', $update->createNamedParameter($hash))
                ->set('previous_hash', $update->createNamedParameter($previousHash))
                ->where(
                    $update->expr()->eq(
                        'id',
                        $update->createNamedParameter($lastId, IQueryBuilder::PARAM_INT)
                    )
                );
            $update->executeStatement();

            // Every row becomes the predecessor of the next, which is what makes
            // the result one chain rather than a fan-out.
            $previousHash = $hash;
            $rechained++;
        }//end foreach

        return [
            'previousHash'        => $previousHash,
            'rechained'           => $rechained,
            'tombstonesPreserved' => $tombstonesPreserved,
            'lastId'              => $lastId,
        ];

    }//end rechainWindow()

    /**
     * Seal a batch of rows — body of {@see sealRows()}, caller holds the
     * seal lock and has already normalised `$ids` to positive integers.
     *
     * @param int[] $ids The audit-trail row ids to seal (non-empty).
     *
     * @return int Number of rows sealed.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Chain walking requires sealed/unsealed branching
     * @SuppressWarnings(PHPMD.NPathComplexity)      Same — early-outs plus per-row sealed/unsealed paths
     */
    private function sealRowsLocked(array $ids): int
    {
        $minId = min($ids);
        $maxId = max($ids);

        // Fetch every row in the id range so interleaved foreign rows keep
        // their place in the chain.
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('openregister_audit_trails')
            ->where($qb->expr()->gte('id', $qb->createNamedParameter($minId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->lte('id', $qb->createNamedParameter($maxId, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'ASC');

        $result = $qb->executeQuery();
        $rows   = $result->fetchAll();
        $result->closeCursor();

        if ($rows === []) {
            return 0;
        }

        $previousHash = $this->getHashBefore(id: $minId);
        if ($previousHash === null) {
            $previousHash = $this->getGenesisHash();
        }

        // Walk the range in id order, computing hashes for unsealed rows.
        $updates = [];
        foreach ($rows as $row) {
            $storedHash = ($row['hash'] ?? null);
            if ($storedHash !== null && $storedHash !== '') {
                // Already sealed — adopt its hash as the next chain link.
                $previousHash = $storedHash;
                continue;
            }

            $entry = new AuditTrail();
            $entry->hydrate(object: $this->mapRowToEntity(row: $row));

            $hash = $this->computeHash(entry: $entry, previousHash: $previousHash);

            $updates[(int) $row['id']] = [
                'hash'         => $hash,
                'previousHash' => $previousHash,
            ];

            $previousHash = $hash;
        }

        if ($updates === []) {
            return 0;
        }

        // Single CASE-based UPDATE for the whole batch.
        $tableName  = $this->db->getQueryBuilder()->getTableName('openregister_audit_trails');
        $hashCases  = [];
        $prevCases  = [];
        $parameters = [];
        foreach ($updates as $id => $values) {
            $hashCases[]  = 'WHEN '.((int) $id).' THEN ?';
            $parameters[] = $values['hash'];
        }

        foreach ($updates as $id => $values) {
            $prevCases[]  = 'WHEN '.((int) $id).' THEN ?';
            $parameters[] = $values['previousHash'];
        }

        $idList = implode(',', array_map('intval', array_keys($updates)));

        $sql = 'UPDATE '.$tableName
            .' SET hash = CASE id '.implode(' ', $hashCases).' ELSE hash END,'
            .' previous_hash = CASE id '.implode(' ', $prevCases).' ELSE previous_hash END'
            .' WHERE id IN ('.$idList.')';

        $this->db->executeStatement($sql, $parameters);

        return count($updates);
    }//end sealRowsLocked()

    /**
     * Try to acquire the exclusive advisory lock serializing seal passes.
     *
     * Bounded, short wait: {@see self::SEAL_LOCK_ATTEMPTS} attempts with
     * {@see self::SEAL_LOCK_RETRY_DELAY_USEC} between them. Sealing is
     * fail-soft, so on sustained contention the caller logs and leaves the
     * rows unsealed instead of blocking the surrounding write path.
     *
     * @return bool True when the lock was acquired.
     */
    private function acquireSealLock(): bool
    {
        // We already hold it: refuse WITHOUT waiting.
        //
        // The lock is exclusive and not re-entrant, so a second acquisition from
        // inside our own critical section can never succeed. Retrying means
        // sleeping SEAL_LOCK_ATTEMPTS x SEAL_LOCK_RETRY_DELAY_USEC — 100 ms —
        // for a holder that is this same call stack, and which cannot release
        // until we return.
        //
        // This is the common case during a configuration import, not an edge
        // case: sealRows() takes the lock for a batch, an object write inside
        // that batch appends its own audit row, and insertHashChained() calls
        // sealRow() for it. One import produced 1,041 of these, each paying the
        // full 100 ms — roughly 100 seconds of a repair run spent in usleep(),
        // which is why the process looked busy while the database sat idle.
        //
        // Returning false is the SAME outcome as before, reached immediately.
        // Sealing is fail-soft by design: the caller logs, leaves the row
        // unsealed, and a later seal pass chains it.
        if ($this->holdsSealLock === true) {
            return false;
        }

        for ($attempt = 1; $attempt <= self::SEAL_LOCK_ATTEMPTS; $attempt++) {
            try {
                $this->lockingProvider->acquireLock(self::SEAL_LOCK_KEY, ILockingProvider::LOCK_EXCLUSIVE);
                $this->holdsSealLock = true;
                $this->appConfig->setValueInt('openregister', self::SEAL_LOCK_SINCE_KEY, time());

                return true;
            } catch (LockedException) {
                // Held by ANOTHER process — a concurrent cron seal pass, say.
                // That holder can finish and release, so waiting is worthwhile.
                if ($attempt < self::SEAL_LOCK_ATTEMPTS) {
                    usleep(self::SEAL_LOCK_RETRY_DELAY_USEC);
                }
            }
        }

        return $this->breakStaleSealLock();
    }//end acquireSealLock()

    /**
     * Release a seal lock whose holder died, and take it.
     *
     * ILockingProvider has no owner and no liveness check: a process killed
     * inside its critical section never reaches `releaseSealLock()`, and
     * DBLockingProvider only reaps expired rows from a separate cleanup job. The
     * lock therefore survives its holder for the remainder of its TTL — measured
     * here at 46 minutes after an interrupted upgrade.
     *
     * That is worse than an outage, because it is a SILENT one. Every sweep in
     * that window returns 0, which is also the value meaning "nothing to seal",
     * so a dead sweeper and an idle one are indistinguishable from the outside
     * while the backlog grows.
     *
     * Hence our own timestamp. A live holder refreshes it on each acquisition,
     * so a timestamp older than STALE_SEAL_LOCK_SECONDS means no living process
     * is inside the section — a real seal pass is bounded by
     * MAX_SWEEP_RECHAIN rows and cannot run that long. Breaking the lock is then
     * safe, and is announced at warning level because "something took a lock and
     * died" is worth seeing even when recovered from.
     *
     * @return bool True when the stale lock was broken and acquired.
     *
     * @spec openspec/specs/audit-hash-chain/spec.md
     */
    private function breakStaleSealLock(): bool
    {
        $since = $this->appConfig->getValueInt('openregister', self::SEAL_LOCK_SINCE_KEY, 0);
        if ($since === 0 || (time() - $since) < self::STALE_SEAL_LOCK_SECONDS) {
            return false;
        }

        $this->logger->warning(
            message: sprintf(
                '[AuditHashService] Seal lock has been held for %d seconds, longer than any real seal pass '
                .'can run. Treating it as abandoned by a process that died inside its critical section, '
                .'and breaking it so sealing resumes.',
                (time() - $since)
            ),
            context: ['file' => __FILE__, 'line' => __LINE__, 'app' => 'openregister']
        );

        try {
            $this->lockingProvider->releaseLock(self::SEAL_LOCK_KEY, ILockingProvider::LOCK_EXCLUSIVE);
            $this->lockingProvider->acquireLock(self::SEAL_LOCK_KEY, ILockingProvider::LOCK_EXCLUSIVE);
        } catch (\Throwable $e) {
            $this->logger->error(
                message: '[AuditHashService] Could not break the stale seal lock: '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__, 'app' => 'openregister']
            );

            return false;
        }

        $this->holdsSealLock = true;
        $this->appConfig->setValueInt('openregister', self::SEAL_LOCK_SINCE_KEY, time());

        return true;
    }//end breakStaleSealLock()

    /**
     * Release the exclusive seal lock acquired by {@see acquireSealLock()}.
     *
     * @return void
     */
    private function releaseSealLock(): void
    {
        // Ownership is cleared FIRST, and unconditionally.
        //
        // Both callers release from a `finally`, so this runs even when sealing
        // threw. If the flag were cleared only after a successful
        // releaseLock(), a throwing release would leave it stuck true and every
        // subsequent seal in this process would refuse instantly — turning a
        // performance guard into a silent stop-sealing switch.
        $this->holdsSealLock = false;
        $this->appConfig->setValueInt('openregister', self::SEAL_LOCK_SINCE_KEY, 0);

        $this->lockingProvider->releaseLock(self::SEAL_LOCK_KEY, ILockingProvider::LOCK_EXCLUSIVE);
    }//end releaseSealLock()

    /**
     * Verify the integrity of the hash chain.
     *
     * Iterates audit trail entries in order and validates that each entry's
     * stored hash matches the recomputed hash.
     *
     * ## Retention tombstones
     *
     * A row purged under a retention policy keeps its `id`, `created`, `hash`
     * and `previous_hash` and is stamped with `purged_at`; its payload is
     * blanked (see {@see \OCA\OpenRegister\Db\AuditTrailMapper::clearLogs()}).
     * Its content therefore no longer re-hashes to the stored value, but the
     * stored value is still the link the NEXT row committed to — so the chain
     * is carried forward across it and the row is counted as a declared
     * tombstone rather than reported as a break.
     *
     * This is what makes a lawful purge distinguishable from tampering. Before
     * tombstoning, a purge physically removed the row and verification failed
     * at the row AFTER it, producing exactly the symptom an attacker would
     * (or#2265): the audit trail could not tell the two apart.
     *
     * ⚠️ RESIDUAL LIMITATION, stated plainly: `purgedAt` is deliberately not
     * part of the canonical JSON, because adding any key to it would change
     * the hash of every row ever written and invalidate the whole existing
     * chain. So the flag itself is not hash-protected: an attacker with direct
     * table write access could set `purged_at` and blank a row's payload to
     * suppress a record without breaking verification. That is strictly
     * weaker and strictly more VISIBLE than the previous situation — the row,
     * its timestamp and its position all remain, and `purgedTombstones` is
     * reported and countable — but it is not cryptographic proof that a
     * tombstone was lawful. Binding the flag into the hash requires a chain
     * re-seal and is tracked separately.
     *
     * @param int|null $from Start entry ID (inclusive), null for beginning
     * @param int|null $to   End entry ID (inclusive), null for end
     *
     * @return array{
     *     valid: bool,
     *     entriesVerified: int,
     *     brokenAt: int|null,
     *     skippedNullHashes: int,
     *     purgedTombstones: int,
     *     range?: array{from: int, to: int}
     * }
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    public function verifyChain(?int $from=null, ?int $to=null): array
    {
        $entriesVerified   = 0;
        $skippedNullHashes = 0;
        $purgedTombstones  = 0;
        $previousHash      = null;

        // If starting from a specific ID, get the previous entry's hash.
        if ($from !== null) {
            $previousHash = $this->getHashBefore(id: $from);
        }

        // Walk in windows rather than one unbounded query. The DB is not the
        // constraint here — Postgres returns the whole trail by index scan in
        // ~350 ms — but the CLIENT is: libpq buffers an entire result set before
        // PHP sees the first row, so `select *` over 309,090 rows at ~5.8 KB
        // wide pulls ~1.8 GB into the driver. That memory is invisible to
        // memory_get_peak_usage() because it is held in C, so the failure mode
        // is not a clean PHP fatal but the OS killing the process — measured
        // here as an occ run dying with SIGKILL while PHP still reported a 57 MB
        // peak. Windowing keeps the driver's buffer bounded and, incidentally,
        // cuts a partial walk from ~129 s to under a second.
        $afterId = 0;
        if ($from !== null) {
            $afterId = ($from - 1);
        }

        while (true) {
            $rows = $this->readChainWindow(afterId: $afterId, to: $to);

            if (empty($rows) === true) {
                break;
            }

            foreach ($rows as $row) {
                $afterId    = (int) $row['id'];
                $storedHash = ($row['hash'] ?? null);

                // Skip entries without hashes (pre-migration entries).
                if ($storedHash === null || $storedHash === '') {
                    $skippedNullHashes++;
                    continue;
                }

                // A retention tombstone: the payload was lawfully destroyed, so
                // it cannot re-hash, but its stored hash is still the link the
                // next row committed to. Carry it forward and count it.
                // Reporting this as a break would recreate the exact ambiguity
                // the tombstone exists to remove.
                if (($row['purged_at'] ?? null) !== null) {
                    $purgedTombstones++;
                    $previousHash = $storedHash;
                    continue;
                }

                $entry = new AuditTrail();
                $entry->hydrate(object: $this->mapRowToEntity(row: $row));

                // Determine the previous hash for verification.
                if ($previousHash === null) {
                    $previousHash = $this->getGenesisHash();
                }

                $computedHash = $this->computeHash(entry: $entry, previousHash: $previousHash);

                if ($computedHash !== $storedHash) {
                    return $this->buildChainReport(
                        brokenAt: (int) $row['id'],
                        entriesVerified: $entriesVerified,
                        skippedNullHashes: $skippedNullHashes,
                        purgedTombstones: $purgedTombstones,
                        from: $from,
                        to: $to
                    );
                }

                $previousHash = $storedHash;
                $entriesVerified++;
            }//end foreach
        }//end while

        return $this->buildChainReport(
            brokenAt: null,
            entriesVerified: $entriesVerified,
            skippedNullHashes: $skippedNullHashes,
            purgedTombstones: $purgedTombstones,
            from: $from,
            to: $to
        );
    }//end verifyChain()

    /**
     * Read one window of the chain, in id order, after the given id.
     *
     * Split out of {@see verifyChain()} so the walk reads as the verification
     * rule rather than as query construction. See verifyChain() for why the walk
     * is windowed at all: the driver buffers a whole result set in C, so one
     * unbounded query gets the process OS-killed on a real trail.
     *
     * @param int      $afterId Exclusive lower bound on `id`.
     * @param int|null $to      Inclusive upper bound on `id`, or null for none.
     *
     * @return array<int, array<string, mixed>> The window's rows, oldest first.
     *
     * @spec openspec/specs/audit-hash-chain/spec.md
     */
    private function readChainWindow(int $afterId, ?int $to): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('openregister_audit_trails')
            ->where($qb->expr()->gt('id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
            ->orderBy('id', 'ASC')
            ->setMaxResults(self::SWEEP_BATCH_SIZE);

        if ($to !== null) {
            $qb->andWhere(
                $qb->expr()->lte('id', $qb->createNamedParameter($to, IQueryBuilder::PARAM_INT))
            );
        }

        $result = $qb->executeQuery();
        $rows   = $result->fetchAll();
        $result->closeCursor();

        return $rows;

    }//end readChainWindow()

    /**
     * Assemble the verifyChain() result.
     *
     * Both exits of the walk return the same shape, differing only in whether
     * `brokenAt` is set, so building it in one place keeps them from drifting
     * apart — which for a tamper-evidence report would mean the "valid" and
     * "broken" answers disagreeing about what they counted.
     *
     * @param int|null $brokenAt          Row id where verification failed, or null when the range is intact.
     * @param int      $entriesVerified   Rows whose content re-hashed correctly.
     * @param int      $skippedNullHashes Rows carrying no hash (pre-migration or fail-soft leftovers).
     * @param int      $purgedTombstones  Rows lawfully purged under a retention policy.
     * @param int|null $from              Requested range start, or null.
     * @param int|null $to                Requested range end, or null.
     *
     * @return array{
     *     valid: bool,
     *     entriesVerified: int,
     *     brokenAt: int|null,
     *     skippedNullHashes: int,
     *     purgedTombstones: int,
     *     range?: array{from: int|null, to: int|null}
     * }
     */
    private function buildChainReport(
        ?int $brokenAt,
        int $entriesVerified,
        int $skippedNullHashes,
        int $purgedTombstones,
        ?int $from,
        ?int $to
    ): array {
        $response = [
            'valid'             => ($brokenAt === null),
            'entriesVerified'   => $entriesVerified,
            'brokenAt'          => $brokenAt,
            'skippedNullHashes' => $skippedNullHashes,
            'purgedTombstones'  => $purgedTombstones,
        ];

        if ($from !== null || $to !== null) {
            $response['range'] = [
                'from' => ($from ?? $brokenAt),
                'to'   => ($to ?? $brokenAt),
            ];
        }

        return $response;
    }//end buildChainReport()

    /**
     * Get the hash of the nearest SEALED entry before the given ID.
     *
     * Unsealed rows (hash NULL/empty — fail-soft leftovers or
     * pre-migration entries) are skipped, exactly mirroring how
     * {@see verifyChain()} walks the chain: it skips null-hash rows and
     * carries the last SEALED hash forward. Chaining a new seal from the
     * immediately-prior row instead would fall back to genesis whenever
     * that row happens to be unsealed, permanently breaking verification
     * at that link.
     *
     * @param int $id The entry ID
     *
     * @return string|null The hash of the nearest prior sealed entry, or null if none
     *
     * @spec openspec/specs/audit-hash-chain/spec.md
     */
    private function getHashBefore(int $id): ?string
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('hash')
            ->from('openregister_audit_trails')
            ->where(
                $qb->expr()->lt('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
            )
            ->andWhere($qb->expr()->isNotNull('hash'))
            ->andWhere(
                $qb->expr()->neq('hash', $qb->createNamedParameter(''))
            )
            ->orderBy('id', 'DESC')
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        if ($row === false || $row['hash'] === null || $row['hash'] === '') {
            return null;
        }

        return $row['hash'];
    }//end getHashBefore()

    /**
     * Map a database row to entity-compatible array with camelCase keys.
     *
     * @param array $row The database row
     *
     * @return array The mapped array with camelCase keys
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    private function mapRowToEntity(array $row): array
    {
        $mapped = [];
        foreach ($row as $key => $value) {
            // Convert snake_case to camelCase.
            $camelKey          = lcfirst(
                str_replace('_', '', ucwords($key, '_'))
            );
            $mapped[$camelKey] = $value;
        }

        return $mapped;
    }//end mapRowToEntity()
}//end class
