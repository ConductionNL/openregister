<?php

declare(strict_types=1);

/**
 * ObjectRetentionHandler Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Settings
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Settings;

use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for ObjectRetentionHandler
 *
 * Tests object/vectorization settings retrieval and update.
 */
class ObjectRetentionHandlerTest extends TestCase
{
    /** @var ObjectRetentionHandler */
    private ObjectRetentionHandler $handler;

    /** @var IAppConfig&MockObject */
    private IAppConfig $appConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->handler = new ObjectRetentionHandler($this->appConfig);
    }

    /**
     * Test getObjectSettingsOnly returns defaults when no config stored
     */
    public function testGetObjectSettingsOnlyReturnsDefaults(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('');

        $result = $this->handler->getObjectSettingsOnly();

        $this->assertFalse($result['vectorizationEnabled']);
        $this->assertTrue($result['vectorizeOnCreate']);
        $this->assertFalse($result['vectorizeOnUpdate']);
        $this->assertTrue($result['vectorizeAllViews']);
        $this->assertSame([], $result['enabledViews']);
        $this->assertTrue($result['includeMetadata']);
        $this->assertTrue($result['includeRelations']);
        $this->assertSame(10, $result['maxNestingDepth']);
        $this->assertSame(25, $result['batchSize']);
        $this->assertTrue($result['autoRetry']);
    }

    /**
     * Test getObjectSettingsOnly parses stored JSON config
     */
    public function testGetObjectSettingsOnlyParsesStoredConfig(): void
    {
        $stored = json_encode([
            'vectorizationEnabled' => true,
            'vectorizeOnCreate'    => false,
            'batchSize'            => 50,
        ]);

        $this->appConfig->method('getValueString')
            ->willReturn($stored);

        $result = $this->handler->getObjectSettingsOnly();

        $this->assertTrue($result['vectorizationEnabled']);
        $this->assertFalse($result['vectorizeOnCreate']);
        $this->assertSame(50, $result['batchSize']);
        // Defaults for missing keys.
        $this->assertFalse($result['vectorizeOnUpdate']);
        $this->assertTrue($result['vectorizeAllViews']);
    }

    /**
     * Test updateObjectSettingsOnly stores and returns config
     */
    public function testUpdateObjectSettingsOnlyStoresConfig(): void
    {
        $this->appConfig->expects($this->once())
            ->method('setValueString')
            ->with('openregister', 'objectManagement', $this->isType('string'));

        $result = $this->handler->updateObjectSettingsOnly([
            'vectorizationEnabled' => true,
            'batchSize'            => 100,
        ]);

        $this->assertTrue($result['vectorizationEnabled']);
        $this->assertSame(100, $result['batchSize']);
        // Defaults for missing keys.
        $this->assertTrue($result['vectorizeOnCreate']);
    }

    /**
     * Test updateObjectSettingsOnly applies defaults for missing values
     */
    public function testUpdateObjectSettingsOnlyAppliesDefaults(): void
    {
        $this->appConfig->method('setValueString');

        $result = $this->handler->updateObjectSettingsOnly([]);

        $this->assertFalse($result['vectorizationEnabled']);
        $this->assertTrue($result['vectorizeOnCreate']);
        $this->assertSame(10, $result['maxNestingDepth']);
        $this->assertSame(25, $result['batchSize']);
    }

    /**
     * Test getObjectSettingsOnly throws RuntimeException on failure
     */
    public function testGetObjectSettingsOnlyThrowsOnFailure(): void
    {
        $this->appConfig->method('getValueString')
            ->willThrowException(new \Exception('DB error'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to get Object Management settings');

        $this->handler->getObjectSettingsOnly();
    }

    /**
     * Test updateObjectSettingsOnly throws RuntimeException on failure
     */
    public function testUpdateObjectSettingsOnlyThrowsOnFailure(): void
    {
        $this->appConfig->method('setValueString')
            ->willThrowException(new \Exception('DB error'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to update Object Management settings');

        $this->handler->updateObjectSettingsOnly(['vectorizationEnabled' => true]);
    }

    // ── Retention Settings ──

    public function testGetRetentionSettingsOnlyReturnsDefaults(): void
    {
        $this->appConfig->method('getValueString')
            ->willReturn('');

        $result = $this->handler->getRetentionSettingsOnly();

        $this->assertSame(31536000000, $result['objectArchiveRetention']);
        $this->assertSame(63072000000, $result['objectDeleteRetention']);
        $this->assertSame(2592000000, $result['searchTrailRetention']);
        $this->assertSame(2592000000, $result['createLogRetention']);
        $this->assertSame(86400000, $result['readLogRetention']);
        $this->assertSame(604800000, $result['updateLogRetention']);
        $this->assertSame(2592000000, $result['deleteLogRetention']);
        $this->assertTrue($result['auditTrailsEnabled']);
        $this->assertTrue($result['searchTrailsEnabled']);
    }

    public function testGetRetentionSettingsOnlyParsesStoredConfig(): void
    {
        $stored = json_encode([
            'objectArchiveRetention' => 999,
            'auditTrailsEnabled' => false,
            'searchTrailsEnabled' => 'yes',
        ]);

        $this->appConfig->method('getValueString')
            ->willReturn($stored);

        $result = $this->handler->getRetentionSettingsOnly();

        $this->assertSame(999, $result['objectArchiveRetention']);
        $this->assertFalse($result['auditTrailsEnabled']);
        $this->assertTrue($result['searchTrailsEnabled']);
        // Defaults for missing keys
        $this->assertSame(63072000000, $result['objectDeleteRetention']);
    }

    public function testGetRetentionSettingsOnlyThrowsOnFailure(): void
    {
        $this->appConfig->method('getValueString')
            ->willThrowException(new \Exception('DB error'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to retrieve Retention settings');

        $this->handler->getRetentionSettingsOnly();
    }

    public function testUpdateRetentionSettingsOnlyStoresConfig(): void
    {
        $this->appConfig->expects($this->once())
            ->method('setValueString')
            ->with('openregister', 'retention', $this->isType('string'));

        $result = $this->handler->updateRetentionSettingsOnly([
            'objectArchiveRetention' => 500,
            'auditTrailsEnabled' => true,
        ]);

        $this->assertSame(500, $result['objectArchiveRetention']);
        $this->assertTrue($result['auditTrailsEnabled']);
        // Defaults for missing keys
        $this->assertSame(63072000000, $result['objectDeleteRetention']);
    }

    public function testUpdateRetentionSettingsOnlyAppliesDefaults(): void
    {
        $this->appConfig->method('setValueString');

        $result = $this->handler->updateRetentionSettingsOnly([]);

        $this->assertSame(31536000000, $result['objectArchiveRetention']);
        $this->assertTrue($result['auditTrailsEnabled']);
    }

    public function testUpdateRetentionSettingsOnlyThrowsOnFailure(): void
    {
        $this->appConfig->method('setValueString')
            ->willThrowException(new \Exception('DB error'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to update Retention settings');

        $this->handler->updateRetentionSettingsOnly(['auditTrailsEnabled' => true]);
    }

    // ── Version Info ──

    public function testGetVersionInfoOnly(): void
    {
        $result = $this->handler->getVersionInfoOnly();

        $this->assertSame('Open Register', $result['appName']);
        $this->assertSame('0.2.3', $result['appVersion']);
    }

    // ── convertToBoolean via getRetentionSettingsOnly ──

    public function testConvertToBooleanWithNumericValues(): void
    {
        $stored = json_encode([
            'auditTrailsEnabled' => 1,
            'searchTrailsEnabled' => 0,
        ]);

        $this->appConfig->method('getValueString')
            ->willReturn($stored);

        $result = $this->handler->getRetentionSettingsOnly();

        $this->assertTrue($result['auditTrailsEnabled']);
        $this->assertFalse($result['searchTrailsEnabled']);
    }

    public function testConvertToBooleanWithStringValues(): void
    {
        $stored = json_encode([
            'auditTrailsEnabled' => 'on',
            'searchTrailsEnabled' => 'false',
        ]);

        $this->appConfig->method('getValueString')
            ->willReturn($stored);

        $result = $this->handler->getRetentionSettingsOnly();

        $this->assertTrue($result['auditTrailsEnabled']);
        $this->assertFalse($result['searchTrailsEnabled']);
    }

    // ── Legacy field migration in getObjectSettingsOnly ──

    public function testGetObjectSettingsOnlyMigratesLegacyFields(): void
    {
        $stored = json_encode([
            'vectorizeAllSchemas' => false,
            'enabledSchemas' => ['schema-1', 'schema-2'],
        ]);

        $this->appConfig->method('getValueString')
            ->willReturn($stored);

        $result = $this->handler->getObjectSettingsOnly();

        // Legacy fields should be used as fallback
        $this->assertFalse($result['vectorizeAllViews']);
        $this->assertSame(['schema-1', 'schema-2'], $result['enabledViews']);
    }
}
