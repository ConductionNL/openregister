<?php

/**
 * Tenant HMAC Key Service
 *
 * Manages per-tenant HMAC signing keys for the audit-trail hash-chain.
 * Each tenant has exactly one active 256-bit key at a time. On first
 * access a fresh key is generated, encrypted at rest via ICrypto, and
 * persisted. Annual rotation is driven by admin action or cron; this
 * method always returns the CURRENT active key.
 *
 * Security design:
 * - Keys are NEVER exposed through any REST endpoint.
 * - Storage is encrypted via OCP\Security\ICrypto (AES-256-GCM + HMAC
 *   with the instance secret as the wrapping key).
 * - This service is an internal server-side API only; it is not wired
 *   into any HTTP controller.
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
 * @spec openspec/changes/scholiq-deps/tenant-key-api/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Provides per-tenant HMAC keys for audit-trail evidence signing.
 *
 * @package OCA\OpenRegister\Service
 *
 * @psalm-suppress UnusedClass
 */
class TenantKeyService
{
    /**
     * Table that stores encrypted tenant keys.
     *
     * @var string
     */
    private const TABLE = 'openregister_tenant_keys';

    /**
     * Length of the random key material in bytes (256 bits).
     *
     * @var int
     */
    private const KEY_BYTES = 32;

    /**
     * Constructor.
     *
     * @param IDBConnection   $db           Database connection
     * @param ICrypto         $crypto       Nextcloud crypto service (encrypt/decrypt at rest)
     * @param ISecureRandom   $secureRandom Nextcloud CSPRNG
     * @param LoggerInterface $logger       PSR logger
     */
    public function __construct(
        private readonly IDBConnection $db,
        private readonly ICrypto $crypto,
        private readonly ISecureRandom $secureRandom,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Return the current active HMAC key for the given tenant.
     *
     * On the very first call for a tenant a fresh 256-bit key is generated,
     * encrypted, stored, and returned.  Subsequent calls return the same
     * plaintext key until a rotation is performed.
     *
     * @param string $tenantId Tenant identifier (e.g. organisation UUID)
     *
     * @return string Raw binary key material (32 bytes / 256 bit)
     *
     * @throws RuntimeException When key decryption fails
     *
     * @spec openspec/changes/scholiq-deps/tenant-key-api/tasks.md
     */
    public function getCurrentTenantKey(string $tenantId): string
    {
        $row = $this->fetchActiveRow(tenantId: $tenantId);

        if ($row === null) {
            return $this->bootstrapKey(tenantId: $tenantId);
        }

        return $this->decrypt(ciphertext: $row['encrypted_key'], tenantId: $tenantId);
    }//end getCurrentTenantKey()

    /**
     * Rotate the HMAC key for the given tenant.
     *
     * The old key is retained in the table (status = 'retired') so that
     * verifiers can still re-check evidence records signed under it.
     * A fresh key is inserted with status = 'active'.
     *
     * @param string $tenantId Tenant identifier
     *
     * @return array{
     *     old: string,
     *     new: string,
     *     rotated_at: string
     * } Old plaintext key, new plaintext key, and ISO-8601 rotation timestamp
     *
     * @throws RuntimeException When key decryption or encryption fails
     *
     * @spec openspec/changes/scholiq-deps/tenant-key-api/tasks.md
     */
    public function rotateTenantKey(string $tenantId): array
    {
        $oldRow = $this->fetchActiveRow(tenantId: $tenantId);
        $oldKey = null;

        if ($oldRow !== null) {
            $oldKey = $this->decrypt(ciphertext: $oldRow['encrypted_key'], tenantId: $tenantId);
            $this->retireRow(id: (int) $oldRow['id']);
        }

        $newKey    = $this->generateKey();
        $rotatedAt = (new DateTime())->format('c');
        $this->insertKey(tenantId: $tenantId, rawKey: $newKey, rotatedAt: $rotatedAt);

        $this->logger->info(
            'Rotated tenant HMAC key',
            ['tenant_id' => $tenantId, 'rotated_at' => $rotatedAt]
        );

        return [
            'old'        => $oldKey ?? '',
            'new'        => $newKey,
            'rotated_at' => $rotatedAt,
        ];
    }//end rotateTenantKey()

    /**
     * Fetch the active key row for a tenant, or null if none exists.
     *
     * @param string $tenantId Tenant identifier
     *
     * @return array<string,mixed>|null Database row or null
     */
    private function fetchActiveRow(string $tenantId): ?array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('id', 'encrypted_key')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq(
                    'tenant_id',
                    $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_STR)
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    'status',
                    $qb->createNamedParameter('active', IQueryBuilder::PARAM_STR)
                )
            )
            ->orderBy('id', 'DESC')
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        return ($row === false) ? null : $row;
    }//end fetchActiveRow()

    /**
     * Generate a fresh key, persist it, and return the plaintext key.
     *
     * @param string $tenantId Tenant identifier
     *
     * @return string Raw plaintext key material
     */
    private function bootstrapKey(string $tenantId): string
    {
        $rawKey   = $this->generateKey();
        $issuedAt = (new DateTime())->format('c');
        $this->insertKey(tenantId: $tenantId, rawKey: $rawKey, rotatedAt: $issuedAt);

        $this->logger->info(
            'Bootstrapped new tenant HMAC key',
            ['tenant_id' => $tenantId, 'issued_at' => $issuedAt]
        );

        return $rawKey;
    }//end bootstrapKey()

    /**
     * Generate 256 bits of cryptographically secure random key material.
     *
     * Returns the raw bytes encoded as a 64-character lowercase hex string
     * so the value is safely stored / transported as a string.
     *
     * @return string 64-character hex string (32 raw bytes)
     */
    private function generateKey(): string
    {
        // ISecureRandom::generate produces URL-safe chars; we need raw bytes.
        // Use random_bytes (CSPRNG) and encode as hex for safe string storage.
        return bin2hex(random_bytes(self::KEY_BYTES));
    }//end generateKey()

    /**
     * Encrypt and insert a new active key row.
     *
     * @param string $tenantId  Tenant identifier
     * @param string $rawKey    Plaintext key (64-char hex)
     * @param string $rotatedAt ISO-8601 timestamp
     *
     * @return void
     */
    private function insertKey(string $tenantId, string $rawKey, string $rotatedAt): void
    {
        $encrypted = $this->crypto->encrypt($rawKey);

        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE)
            ->values(
                    [
                        'tenant_id'     => $qb->createNamedParameter($tenantId, IQueryBuilder::PARAM_STR),
                        'encrypted_key' => $qb->createNamedParameter($encrypted, IQueryBuilder::PARAM_STR),
                        'status'        => $qb->createNamedParameter('active', IQueryBuilder::PARAM_STR),
                        'created_at'    => $qb->createNamedParameter($rotatedAt, IQueryBuilder::PARAM_STR),
                    ]
                    )
            ->executeStatement();
    }//end insertKey()

    /**
     * Mark a key row as retired.
     *
     * @param int $id Primary key of the row to retire
     *
     * @return void
     */
    private function retireRow(int $id): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE)
            ->set('status', $qb->createNamedParameter('retired', IQueryBuilder::PARAM_STR))
            ->where(
                $qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
            )
            ->executeStatement();
    }//end retireRow()

    /**
     * Decrypt a ciphertext produced by ICrypto::encrypt.
     *
     * @param string $ciphertext Encrypted key material
     * @param string $tenantId   Tenant identifier (used only for log context)
     *
     * @return string Plaintext key material
     *
     * @throws RuntimeException When decryption produces an empty result
     */
    private function decrypt(string $ciphertext, string $tenantId): string
    {
        $plaintext = $this->crypto->decrypt($ciphertext);

        if ($plaintext === '') {
            throw new RuntimeException(
                "TenantKeyService: decryption returned empty string for tenant '{$tenantId}'"
            );
        }

        return $plaintext;
    }//end decrypt()
}//end class
