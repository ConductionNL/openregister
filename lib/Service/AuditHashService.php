<?php

/**
 * Service for cryptographic hash chaining on audit trail entries.
 *
 * Provides SHA-256 hash computation, chain verification, and genesis hash management
 * for the immutable audit trail system.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-7
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-8
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-11
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-10
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-9
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-14
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-13
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
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-8
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-10
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
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-8
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-9
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
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-8
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-11
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
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-8
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-13
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
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-8
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-14
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
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-8
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
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-7
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
