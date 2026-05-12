<?php

/**
 * Unit tests for TenantKeyService.
 *
 * Covers:
 *  - Fresh-tenant bootstrap: first call inserts a new active key and returns it.
 *  - Idempotency: repeated calls for the same tenant return the same plaintext key.
 *  - Rotation: rotateTenantKey() returns old + new keys and new key differs from old.
 *  - Rotation from scratch: rotateTenantKey() on a tenant with no prior key still works.
 *  - Retired rows are not returned by getCurrentTenantKey() after rotation.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
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

namespace Unit\Service;

use OCA\OpenRegister\Service\TenantKeyService;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for TenantKeyService.
 *
 * The DB is simulated by an in-memory array ($store) that the fluent
 * IQueryBuilder mock writes to / reads from.  The mock intercepts the
 * tenantId parameter via createNamedParameter so SELECT can be filtered
 * by tenant correctly.
 *
 * @psalm-suppress MissingConstructor
 */
class TenantKeyServiceTest extends TestCase
{

    /**
     * @var ICrypto&MockObject
     */
    private ICrypto $crypto;

    /**
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    /**
     * In-memory store simulating the openregister_tenant_keys table.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $store = [];

    /**
     * @var integer Auto-increment counter for row IDs.
     */
    private int $nextId = 1;

    /**
     * Most-recently seen tenant_id passed to createNamedParameter.
     * Used by the SELECT mock to filter rows.
     *
     * @var string
     */
    private string $lastTenantId = '';

    // -------------------------------------------------------------------------
    // setUp helpers
    // -------------------------------------------------------------------------

    /**
     * Build a fresh TenantKeyService backed by the in-memory store mock.
     *
     * @return TenantKeyService
     */
    private function makeService(): TenantKeyService
    {
        $this->store        = [];
        $this->nextId       = 1;
        $this->lastTenantId = '';

        $this->crypto = $this->createMock(ICrypto::class);
        $this->crypto->method('encrypt')->willReturnCallback(
            fn(string $plain): string => 'ENC:'.$plain
        );
        $this->crypto->method('decrypt')->willReturnCallback(
            fn(string $cipher): string => str_starts_with($cipher, 'ENC:') ? substr($cipher, 4) : ''
        );

        $this->logger = $this->createMock(LoggerInterface::class);

        $db = $this->buildDb();

        return new TenantKeyService(
            db: $db,
            crypto: $this->crypto,
            logger: $this->logger
        );
    }//end makeService()

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /**
     * On first call for a fresh tenant, a 64-char hex key is bootstrapped.
     */
    public function testBootstrapCreatesKeyOnFirstCall(): void
    {
        $svc = $this->makeService();
        $key = $svc->getCurrentTenantKey('tenant-abc');

        $this->assertNotEmpty($key, 'Key must not be empty');
        $this->assertSame(64, strlen($key), 'Key must be 64 hex chars (256 bits)');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key, 'Key must be lowercase hex');
    }//end testBootstrapCreatesKeyOnFirstCall()

    /**
     * After bootstrap, exactly one active row exists in the store.
     */
    public function testBootstrapInsertsExactlyOneActiveRow(): void
    {
        $svc = $this->makeService();
        $svc->getCurrentTenantKey('tenant-abc');

        $active = array_filter(
            $this->store,
            fn($row) => $row['tenant_id'] === 'tenant-abc' && $row['status'] === 'active'
        );

        $this->assertCount(1, $active, 'Exactly one active row must be inserted on bootstrap');
    }//end testBootstrapInsertsExactlyOneActiveRow()

    /**
     * Repeated calls return the same plaintext key (no extra rows inserted).
     */
    public function testRepeatedCallsReturnSameKey(): void
    {
        $svc    = $this->makeService();
        $first  = $svc->getCurrentTenantKey('tenant-xyz');
        $second = $svc->getCurrentTenantKey('tenant-xyz');

        $this->assertSame($first, $second, 'Repeated calls must return the same key');

        $allRows = array_filter($this->store, fn($row) => $row['tenant_id'] === 'tenant-xyz');
        $this->assertCount(1, $allRows, 'Only one row must exist after repeated reads');
    }//end testRepeatedCallsReturnSameKey()

    /**
     * Different tenants each get a valid key (both are 64-char hex).
     */
    public function testDifferentTenantsReceiveValidKeys(): void
    {
        $svc  = $this->makeService();
        $keyA = $svc->getCurrentTenantKey('tenant-a');
        $keyB = $svc->getCurrentTenantKey('tenant-b');

        $this->assertSame(64, strlen($keyA));
        $this->assertSame(64, strlen($keyB));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $keyA);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $keyB);
    }//end testDifferentTenantsReceiveValidKeys()

    /**
     * Rotation on a bootstrapped tenant returns old key, new key, and timestamp.
     * The new key is a valid hex string and differs from the old.
     */
    public function testRotationReturnsBothKeysAndTimestamp(): void
    {
        $svc      = $this->makeService();
        $original = $svc->getCurrentTenantKey('tenant-rot');

        $result = $svc->rotateTenantKey('tenant-rot');

        $this->assertArrayHasKey('old', $result);
        $this->assertArrayHasKey('new', $result);
        $this->assertArrayHasKey('rotated_at', $result);
        $this->assertSame($original, $result['old'], 'old must equal the pre-rotation active key');
        $this->assertNotEmpty($result['new'], 'new key must not be empty');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result['new']);
        $this->assertNotSame($result['old'], $result['new'], 'New key must differ from old key');
        $this->assertNotEmpty($result['rotated_at']);
    }//end testRotationReturnsBothKeysAndTimestamp()

    /**
     * After rotation, getCurrentTenantKey returns the new key, not the old one.
     */
    public function testCurrentKeyAfterRotationIsNewKey(): void
    {
        $svc = $this->makeService();
        $svc->getCurrentTenantKey('tenant-rot2');
        $result  = $svc->rotateTenantKey('tenant-rot2');
        $current = $svc->getCurrentTenantKey('tenant-rot2');

        $this->assertSame($result['new'], $current, 'getCurrentTenantKey must return new key after rotation');
    }//end testCurrentKeyAfterRotationIsNewKey()

    /**
     * After rotation the old row is retired (status = 'retired'), not deleted.
     */
    public function testRotationRetiresPreviousRow(): void
    {
        $svc = $this->makeService();
        $svc->getCurrentTenantKey('tenant-retire');
        $svc->rotateTenantKey('tenant-retire');

        $retired = array_filter(
            $this->store,
            fn($row) => $row['tenant_id'] === 'tenant-retire' && $row['status'] === 'retired'
        );

        $this->assertCount(1, $retired, 'Previous row must be retired, not deleted');
    }//end testRotationRetiresPreviousRow()

    /**
     * Rotation on a tenant with no prior key still succeeds.
     * old is empty string, new is a valid 64-char hex key.
     */
    public function testRotationWithNoPriorKeySucceeds(): void
    {
        $svc    = $this->makeService();
        $result = $svc->rotateTenantKey('brand-new-tenant');

        $this->assertSame('', $result['old'], 'old must be empty string when no prior key exists');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result['new']);
    }//end testRotationWithNoPriorKeySucceeds()

    /**
     * After rotation the store has exactly one active row and one retired row.
     */
    public function testStoreStateAfterRotation(): void
    {
        $svc = $this->makeService();
        $svc->getCurrentTenantKey('tenant-state');
        $svc->rotateTenantKey('tenant-state');

        $tenantRows = array_values(
            array_filter($this->store, fn($row) => $row['tenant_id'] === 'tenant-state')
        );

        $this->assertCount(2, $tenantRows, 'Two rows must exist: one retired, one active');

        $statuses = array_column($tenantRows, 'status');
        $this->assertContains('active', $statuses);
        $this->assertContains('retired', $statuses);
    }//end testStoreStateAfterRotation()

    /**
     * Encrypted keys are stored with the ENC: prefix (simulating ICrypto).
     */
    public function testEncryptedKeyIsStoredEncrypted(): void
    {
        $svc = $this->makeService();
        $svc->getCurrentTenantKey('tenant-enc');

        $rows = array_values(
            array_filter($this->store, fn($row) => $row['tenant_id'] === 'tenant-enc')
        );

        $this->assertCount(1, $rows);
        $this->assertStringStartsWith('ENC:', $rows[0]['encrypted_key'], 'Key must be stored encrypted');
    }//end testEncryptedKeyIsStoredEncrypted()

    /**
     * The returned key is the decrypted plaintext, not the ciphertext.
     */
    public function testReturnedKeyIsPlaintext(): void
    {
        $svc = $this->makeService();
        $key = $svc->getCurrentTenantKey('tenant-pt');

        $this->assertStringNotContainsString('ENC:', $key, 'Returned key must be plaintext, not ciphertext');
    }//end testReturnedKeyIsPlaintext()

    // -------------------------------------------------------------------------
    // Mock builder
    // -------------------------------------------------------------------------

    /**
     * Build the IDBConnection mock backed by $this->store.
     *
     * @return IDBConnection&MockObject
     */
    private function buildDb(): IDBConnection&MockObject
    {
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturnCallback(
            fn() => $this->buildQb()
        );

        return $db;
    }//end buildDb()

    /**
     * Build one IQueryBuilder mock that talks to $this->store.
     *
     * The builder tracks: op type (insert|update), values array, and set columns.
     * createNamedParameter records the tenant_id (first non-status, non-ENC string < 100 chars
     * that doesn't look like an ISO timestamp) into $this->lastTenantId.
     *
     * @return IQueryBuilder&MockObject
     */
    private function buildQb(): IQueryBuilder&MockObject
    {
        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('eq')->willReturn('1=1');

        $qb = $this->createMock(IQueryBuilder::class);

        // Mutable op-context captured by closures.
        $ctx = ['type' => null, 'values' => [], 'set' => []];

        $qb->method('select')->willReturn($qb);
        $qb->method('from')->willReturn($qb);
        $qb->method('where')->willReturn($qb);
        $qb->method('andWhere')->willReturn($qb);
        $qb->method('orderBy')->willReturn($qb);
        $qb->method('setMaxResults')->willReturn($qb);
        $qb->method('expr')->willReturn($expr);

        $qb->method('insert')->willReturnCallback(
                function (string $table) use ($qb, &$ctx) {
                    $ctx['type'] = 'insert';
                    return $qb;
                }
                );

        $qb->method('update')->willReturnCallback(
                function (string $table) use ($qb, &$ctx) {
                    $ctx['type'] = 'update';
                    return $qb;
                }
                );

        $qb->method('values')->willReturnCallback(
                function (array $values) use ($qb, &$ctx) {
                    $ctx['values'] = $values;
                    return $qb;
                }
                );

        $qb->method('set')->willReturnCallback(
                function (string $col, mixed $val) use ($qb, &$ctx) {
                    $ctx['set'][$col] = $val;
                    return $qb;
                }
                );

        // CreateNamedParameter: return the plain value and track tenant ID.
        // Sniff the tenant_id: anything that looks like our tenant UUID/string
        // (not 'active'/'retired', not 'ENC:*', not an ISO date, shorter than 100 chars).
        $knownStatuses = ['active', 'retired'];
        $qb->method('createNamedParameter')->willReturnCallback(
            function (mixed $value) use ($knownStatuses) {
                if (is_string($value) === true
                    && strlen($value) < 100
                    && in_array($value, $knownStatuses, true) === false
                    && str_starts_with($value, 'ENC:') === false
                    && preg_match('/^\d{4}-/', $value) === 0
                ) {
                    $this->lastTenantId = $value;
                }

                return $value;
            }
        );

        // ExecuteQuery: SELECT returns matching active rows for lastTenantId.
        $qb->method('executeQuery')->willReturnCallback(
            function () {
                $tid      = $this->lastTenantId;
                $matching = array_values(
                    array_filter(
                        $this->store,
                        fn($row) => $row['tenant_id'] === $tid && $row['status'] === 'active'
                    )
                );
                // Sort by id DESC (service picks setMaxResults(1) = most-recent active row).
                usort($matching, fn($a, $b) => $b['id'] <=> $a['id']);
                $remaining = $matching;

                $result = $this->createMock(\OCP\DB\IResult::class);
                $result->method('fetch')->willReturnCallback(
                    function () use (&$remaining) {
                        return (empty($remaining) === true) ? false : array_shift($remaining);
                    }
                );
                $result->method('closeCursor')->willReturn(true);

                return $result;
            }
        );

        // ExecuteStatement: INSERT or UPDATE against $this->store.
        $qb->method('executeStatement')->willReturnCallback(
            function () use (&$ctx) {
                if ($ctx['type'] === 'insert') {
                    $id = $this->nextId++;
                    $this->store[$id] = [
                        'id'            => $id,
                        'tenant_id'     => $ctx['values']['tenant_id'],
                        'encrypted_key' => $ctx['values']['encrypted_key'],
                        'status'        => $ctx['values']['status'],
                        'created_at'    => $ctx['values']['created_at'],
                    ];
                } else if ($ctx['type'] === 'update') {
                    // Retire all active rows for the current tenant.
                    $tid = $this->lastTenantId;
                    foreach ($this->store as $id => $row) {
                        if ($row['tenant_id'] === $tid && $row['status'] === 'active') {
                            $this->store[$id]['status'] = $ctx['set']['status'] ?? 'retired';
                        }
                    }
                }

                return 1;
            }
        );

        return $qb;
    }//end buildQb()
}//end class
