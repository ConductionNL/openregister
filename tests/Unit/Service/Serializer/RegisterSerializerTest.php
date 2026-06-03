<?php

/**
 * RegisterSerializer Unit Tests
 *
 * Tests for RegisterSerializer covering schema expansion, orphan ID retention,
 * stats attachment, and unknown _extend key handling.
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
 * Covers:
 * - Task 5.1: No _extend (IDs preserved) + 'schemas' expansion (all found)
 * - Task 5.2: Orphan schema ID retention + logger warning + type preservation
 * - Task 5.3: @self.stats interaction, unknown keys silently ignored
 *
 * @package OCA\OpenRegister\Tests\Unit\Service\Serializer
 *
 * @spec openspec/changes/extend-schemas-in-register-service/tasks.md#task-5
 */
class RegisterSerializerTest extends TestCase
{

    /**
     * Schema mapper mock.
     *
     * @var SchemaMapper|MockObject
     */
    private $schemaMapper;

    /**
     * Logger mock.
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
     * Set up test doubles.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->schemaMapper = $this->createMock(SchemaMapper::class); // phpcs:ignore
        $this->logger       = $this->createMock(LoggerInterface::class); // phpcs:ignore

        $this->serializer = new RegisterSerializer(
            schemaMapper: $this->schemaMapper,
            logger: $this->logger
        );
    }//end setUp()

    /**
     * Build a Register entity stub with the given schema IDs.
     *
     * @param int   $id        Register ID.
     * @param array $schemaIds Array of schema IDs to attach.
     *
     * @return Register
     */
    private function makeRegister(int $id, array $schemaIds=[]): Register
    {
        $register = new Register();
        $register->setId($id);
        $register->setTitle('Test Register');
        $register->setSchemas($schemaIds);
        return $register;
    }//end makeRegister()

    /**
     * Build a Schema entity stub with the given ID and title.
     *
     * @param int    $id         Schema ID.
     * @param string $title      Schema title.
     * @param array  $properties Schema properties.
     *
     * @return Schema
     */
    private function makeSchema(int $id, string $title='Schema', array $properties=[]): Schema
    {
        $schema = new Schema();
        $schema->setId($id);
        $schema->setTitle($title);
        if (empty($properties) === false) {
            $schema->setProperties($properties);
        }

        return $schema;
    }//end makeSchema()

    // =========================================================================
    // Task 5.1 — no _extend and full 'schemas' expansion
    // =========================================================================

    /**
     * No _extend → schemas field is the original ID array; no SchemaMapper calls made.
     *
     * @return void
     *
     * @spec openspec/changes/extend-schemas-in-register-service/tasks.md#task-5.1
     */
    public function testSerializeNoExtendReturnsIdArray(): void
    {
        $register = $this->makeRegister(id: 1, schemaIds: [10, 20]);

        $this->schemaMapper
            ->expects($this->never())
            ->method('find');

        $result = $this->serializer->serialize(register: $register, extend: []);

        $this->assertSame(expected: [10, 20], actual: $result['schemas']);
    }//end testSerializeNoExtendReturnsIdArray()

    /**
     * _extend=['schemas'] with all schemas resolvable → ordered array of schema objects.
     *
     * @return void
     *
     * @spec openspec/changes/extend-schemas-in-register-service/tasks.md#task-5.1
     */
    public function testSerializeSchemasExtendExpandsToObjects(): void
    {
        $register = $this->makeRegister(id: 1, schemaIds: [10, 20]);
        $schema10 = $this->makeSchema(id: 10, title: 'Alpha');
        $schema20 = $this->makeSchema(id: 20, title: 'Beta');

        $this->schemaMapper
            ->expects($this->exactly(count: 2))
            ->method('find')
            ->willReturnMap(
                [
                    [10, null, false, true, false, $schema10],
                    [20, null, false, true, false, $schema20],
                ]
            );

        $result = $this->serializer->serialize(register: $register, extend: ['schemas']);

        $this->assertCount(expectedCount: 2, haystack: $result['schemas']);
        $this->assertSame(expected: 10, actual: $result['schemas'][0]['id']);
        $this->assertSame(expected: 20, actual: $result['schemas'][1]['id']);
        $this->assertSame(expected: 'Alpha', actual: $result['schemas'][0]['title']);
        $this->assertSame(expected: 'Beta', actual: $result['schemas'][1]['title']);
    }//end testSerializeSchemasExtendExpandsToObjects()

    /**
     * _extend=['schemas'] preserves the 'properties' field on expanded schemas.
     *
     * @return void
     *
     * @spec openspec/changes/extend-schemas-in-register-service/tasks.md#task-5.1
     */
    public function testSerializeSchemasPreservesPropertiesField(): void
    {
        $register = $this->makeRegister(id: 1, schemaIds: [10]);
        $schema10 = $this->makeSchema(
            id: 10,
            title: 'WithProps',
            properties: ['name' => ['type' => 'string']]
        );

        $this->schemaMapper
            ->method('find')
            ->willReturn($schema10);

        $result = $this->serializer->serialize(register: $register, extend: ['schemas']);

        $this->assertArrayHasKey(key: 'properties', array: $result['schemas'][0]);
    }//end testSerializeSchemasPreservesPropertiesField()

    /**
     * Direct Register::jsonSerialize() still returns an ID array (entity contract unchanged).
     *
     * @return void
     *
     * @spec openspec/changes/extend-schemas-in-register-service/tasks.md#task-5.1
     */
    public function testRegisterEntityJsonSerializeStillReturnsIds(): void
    {
        $register = $this->makeRegister(id: 1, schemaIds: [10, 20]);
        $data     = $register->jsonSerialize();
        $this->assertSame(expected: [10, 20], actual: array_values($data['schemas']));
    }//end testRegisterEntityJsonSerializeStillReturnsIds()

    /**
     * Empty schemas array → serialize returns empty schemas, no mapper calls.
     *
     * @return void
     *
     * @spec openspec/changes/extend-schemas-in-register-service/tasks.md#task-5.1
     */
    public function testSerializeSchemasExtendWithEmptySchemas(): void
    {
        $register = $this->makeRegister(id: 1, schemaIds: []);

        $this->schemaMapper->expects($this->never())->method('find');

        $result = $this->serializer->serialize(register: $register, extend: ['schemas']);

        $this->assertSame(expected: [], actual: $result['schemas']);
    }//end testSerializeSchemasExtendWithEmptySchemas()

    // =========================================================================
    // Task 5.2 — orphan ID retention
    // =========================================================================

    /**
     * One orphan schema ID → retained in original array position (mixed object/ID array).
     *
     * @return void
     *
     * @spec openspec/changes/extend-schemas-in-register-service/tasks.md#task-5.2
     */
    public function testOrphanSchemaIdRetainedInPosition(): void
    {
        $register = $this->makeRegister(id: 1, schemaIds: [10, 999, 20]);
        $schema10 = $this->makeSchema(id: 10, title: 'Alpha');
        $schema20 = $this->makeSchema(id: 20, title: 'Beta');

        $this->schemaMapper
            ->method('find')
            ->willReturnCallback(
                    function ($id) use ($schema10, $schema20) {
                        if ($id === 10) {
                            return $schema10;
                        }

                        if ($id === 20) {
                            return $schema20;
                        }

                        throw new DoesNotExistException('not found');
                    }
                    );

        $result = $this->serializer->serialize(register: $register, extend: ['schemas']);

        // Position 0: schema object for ID 10.
        $this->assertIsArray($result['schemas'][0]); // phpcs:ignore
        $this->assertSame(expected: 10, actual: $result['schemas'][0]['id']);

        // Position 1: orphan ID 999 retained as-is.
        $this->assertSame(expected: 999, actual: $result['schemas'][1]);

        // Position 2: schema object for ID 20.
        $this->assertIsArray($result['schemas'][2]); // phpcs:ignore
        $this->assertSame(expected: 20, actual: $result['schemas'][2]['id']);
    }//end testOrphanSchemaIdRetainedInPosition()

    /**
     * Orphan ID triggers a logger warning containing the failing schema ID.
     *
     * @return void
     *
     * @spec openspec/changes/extend-schemas-in-register-service/tasks.md#task-5.2
     */
    public function testOrphanSchemaIdLogsWarning(): void
    {
        $register = $this->makeRegister(id: 1, schemaIds: [999]);

        $this->schemaMapper
            ->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains(string: '[RegisterSerializer]'), // phpcs:ignore
                $this->arrayHasKey(key: 'schemaId') // phpcs:ignore
            );

        $result = $this->serializer->serialize(register: $register, extend: ['schemas']);

        // No exception propagated.
        $this->assertSame(expected: 999, actual: $result['schemas'][0]);
    }//end testOrphanSchemaIdLogsWarning()

    /**
     * Orphan numeric ID stays int; orphan string UUID stays string.
     *
     * @return void
     *
     * @spec openspec/changes/extend-schemas-in-register-service/tasks.md#task-5.2
     */
    public function testOrphanIdTypePreserved(): void
    {
        $uuidOrphan = 'uuid-abc-123';
        $register   = $this->makeRegister(id: 1, schemaIds: [$uuidOrphan, 20]);
        $schema20   = $this->makeSchema(id: 20, title: 'Beta');

        $this->schemaMapper
            ->method('find')
            ->willReturnCallback(
                    function ($id) use ($schema20, $uuidOrphan) {
                        if ($id === $uuidOrphan) {
                            throw new DoesNotExistException('not found');
                        }

                        return $schema20;
                    }
                    );

        $result = $this->serializer->serialize(register: $register, extend: ['schemas']);

        // Orphan string UUID preserved as string.
        $this->assertIsString($result['schemas'][0]); // phpcs:ignore
        $this->assertSame(expected: $uuidOrphan, actual: $result['schemas'][0]);

        // Resolved schema at position 1.
        $this->assertIsArray($result['schemas'][1]); // phpcs:ignore
    }//end testOrphanIdTypePreserved()

    // =========================================================================
    // Task 5.3 — @self.stats interaction
    // =========================================================================

    /**
     * _extend=['schemas','@self.stats'] → expanded schemas get stats.objects.total.
     *
     * @return void
     *
     * @spec openspec/changes/extend-schemas-in-register-service/tasks.md#task-5.3
     */
    public function testStatsAttachedToExpandedSchemas(): void
    {
        $register = $this->makeRegister(id: 1, schemaIds: [10, 20]);
        $schema10 = $this->makeSchema(id: 10, title: 'Alpha');
        $schema20 = $this->makeSchema(id: 20, title: 'Beta');

        $this->schemaMapper
            ->method('find')
            ->willReturnMap(
                    [
                        [10, null, false, true, false, $schema10],
                        [20, null, false, true, false, $schema20],
                    ]
                    );

        $schemaStats = [10 => ['total' => 5], 20 => ['total' => 0]];

        $result = $this->serializer->serialize(
            register: $register,
            extend: ['schemas', '@self.stats'],
            schemaStats: $schemaStats
        );

        $this->assertSame(expected: 5, actual: $result['schemas'][0]['stats']['objects']['total']);
        $this->assertSame(expected: 0, actual: $result['schemas'][1]['stats']['objects']['total']);
    }//end testStatsAttachedToExpandedSchemas()

    /**
     * Orphan ID → no stats attached; it stays a bare ID even with @self.stats.
     *
     * @return void
     *
     * @spec openspec/changes/extend-schemas-in-register-service/tasks.md#task-5.3
     */
    public function testStatsNotAttachedToOrphanId(): void
    {
        $register = $this->makeRegister(id: 1, schemaIds: [10, 999]);
        $schema10 = $this->makeSchema(id: 10, title: 'Alpha');

        $this->schemaMapper
            ->method('find')
            ->willReturnCallback(
                    function ($id) use ($schema10) {
                        if ($id === 10) {
                            return $schema10;
                        }

                        throw new DoesNotExistException('not found');
                    }
                    );

        $schemaStats = [10 => ['total' => 3]];

        $result = $this->serializer->serialize(
            register: $register,
            extend: ['schemas', '@self.stats'],
            schemaStats: $schemaStats
        );

        // Schema 10 has stats.
        $this->assertSame(expected: 3, actual: $result['schemas'][0]['stats']['objects']['total']);

        // Orphan 999 is a bare ID, no stats.
        $this->assertSame(expected: 999, actual: $result['schemas'][1]);
        $this->assertIsNotArray($result['schemas'][1]); // phpcs:ignore
    }//end testStatsNotAttachedToOrphanId()

    /**
     * _extend=['@self.stats'] alone (without 'schemas') → schemas stay as IDs, no stats.
     *
     * @return void
     *
     * @spec openspec/changes/extend-schemas-in-register-service/tasks.md#task-5.3
     */
    public function testStatsAloneWithoutSchemasHasNoEffect(): void
    {
        $register = $this->makeRegister(id: 1, schemaIds: [10, 20]);

        $this->schemaMapper->expects($this->never())->method('find');

        $result = $this->serializer->serialize(register: $register, extend: ['@self.stats']);

        // Schemas remain IDs.
        $this->assertSame(expected: [10, 20], actual: array_values($result['schemas']));
        // No stats on the register or schemas.
        $this->assertArrayNotHasKey(key: 'stats', array: $result);
    }//end testStatsAloneWithoutSchemasHasNoEffect()

    /**
     * Unknown _extend key → output identical to extension without that key; no warning.
     *
     * @return void
     *
     * @spec openspec/changes/extend-schemas-in-register-service/tasks.md#task-5.3
     */
    public function testUnknownExtendKeyIgnoredSilently(): void
    {
        $register = $this->makeRegister(id: 1, schemaIds: [10]);
        $schema10 = $this->makeSchema(id: 10, title: 'Alpha');

        $this->schemaMapper->method('find')->willReturn($schema10);

        $this->logger->expects($this->never())->method('warning');

        $resultWithUnknown    = $this->serializer->serialize(
            register: $register,
            extend: ['schemas', 'nonexistent-key']
        );
        $resultWithoutUnknown = $this->serializer->serialize(
            register: $register,
            extend: ['schemas']
        );

        // Schema fields must be identical.
        $this->assertSame(
            expected: $resultWithoutUnknown['schemas'],
            actual: $resultWithUnknown['schemas']
        );
    }//end testUnknownExtendKeyIgnoredSilently()

    // =========================================================================
    // serializeMany tests
    // =========================================================================

    /**
     * SerializeMany — returns one entry per register, schema expansion applied to each.
     *
     * @return void
     *
     * @spec openspec/changes/extend-schemas-in-register-service/tasks.md#task-1.1
     */
    public function testSerializeManyExpandsEachRegister(): void
    {
        $reg1     = $this->makeRegister(id: 1, schemaIds: [10]);
        $reg2     = $this->makeRegister(id: 2, schemaIds: [20]);
        $schema10 = $this->makeSchema(id: 10, title: 'Alpha');
        $schema20 = $this->makeSchema(id: 20, title: 'Beta');

        $this->schemaMapper
            ->method('find')
            ->willReturnMap(
                    [
                        [10, null, false, true, false, $schema10],
                        [20, null, false, true, false, $schema20],
                    ]
                    );

        $results = $this->serializer->serializeMany(
            registers: [$reg1, $reg2],
            extend: ['schemas']
        );

        $this->assertCount(expectedCount: 2, haystack: $results);
        $this->assertSame(expected: 10, actual: $results[0]['schemas'][0]['id']);
        $this->assertSame(expected: 20, actual: $results[1]['schemas'][0]['id']);
    }//end testSerializeManyExpandsEachRegister()

    /**
     * SerializeMany with per-register stats lookup — correct stats per register.
     *
     * @return void
     *
     * @spec openspec/changes/extend-schemas-in-register-service/tasks.md#task-1.1
     */
    public function testSerializeManyPassesPerRegisterStats(): void
    {
        $reg1     = $this->makeRegister(id: 1, schemaIds: [10]);
        $reg2     = $this->makeRegister(id: 2, schemaIds: [20]);
        $schema10 = $this->makeSchema(id: 10, title: 'Alpha');
        $schema20 = $this->makeSchema(id: 20, title: 'Beta');

        $this->schemaMapper
            ->method('find')
            ->willReturnMap(
                    [
                        [10, null, false, true, false, $schema10],
                        [20, null, false, true, false, $schema20],
                    ]
                    );

        $statsByRegisterId = [
            1 => [10 => ['total' => 7]],
            2 => [20 => ['total' => 3]],
        ];

        $results = $this->serializer->serializeMany(
            registers: [$reg1, $reg2],
            extend: ['schemas', '@self.stats'],
            schemaStatsByRegisterId: $statsByRegisterId
        );

        $this->assertSame(expected: 7, actual: $results[0]['schemas'][0]['stats']['objects']['total']);
        $this->assertSame(expected: 3, actual: $results[1]['schemas'][0]['stats']['objects']['total']);
    }//end testSerializeManyPassesPerRegisterStats()
}//end class
