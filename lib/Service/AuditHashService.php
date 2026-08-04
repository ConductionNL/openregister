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
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from('openregister_audit_trails')
            ->orderBy('id', 'ASC');

        if ($from !== null) {
            $qb->andWhere(
                $qb->expr()->gte('id', $qb->createNamedParameter($from, IQueryBuilder::PARAM_INT))
            );
        }

        if ($to !== null) {
            $qb->andWhere(
                $qb->expr()->lte('id', $qb->createNamedParameter($to, IQueryBuilder::PARAM_INT))
            );
        }

        $result = $qb->executeQuery();

        $entriesVerified   = 0;
        $skippedNullHashes = 0;
        $purgedTombstones  = 0;
        $previousHash      = null;

        // If starting from a specific ID, get the previous entry's hash.
        if ($from !== null) {
            $previousHash = $this->getHashBefore(id: $from);
        }

        while (($row = $result->fetch()) !== false) {
            $storedHash = $row['hash'] ?? null;

            // Skip entries without hashes (pre-migration entries).
            if ($storedHash === null || $storedHash === '') {
                $skippedNullHashes++;
                continue;
            }

            // A retention tombstone: the payload was lawfully destroyed, so it
            // cannot re-hash, but its stored hash is still the link the next
            // row committed to. Carry it forward and count it. Reporting this
            // as a break would recreate the exact ambiguity the tombstone
            // exists to remove.
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
                $result->closeCursor();

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
        }//end while

        $result->closeCursor();

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
