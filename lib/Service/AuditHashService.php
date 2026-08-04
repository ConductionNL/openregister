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
 * Complexity is over phpmd's threshold (49 on development; the sweep pass and
 * its backlog counter take it to 53). Suppressed rather than restructured: the
 * concerns here — canonical hashing, chain verification, the seal lock and now
 * the sweep — are one cohesive job, and splitting an audit-INTEGRITY class
 * risks the property it exists to guarantee for no behavioural gain. If it
 * grows further it wants decomposing properly, not a wider threshold.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
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
     * Constructor for AuditHashService.
     *
     * @param IDBConnection    $db              The database connection
     * @param ILockingProvider $lockingProvider Advisory lock provider serializing seal passes
     * @param LoggerInterface  $logger          Logger for fail-soft lock warnings
     */
    public function __construct(
        private readonly IDBConnection $db,
        private readonly ILockingProvider $lockingProvider,
        private readonly LoggerInterface $logger
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

        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from('openregister_audit_trails')
            ->where($qb->expr()->isNull('hash'))
            ->orderBy('id', 'ASC')
            ->setMaxResults($limit);

        $result = $qb->executeQuery();
        $ids    = array_column($result->fetchAll(), 'id');
        $result->closeCursor();

        if ($ids === []) {
            return 0;
        }

        return $this->sealRows(ids: $ids);

    }//end sealUnsealed()

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
        $qb->select($qb->func()->max('id', 'last_sealed'))
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
     * Re-chains EVERY row, including rows that never had a hash, so a single
     * run repairs both failure modes at once: the gaps the sweeper exists to
     * close, and the mis-chained rows it cannot touch.
     *
     * @param int $batchSize Rows re-chained per query window.
     *
     * @return array{rechained: int} The number of rows rewritten.
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

            return ['rechained' => 0];
        }

        try {
            $previousHash = $this->getGenesisHash();
            $rechained    = 0;
            $afterId      = 0;

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

                foreach ($rows as $row) {
                    $afterId = (int) $row['id'];

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
                                $update->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)
                            )
                        );
                    $update->executeStatement();

                    // Every row becomes the predecessor of the next, which is
                    // what makes the result one chain rather than a fan-out.
                    $previousHash = $hash;
                    $rechained++;
                }//end foreach
            }//end while

            $this->logger->warning(
                message: sprintf(
                    '[AuditHashService] FULL RE-CHAIN COMPLETE — %d audit row(s) re-chained from genesis.',
                    $rechained
                ),
                context: ['file' => __FILE__, 'line' => __LINE__, 'app' => 'openregister']
            );

            return ['rechained' => $rechained];
        } finally {
            $this->releaseSealLock();
        }//end try

    }//end rechainAll()

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
        for ($attempt = 1; $attempt <= self::SEAL_LOCK_ATTEMPTS; $attempt++) {
            try {
                $this->lockingProvider->acquireLock(self::SEAL_LOCK_KEY, ILockingProvider::LOCK_EXCLUSIVE);

                return true;
            } catch (LockedException) {
                if ($attempt < self::SEAL_LOCK_ATTEMPTS) {
                    usleep(self::SEAL_LOCK_RETRY_DELAY_USEC);
                }
            }
        }

        return false;
    }//end acquireSealLock()

    /**
     * Release the exclusive seal lock acquired by {@see acquireSealLock()}.
     *
     * @return void
     */
    private function releaseSealLock(): void
    {
        $this->lockingProvider->releaseLock(self::SEAL_LOCK_KEY, ILockingProvider::LOCK_EXCLUSIVE);
    }//end releaseSealLock()

    /**
     * Verify the integrity of the hash chain.
     *
     * Iterates audit trail entries in order and validates that each entry's
     * stored hash matches the recomputed hash.
     *
     * @param int|null $from Start entry ID (inclusive), null for beginning
     * @param int|null $to   End entry ID (inclusive), null for end
     *
     * @return array{
     *     valid: bool,
     *     entriesVerified: int,
     *     brokenAt: int|null,
     *     skippedNullHashes: int,
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
        $afterId = ($from - 1);
        if ($from === null) {
            $afterId = 0;
        }

        while (true) {
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

                $entry = new AuditTrail();
                $entry->hydrate(object: $this->mapRowToEntity(row: $row));

                // Determine the previous hash for verification.
                if ($previousHash === null) {
                    $previousHash = $this->getGenesisHash();
                }

                $computedHash = $this->computeHash(entry: $entry, previousHash: $previousHash);

                if ($computedHash !== $storedHash) {
                    $response = [
                        'valid'             => false,
                        'entriesVerified'   => $entriesVerified,
                        'brokenAt'          => (int) $row['id'],
                        'skippedNullHashes' => $skippedNullHashes,
                    ];

                    if ($from !== null || $to !== null) {
                        $response['range'] = [
                            'from' => ($from ?? (int) $row['id']),
                            'to'   => ($to ?? (int) $row['id']),
                        ];
                    }

                    return $response;
                }

                $previousHash = $storedHash;
                $entriesVerified++;
            }//end foreach
        }//end while

        $response = [
            'valid'             => true,
            'entriesVerified'   => $entriesVerified,
            'brokenAt'          => null,
            'skippedNullHashes' => $skippedNullHashes,
        ];

        if ($from !== null || $to !== null) {
            $response['range'] = [
                'from' => $from,
                'to'   => $to,
            ];
        }

        return $response;
    }//end verifyChain()

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
