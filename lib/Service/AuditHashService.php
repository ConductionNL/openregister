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

/**
 * Handles cryptographic hash chaining for audit trail entries.
 *
 * @package OCA\OpenRegister\Service
 *
 * @psalm-suppress UnusedClass
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
     * Constructor for AuditHashService.
     *
     * @param IDBConnection $db The database connection
     */
    public function __construct(
        private readonly IDBConnection $db
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
     * Reads the row by id, derives `previousHash` from the immediately-prior
     * row (or genesis), and computes `hash` over the row using the SAME
     * `mapRowToEntity()` + `hydrate()` + `getCanonicalJson()` path that
     * {@see verifyChain()} uses — guaranteeing the stored hash re-verifies
     * exactly. The `hash` / `previous_hash` values are then written directly.
     * Call this AFTER inserting the row so the id and all persisted (DB-typed)
     * field values are final.
     *
     * @param int $id The audit-trail row id to seal.
     *
     * @return bool True when the row was found and sealed.
     */
    public function sealRow(int $id): bool
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

    }//end sealRow()

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
     * @param int[] $ids The audit-trail row ids to seal.
     *
     * @return int Number of rows sealed.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Chain walking requires sealed/unsealed branching
     * @SuppressWarnings(PHPMD.NPathComplexity)      Same — early-outs plus per-row sealed/unsealed paths
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
    }//end sealRows()

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

            $entry = new AuditTrail();
            $entry->hydrate(object: $this->mapRowToEntity(row: $row));

            // Determine the previous hash for verification.
            if ($previousHash === null) {
                $previousHash = $this->getGenesisHash();
            }

            $computedHash = $this->computeHash(entry: $entry, previousHash: $previousHash);

            if ($computedHash !== $storedHash) {
                $result->closeCursor();

                $response = [
                    'valid'             => false,
                    'entriesVerified'   => $entriesVerified,
                    'brokenAt'          => (int) $row['id'],
                    'skippedNullHashes' => $skippedNullHashes,
                ];

                if ($from !== null || $to !== null) {
                    $response['range'] = [
                        'from' => $from ?? (int) $row['id'],
                        'to'   => $to ?? (int) $row['id'],
                    ];
                }

                return $response;
            }

            $previousHash = $storedHash;
            $entriesVerified++;
        }//end while

        $result->closeCursor();

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
     * Get the hash of the entry immediately before the given ID.
     *
     * @param int $id The entry ID
     *
     * @return string|null The hash of the previous entry, or null if none
     *
     * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
     */
    private function getHashBefore(int $id): ?string
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('hash')
            ->from('openregister_audit_trails')
            ->where(
                $qb->expr()->lt('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
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
