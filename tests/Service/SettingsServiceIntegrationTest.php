<?php

/**
 * Integration tests for SettingsService
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use OCA\OpenRegister\Service\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for SettingsService
 *
 * Tests settings retrieval, formatting utilities, and configuration management
 * using the real Nextcloud DI container and database.
 */
class SettingsServiceIntegrationTest extends TestCase
{
    /**
     * The settings service instance
     *
     * @var SettingsService
     */
    private SettingsService $service;

    /**
     * Set up test fixtures
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = \OC::$server->get(SettingsService::class);
    }

    /**
     * Test getSearchBackendConfig returns valid config
     *
     * @return void
     */
    public function testGetSearchBackendConfig(): void
    {
        $config = $this->service->getSearchBackendConfig();

        $this->assertIsArray($config);
        $this->assertArrayHasKey('active', $config);
        $this->assertArrayHasKey('available', $config);
        $this->assertIsArray($config['available']);
    }

    /**
     * Test isMultiTenancyEnabled returns boolean
     *
     * @return void
     */
    public function testIsMultiTenancyEnabled(): void
    {
        $result = $this->service->isMultiTenancyEnabled();

        $this->assertIsBool($result);
    }

    /**
     * Test getDefaultOrganisationUuid returns string or null
     *
     * @return void
     */
    public function testGetDefaultOrganisationUuid(): void
    {
        $result = $this->service->getDefaultOrganisationUuid();

        $this->assertTrue($result === null || is_string($result));
    }

    /**
     * Test formatBytes with various inputs
     *
     * @return void
     */
    public function testFormatBytesZero(): void
    {
        $result = $this->service->formatBytes(0);
        $this->assertSame('0 B', $result);
    }

    /**
     * Test formatBytes with kilobytes
     *
     * @return void
     */
    public function testFormatBytesKilobytes(): void
    {
        $result = $this->service->formatBytes(2048);
        $this->assertSame('2 KB', $result);
    }

    /**
     * Test formatBytes with megabytes
     *
     * @return void
     */
    public function testFormatBytesMegabytes(): void
    {
        $result = $this->service->formatBytes(2097152);
        $this->assertSame('2 MB', $result);
    }

    /**
     * Test formatBytes with gigabytes
     *
     * @return void
     */
    public function testFormatBytesGigabytes(): void
    {
        $result = $this->service->formatBytes(2147483648);
        $this->assertSame('2 GB', $result);
    }

    /**
     * Test formatBytes with precision
     *
     * @return void
     */
    public function testFormatBytesWithPrecision(): void
    {
        $result = $this->service->formatBytes(1536, 1);
        $this->assertSame('1.5 KB', $result);
    }

    /**
     * Test convertToBytes with megabyte string
     *
     * @return void
     */
    public function testConvertToBytesM(): void
    {
        $result = $this->service->convertToBytes('128M');
        $this->assertSame(134217728, $result);
    }

    /**
     * Test convertToBytes with gigabyte string
     *
     * @return void
     */
    public function testConvertToBytesG(): void
    {
        $result = $this->service->convertToBytes('1G');
        $this->assertSame(1073741824, $result);
    }

    /**
     * Test convertToBytes with kilobyte string
     *
     * @return void
     */
    public function testConvertToBytesK(): void
    {
        $result = $this->service->convertToBytes('512K');
        $this->assertSame(524288, $result);
    }

    /**
     * Test convertToBytes with plain bytes
     *
     * @return void
     */
    public function testConvertToBytesPlain(): void
    {
        $result = $this->service->convertToBytes('1024');
        $this->assertSame(1024, $result);
    }

    /**
     * Test maskToken with long token
     *
     * @return void
     */
    public function testMaskTokenLong(): void
    {
        $token = 'abcdefghijklmnopqrstuvwxyz';
        $result = $this->service->maskToken($token);

        // First 4 chars visible
        $this->assertStringStartsWith('abcd', $result);
        // Last 4 chars visible
        $this->assertStringEndsWith('wxyz', $result);
        // Middle contains asterisks
        $this->assertStringContainsString('*', $result);
    }

    /**
     * Test maskToken with short token
     *
     * @return void
     */
    public function testMaskTokenShort(): void
    {
        $token = 'abcd';
        $result = $this->service->maskToken($token);

        // Short tokens are fully masked
        $this->assertSame('****', $result);
    }

    /**
     * Test maskToken with exactly 8 chars
     *
     * @return void
     */
    public function testMaskTokenExactly8(): void
    {
        $token = 'abcdefgh';
        $result = $this->service->maskToken($token);

        // 8 chars or fewer should be fully masked
        $this->assertSame('********', $result);
    }

    /**
     * Test getVersionInfoOnly returns version info
     *
     * @return void
     */
    public function testGetVersionInfoOnly(): void
    {
        $result = $this->service->getVersionInfoOnly();

        $this->assertIsArray($result);
    }

    /**
     * Test getDatabaseInfo returns database information
     *
     * @return void
     */
    public function testGetDatabaseInfo(): void
    {
        $result = $this->service->getDatabaseInfo();

        // Can return null if DB info unavailable, or an array
        $this->assertTrue($result === null || is_array($result));
    }

    /**
     * Test hasPostgresExtension returns boolean
     *
     * @return void
     */
    public function testHasPostgresExtension(): void
    {
        // This test should work regardless of whether PostgreSQL is in use
        try {
            $result = $this->service->hasPostgresExtension('vector');
            $this->assertIsBool($result);
        } catch (\Exception $e) {
            // If not using PostgreSQL, this may throw - that is acceptable
            $this->assertStringContainsString('pgsql', strtolower($e->getMessage()));
        }
    }

    /**
     * Test getStats returns statistics array
     *
     * @return void
     */
    public function testGetStats(): void
    {
        $result = $this->service->getStats();

        $this->assertIsArray($result);
    }

    /**
     * Test getRbacSettingsOnly returns RBAC settings
     *
     * @return void
     */
    public function testGetRbacSettingsOnly(): void
    {
        $result = $this->service->getRbacSettingsOnly();

        $this->assertIsArray($result);
    }

    /**
     * Test getOrganisationSettingsOnly returns organisation settings
     *
     * @return void
     */
    public function testGetOrganisationSettingsOnly(): void
    {
        $result = $this->service->getOrganisationSettingsOnly();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('organisation', $result);
    }

    /**
     * Test getMultitenancySettingsOnly returns multitenancy settings
     *
     * @return void
     */
    public function testGetMultitenancySettingsOnly(): void
    {
        $result = $this->service->getMultitenancySettingsOnly();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('multitenancy', $result);
    }

    /**
     * Test compareFields with matching fields
     *
     * @return void
     */
    public function testCompareFieldsMatching(): void
    {
        $actual = [
            'field1' => ['type' => 'string'],
            'field2' => ['type' => 'integer'],
        ];
        $expected = [
            'field1' => ['type' => 'string'],
            'field2' => ['type' => 'integer'],
        ];

        $result = $this->service->compareFields($actual, $expected);

        $this->assertIsArray($result);
    }

    /**
     * Test compareFields with missing fields
     *
     * @return void
     */
    public function testCompareFieldsMissing(): void
    {
        $actual = [
            'field1' => ['type' => 'string'],
        ];
        $expected = [
            'field1' => ['type' => 'string'],
            'field2' => ['type' => 'integer'],
        ];

        $result = $this->service->compareFields($actual, $expected);

        $this->assertIsArray($result);
    }

    /**
     * Test compareFields with empty inputs
     *
     * @return void
     */
    public function testCompareFieldsEmpty(): void
    {
        $result = $this->service->compareFields([], []);

        $this->assertIsArray($result);
    }
}
