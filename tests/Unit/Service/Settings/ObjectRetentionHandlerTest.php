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
}
