<?php

declare(strict_types=1);

/**
 * MetadataHydrationHandler Deprecation Warning Tests
 *
 * Tests that deprecated schema configuration keys (objectPublishedField,
 * objectDepublishedField, autoPublish) trigger deprecation warnings.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests deprecation warnings for removed published metadata config keys.
 */
class MetadataHydrationHandlerDeprecationTest extends TestCase
{
    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    /** @var CacheHandler&MockObject */
    private CacheHandler $cacheHandler;

    /** @var MetadataHydrationHandler */
    private MetadataHydrationHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->cacheHandler = $this->createMock(CacheHandler::class);
        $this->handler = new MetadataHydrationHandler(
            $this->logger,
            $this->cacheHandler
        );
    }

    /**
     * Test that objectPublishedField config key triggers deprecation warning.
     */
    public function testObjectPublishedFieldTriggersDeprecationWarning(): void
    {
        $entity = $this->createMockEntity(['name' => 'Test Object']);
        $schema = $this->createMockSchema([
            'objectPublishedField' => 'publicatieDatum',
        ]);

        $this->logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with(
                $this->stringContains('objectPublishedField'),
                $this->callback(function (array $context) {
                    return $context['key'] === 'objectPublishedField'
                        && $context['value'] === 'publicatieDatum';
                })
            );

        $this->handler->hydrateObjectMetadata($entity, $schema);
    }

    /**
     * Test that objectDepublishedField config key triggers deprecation warning.
     */
    public function testObjectDepublishedFieldTriggersDeprecationWarning(): void
    {
        $entity = $this->createMockEntity(['name' => 'Test Object']);
        $schema = $this->createMockSchema([
            'objectDepublishedField' => 'depublicatieDatum',
        ]);

        $this->logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with(
                $this->stringContains('objectDepublishedField'),
                $this->callback(function (array $context) {
                    return $context['key'] === 'objectDepublishedField'
                        && $context['value'] === 'depublicatieDatum';
                })
            );

        $this->handler->hydrateObjectMetadata($entity, $schema);
    }

    /**
     * Test that autoPublish config key triggers deprecation warning.
     */
    public function testAutoPublishTriggersDeprecationWarning(): void
    {
        $entity = $this->createMockEntity(['name' => 'Test Object']);
        $schema = $this->createMockSchema([
            'autoPublish' => true,
        ]);

        $this->logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with(
                $this->stringContains('autoPublish'),
                $this->callback(function (array $context) {
                    return $context['key'] === 'autoPublish'
                        && $context['value'] === true;
                })
            );

        $this->handler->hydrateObjectMetadata($entity, $schema);
    }

    /**
     * Test that multiple deprecated keys each trigger their own warning.
     */
    public function testMultipleDeprecatedKeysEachTriggerWarning(): void
    {
        $entity = $this->createMockEntity(['name' => 'Test Object']);
        $schema = $this->createMockSchema([
            'objectPublishedField' => 'publicatieDatum',
            'objectDepublishedField' => 'depublicatieDatum',
            'autoPublish' => true,
        ]);

        // Expect exactly 3 warning calls (one for each deprecated key).
        $this->logger->expects($this->exactly(3))
            ->method('warning');

        $this->handler->hydrateObjectMetadata($entity, $schema);
    }

    /**
     * Test that non-deprecated config keys do NOT trigger warnings.
     */
    public function testNonDeprecatedKeysDoNotTriggerWarning(): void
    {
        $entity = $this->createMockEntity(['name' => 'Test Object']);
        $schema = $this->createMockSchema([
            'objectNameField' => 'name',
            'objectDescriptionField' => 'description',
        ]);

        $this->logger->expects($this->never())
            ->method('warning');

        $this->handler->hydrateObjectMetadata($entity, $schema);
    }

    /**
     * Test that deprecation warning suggests RBAC $now migration.
     */
    public function testDeprecationWarningSuggestsRbacMigration(): void
    {
        $entity = $this->createMockEntity(['name' => 'Test Object']);
        $schema = $this->createMockSchema([
            'objectPublishedField' => 'publicatieDatum',
        ]);

        $this->logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with(
                $this->stringContains('RBAC authorization rules with $now'),
                $this->anything()
            );

        $this->handler->hydrateObjectMetadata($entity, $schema);
    }

    /**
     * Create a mock ObjectEntity with given object data.
     *
     * @param array $objectData The object data
     *
     * @return ObjectEntity&MockObject
     */
    private function createMockEntity(array $objectData): ObjectEntity
    {
        $entity = $this->createMock(ObjectEntity::class);
        $entity->method('getObject')->willReturn($objectData);
        return $entity;
    }

    /**
     * Create a mock Schema with given configuration.
     *
     * @param array $config The schema configuration
     *
     * @return Schema&MockObject
     */
    private function createMockSchema(array $config): Schema
    {
        $schema = $this->createMock(Schema::class);
        $schema->method('getConfiguration')->willReturn($config);
        $schema->method('getId')->willReturn(1);
        $schema->method('getProperties')->willReturn([]);
        return $schema;
    }
}
