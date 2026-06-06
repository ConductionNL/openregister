<?php

/**
 * RegisterSerializerTest
 *
 * Unit tests for RegisterSerializer covering:
 * - Default (no _extend) output preserves ID-only schemas.
 * - 'schemas' extension replaces IDs with full schema objects.
 * - Orphan schema IDs are retained (not dropped) and a warning is logged.
 * - '@self.stats' extension attaches per-schema object counts.
 * - '@self.stats' without 'schemas' leaves schemas unchanged.
 * - Unknown _extend keys are silently ignored.
 * - serializeMany() delegates per-register stats correctly.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Serializer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/extend-schemas-in-register-service/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Serializer;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Serializer\RegisterSerializer;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for RegisterSerializer.
 *
 * @package OCA\OpenRegister\Tests\Unit\Service\Serializer
 */
class RegisterSerializerTest extends TestCase
{

    /**
     * Mock schema mapper.
     *
     * @var SchemaMapper|MockObject
     */
    private $schemaMapper;

    /**
     * Mock logger.
     *
     * @var LoggerInterface|MockObject
     */
    private $logger;

    /**
     * Serializer under test.
     *
     * @var RegisterSerializer
     */
    private RegisterSerializer $serializer;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->logger       = $this->createMock(LoggerInterface::class);

        $this->serializer = new RegisterSerializer(
            schemaMapper: $this->schemaMapper,
            logger: $this->logger
        );
    }

    // =========================================================================
    // Helper builders
    // =========================================================================

    /**
     * Build a Register entity with the given schema IDs.
     *
     * @param int   $id      Register integer ID.
     * @param array $schemas Array of schema IDs.
     *
     * @return Register
     */
    private function buildRegister(int $id, array $schemas = []): Register
    {
        $register = new Register();
        $register->setId($id);
        $register->setTitle('Test Register');
        $register->setSchemas($schemas);
        return $register;
    }

    /**
     * Build a Schema entity mock.
     *
     * @param int    $id    Schema integer ID.
     * @param string $title Schema title.
     *
     * @return Schema
     */
    private function buildSchema(int $id, string $title = 'Test Schema'): Schema
    {
        $schema = new Schema();
        $schema->setId($id);
        $schema->setTitle($title);
        return $schema;
    }

    // =========================================================================
    // 5.1 — Default + 'schemas' expansion
    // =========================================================================

    /**
     * Test serialize() with no _extend returns schemas as ID array.
     *
     * No SchemaMapper::find() calls expected.
     *
     * @return void
     *
     * @spec openspec/changes/extend-schemas-in-register-service/tasks.md#task-5.1
     */
    public function testSerializeNoExtendReturnsIdArray(): void
    {
        $register = $this->buildRegister(id: 1, schemas: [10, 20]);

        $this->schemaMapper->expects($this->never())->method('find');

        $result = $this->serializer->serialize(register: $register);

        $this->assertIsArray($result['schemas']);
        $this->assertEquals([10, 20], $result['schemas']);
    }

    /**
     * Test serialize() with 'schemas' extension replaces all IDs with objects.
     *
     * @return void
     *
     * @spec openspec/changes/extend-schemas-in-register-service/tasks.md#task-5.1
     */
    public function testSerializeWithSchemasExtensionExpandsAll(): void
    {
        $register = $this->buildRegister(id: 1, schemas: [10, 20]);
        $schema10 = $this->buildSchema(id: 10, title: 'Schema Ten');
        $schema20 = $this->buildSchema(id: 20, title: 'Schema Twenty');

        $this->schemaMapper->expects($this->exactly(2))
            ->method('find')
            ->willReturnCallback(function ($id) use ($schema10, $schema20) {
                if ($id === 10) {
                    return $schema10;
                }

                return $schema20;
            });

        $result = $this->serializer->serialize(register: $register, extend: ['schemas']);

        $this->assertCount(2, $result['schemas']);
        $this->assertIsArray($result['schemas'][0]);
        $this->assertIsArray($result['schemas'][1]);
        $this->assertEquals(10, $result['schemas'][0]['id']);
        $this->assertEquals(20, $result['schemas'][1]['id']);
        $this->assertEquals('Schema Ten', $result['schemas'][0]['title']);
    }

    /**
     * Test that entity jsonSerialize() is unaffected — still returns IDs.
     *
     * @return void
     *
     * @spec openspec/changes/extend-schemas-in-register-service/tasks.md#task-5.1
     */
    public function testEntityJsonSerializeRemainsIdOnly(): void
    {
        $register = $this->buildRegister(id: 1, schemas: [10, 20]);

        // Serialize with schemas expansion.
        $this->schemaMapper->method('find')->willReturn($this->buildSchema(id: 10));
        $this->serializer->serialize(register: $register, extend: ['schemas']);

        // Entity itself must still return IDs.
        $raw = $register->jsonSerialize();
        $this->assertEquals([10, 20], $raw['schemas']);
    }

    /**
     * Test serialize() with 'schemas' extension and empty schemas array.
     *
     * No SchemaMapper::find() calls expected when schemas array is empty.
     *
     * @return void
     *
     * @spec openspec/changes/extend-schemas-in-register-service/tasks.md#task-5.1
     */
    public function testSerializeEmptySchemasWithExtension(): void
    {
        $register = $this->buildRegister(id: 1, schemas: []);

        $this->schemaMapper->expects($this->never())->method('find');

        $result = $this->serializer->serialize(register: $register, extend: ['schemas']);

        $this->assertIsArray($result['schemas']);
        $this->assertEmpty($result['schemas']);
    }

    // =========================================================================
    // 5.2 — Orphan-ID retention
    // =========================================================================

    /**
     * Test that an orphan schema ID is retained in its original array position.
     *
     * @return void
     *
     * @spec openspec/changes/extend-schemas-in-register-service/tasks.md#task-5.2
     */
    public function testOrphanSchemaIdIsRetainedInPosition(): void
    {
        $register = $this->buildRegister(id: 1, schemas: [10, 999, 20]);
        $schema10 = $this->buildSchema(id: 10, title: 'Schema Ten');
        $schema20 = $this->buildSchema(id: 20, title: 'Schema Twenty');

        $this->schemaMapper->expects($this->exactly(3))
            ->method('find')
            ->willReturnCallback(function ($id) use ($schema10, $schema20) {
                if ($id === 10) {
                    return $schema10;
                }

                if ($id === 999) {
                    throw new DoesNotExistException(msg: 'Schema not found');
                }

                return $schema20;
            });

        $result = $this->serializer->serialize(register: $register, extend: ['schemas']);

        $this->assertCount(3, $result['schemas']);
        // Position 0: expanded schema object.
        $this->assertIsArray($result['schemas'][0]);
        $this->assertEquals(10, $result['schemas'][0]['id']);
        // Position 1: orphan ID retained.
        $this->assertEquals(999, $result['schemas'][1]);
        // Position 2: expanded schema object.
        $this->assertIsArray($result['schemas'][2]);
        $this->assertEquals(20, $result['schemas'][2]['id']);
    }

    /**
     * Test that a warning is logged for the orphan schema ID.
     *
     * @return void
     *
     * @spec openspec/changes/extend-schemas-in-register-service/tasks.md#task-5.2
     */
    public function testOrphanSchemaIdLogsWarning(): void
    {
        $register = $this->buildRegister(id: 1, schemas: [999]);

        $this->schemaMapper->method('find')
            ->willThrowException(new DoesNotExistException(msg: 'Schema not found'));

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('[RegisterSerializer]'),
                $this->arrayHasKey('schemaId')
            );

        $result = $this->serializer->serialize(register: $register, extend: ['schemas']);

        $this->assertEquals([999], $result['schemas']);
    }

    /**
     * Test that no exception propagates when a schema ID is orphaned.
     *
     * @return void
     *
     * @spec openspec/changes/extend-schemas-in-register-service/tasks.md#task-5.2
     */
    public function testOrphanSchemaDoesNotThrow(): void
    {
        $register = $this->buildRegister(id: 1, schemas: [999]);

        $this->schemaMapper->method('find')
            ->willThrowException(new DoesNotExistException(msg: 'Schema not found'));

        // Must not throw.
        $result = $this->serializer->serialize(register: $register, extend: ['schemas']);
        $this->assertCount(1, $result['schemas']);
    }

    /**
     * Test that orphan string UUID IDs retain their original string type.
     *
     * @return void
     *
     * @spec openspec/changes/extend-schemas-in-register-service/tasks.md#task-5.2
     */
    public function testOrphanStringIdRetainsStringType(): void
    {
        $register = $this->buildRegister(id: 1, schemas: [20]);
        // Manually inject a string UUID-like ID via JSON approach.
        // Since setSchemas filters non-int/non-string, we use a valid string.
        $register->setSchemas(['uuid-abc-123', 20]);

        $schema20 = $this->buildSchema(id: 20, title: 'Schema Twenty');

        $this->schemaMapper->method('find')
            ->willReturnCallback(function ($id) use ($schema20) {
                if ($id === 'uuid-abc-123') {
                    throw new DoesNotExistException(msg: 'Schema not found');
                }

                return $schema20;
            });

        $result = $this->serializer->serialize(register: $register, extend: ['schemas']);

        $this->assertCount(2, $result['schemas']);
        // Orphan string ID retained as string.
        $this->assertIsString($result['schemas'][0]);
        $this->assertEquals('uuid-abc-123', $result['schemas'][0]);
        // Position 1: expanded.
        $this->assertIsArray($result['schemas'][1]);
    }

    // =========================================================================
    // 5.3 — '@self.stats' interaction
    // =========================================================================

    /**
     * Test that '@self.stats' attaches stats to expanded schemas.
     *
     * @return void
     *
     * @spec openspec/changes/extend-schemas-in-register-service/tasks.md#task-5.3
     */
    public function testSelfStatsAttachedToExpandedSchemas(): void
    {
        $register = $this->buildRegister(id: 1, schemas: [10, 20]);
        $schema10 = $this->buildSchema(id: 10);
        $schema20 = $this->buildSchema(id: 20);

        $this->schemaMapper->method('find')
            ->willReturnCallback(function ($id) use ($schema10, $schema20) {
                return $id === 10 ? $schema10 : $schema20;
            });

        $stats = [
            10 => ['total' => 5, 'deleted' => 0, 'invalid' => 0, 'locked' => 0, 'size' => 0],
            20 => ['total' => 0, 'deleted' => 0, 'invalid' => 0, 'locked' => 0, 'size' => 0],
        ];

        $result = $this->serializer->serialize(
            register: $register,
            extend: ['schemas', '@self.stats'],
            schemaStats: $stats
        );

        $this->assertEquals(5, $result['schemas'][0]['stats']['objects']['total']);
        $this->assertEquals(0, $result['schemas'][1]['stats']['objects']['total']);
    }

    /**
     * Test that orphan IDs receive no stats attachment.
     *
     * @return void
     *
     * @spec openspec/changes/extend-schemas-in-register-service/tasks.md#task-5.3
     */
    public function testOrphanIdGetsNoStats(): void
    {
        $register = $this->buildRegister(id: 1, schemas: [10, 999]);
        $schema10 = $this->buildSchema(id: 10);

        $this->schemaMapper->method('find')
            ->willReturnCallback(function ($id) use ($schema10) {
                if ($id === 999) {
                    throw new DoesNotExistException(msg: 'Schema not found');
                }

                return $schema10;
            });

        $stats = [10 => ['total' => 3, 'deleted' => 0, 'invalid' => 0, 'locked' => 0, 'size' => 0]];

        $result = $this->serializer->serialize(
            register: $register,
            extend: ['schemas', '@self.stats'],
            schemaStats: $stats
        );

        $this->assertCount(2, $result['schemas']);
        // Schema 10 should have stats.
        $this->assertArrayHasKey('stats', $result['schemas'][0]);
        $this->assertEquals(3, $result['schemas'][0]['stats']['objects']['total']);
        // Orphan 999 is a bare integer — no stats field.
        $this->assertEquals(999, $result['schemas'][1]);
        $this->assertIsInt($result['schemas'][1]);
    }

    /**
     * Test that '@self.stats' without 'schemas' leaves schemas field unchanged.
     *
     * @return void
     *
     * @spec openspec/changes/extend-schemas-in-register-service/tasks.md#task-5.3
     */
    public function testSelfStatsAloneDoesNotExpandSchemas(): void
    {
        $register = $this->buildRegister(id: 1, schemas: [10, 20]);

        $this->schemaMapper->expects($this->never())->method('find');

        $result = $this->serializer->serialize(register: $register, extend: ['@self.stats']);

        $this->assertEquals([10, 20], $result['schemas']);
        $this->assertArrayNotHasKey('stats', $result);
    }

    /**
     * Test that an unknown _extend key is silently ignored.
     *
     * @return void
     *
     * @spec openspec/changes/extend-schemas-in-register-service/tasks.md#task-5.3
     */
    public function testUnknownExtendKeyIsSilentlyIgnored(): void
    {
        $register = $this->buildRegister(id: 1, schemas: [10, 20]);
        $schema10 = $this->buildSchema(id: 10);
        $schema20 = $this->buildSchema(id: 20);

        $this->schemaMapper->method('find')
            ->willReturnCallback(function ($id) use ($schema10, $schema20) {
                return $id === 10 ? $schema10 : $schema20;
            });

        $this->logger->expects($this->never())->method('warning');

        $result = $this->serializer->serialize(register: $register, extend: ['schemas', 'nonexistent-key']);

        // Output identical to ['schemas'] only.
        $this->assertCount(2, $result['schemas']);
        $this->assertIsArray($result['schemas'][0]);
        $this->assertIsArray($result['schemas'][1]);
    }

    // =========================================================================
    // serializeMany() tests
    // =========================================================================

    /**
     * Test serializeMany() returns serialized arrays for all registers.
     *
     * @return void
     *
     * @spec openspec/changes/extend-schemas-in-register-service/tasks.md#task-5.1
     */
    public function testSerializeManyReturnsAllRegisters(): void
    {
        $reg1 = $this->buildRegister(id: 1, schemas: [10]);
        $reg2 = $this->buildRegister(id: 2, schemas: [20]);

        $schema10 = $this->buildSchema(id: 10);
        $schema20 = $this->buildSchema(id: 20);

        $this->schemaMapper->method('find')
            ->willReturnCallback(function ($id) use ($schema10, $schema20) {
                return $id === 10 ? $schema10 : $schema20;
            });

        $result = $this->serializer->serializeMany(
            registers: [$reg1, $reg2],
            extend: ['schemas']
        );

        $this->assertCount(2, $result);
        $this->assertIsArray($result[0]['schemas'][0]);
        $this->assertIsArray($result[1]['schemas'][0]);
        $this->assertEquals(10, $result[0]['schemas'][0]['id']);
        $this->assertEquals(20, $result[1]['schemas'][0]['id']);
    }

    /**
     * Test serializeMany() routes per-register stats to the correct register.
     *
     * @return void
     *
     * @spec openspec/changes/extend-schemas-in-register-service/tasks.md#task-5.3
     */
    public function testSerializeManyRoutesStatsPerRegister(): void
    {
        $reg1 = $this->buildRegister(id: 1, schemas: [10]);
        $reg2 = $this->buildRegister(id: 2, schemas: [20]);

        $schema10 = $this->buildSchema(id: 10);
        $schema20 = $this->buildSchema(id: 20);

        $this->schemaMapper->method('find')
            ->willReturnCallback(function ($id) use ($schema10, $schema20) {
                return $id === 10 ? $schema10 : $schema20;
            });

        $statsByRegister = [
            1 => [10 => ['total' => 7, 'deleted' => 0, 'invalid' => 0, 'locked' => 0, 'size' => 0]],
            2 => [20 => ['total' => 3, 'deleted' => 0, 'invalid' => 0, 'locked' => 0, 'size' => 0]],
        ];

        $result = $this->serializer->serializeMany(
            registers: [$reg1, $reg2],
            extend: ['schemas', '@self.stats'],
            schemaStatsByRegisterId: $statsByRegister
        );

        $this->assertEquals(7, $result[0]['schemas'][0]['stats']['objects']['total']);
        $this->assertEquals(3, $result[1]['schemas'][0]['stats']['objects']['total']);
    }
}//end class
