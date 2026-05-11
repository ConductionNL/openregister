<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Serializer;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Serializer\RegisterSerializer;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for `RegisterSerializer`.
 *
 * Covers the `_extend` contract defined by the
 * `register-service-extensions` capability: `schemas` expansion,
 * `@self.stats` stats attachment, orphan-ID retention, and unknown-key
 * tolerance.
 */
class RegisterSerializerTest extends TestCase
{

    /**
     * @var SchemaMapper&\PHPUnit\Framework\MockObject\MockObject
     */
    private $schemaMapper;

    /**
     * @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private $logger;

    private RegisterSerializer $serializer;


    protected function setUp(): void
    {
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->logger       = $this->createMock(LoggerInterface::class);
        $this->serializer   = new RegisterSerializer(
            schemaMapper: $this->schemaMapper,
            logger: $this->logger
        );

    }//end setUp()


    /**
     * Build a Register entity stub with the given ID and schema-ID list.
     *
     * @param int               $id        Register ID
     * @param array<int|string> $schemaIds Schema IDs
     *
     * @return Register
     */
    private function makeRegister(int $id, array $schemaIds): Register
    {
        $register = new Register();
        $register->setId($id);
        $register->setUuid('uuid-'.$id);
        $register->setSlug('register-'.$id);
        $register->setTitle('Register '.$id);
        $register->setSchemas($schemaIds);

        return $register;
    }//end makeRegister()


    /**
     * Build a Schema entity stub with id + title + properties for assertion.
     *
     * @param int   $id         Schema ID
     * @param array $properties Properties array
     *
     * @return Schema
     */
    private function makeSchema(int $id, array $properties=[]): Schema
    {
        $schema = new Schema();
        $schema->setId($id);
        $schema->setUuid('schema-uuid-'.$id);
        $schema->setSlug('schema-'.$id);
        $schema->setTitle('Schema '.$id);
        $schema->setProperties($properties);

        return $schema;
    }//end makeSchema()


    /**
     * 5.1 — No `_extend` → schemas stays as ID array; no SchemaMapper::find calls.
     */
    public function testNoExtendKeepsSchemasAsIds(): void
    {
        $register = $this->makeRegister(id: 1, schemaIds: [10, 20]);

        $this->schemaMapper->expects($this->never())->method('find');

        $result = $this->serializer->serialize(register: $register, extend: []);

        $this->assertSame([10, 20], $result['schemas']);
    }//end testNoExtendKeepsSchemasAsIds()


    /**
     * 5.2 — `['schemas']` with all schemas resolvable → schemas become objects with id/title/properties.
     */
    public function testSchemasExtendExpandsResolvableIds(): void
    {
        $register = $this->makeRegister(id: 1, schemaIds: [10, 20]);

        $schema10 = $this->makeSchema(id: 10, properties: ['name' => ['type' => 'string']]);
        $schema20 = $this->makeSchema(id: 20, properties: ['email' => ['type' => 'string']]);

        $this->schemaMapper->method('find')
            ->willReturnCallback(
                function (int|string $id) use ($schema10, $schema20): Schema {
                    return ($id === 10) ? $schema10 : $schema20;
                }
            );

        $result = $this->serializer->serialize(register: $register, extend: ['schemas']);

        $this->assertCount(2, $result['schemas']);
        $this->assertSame(10, $result['schemas'][0]['id']);
        $this->assertSame('Schema 10', $result['schemas'][0]['title']);
        $this->assertArrayHasKey('properties', $result['schemas'][0]);
        $this->assertSame(20, $result['schemas'][1]['id']);
    }//end testSchemasExtendExpandsResolvableIds()


    /**
     * 5.3 — `['schemas']` with one orphan ID → orphan retained in original position; warning logged.
     */
    public function testOrphanSchemaIdIsRetainedInPlace(): void
    {
        $register = $this->makeRegister(id: 1, schemaIds: [10, 999, 20]);

        $schema10 = $this->makeSchema(id: 10);
        $schema20 = $this->makeSchema(id: 20);

        $this->schemaMapper->method('find')
            ->willReturnCallback(
                function (int|string $id) use ($schema10, $schema20): Schema {
                    if ($id === 10) {
                        return $schema10;
                    }

                    if ($id === 20) {
                        return $schema20;
                    }

                    throw new DoesNotExistException(msg: 'not found');
                }
            );

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('Schema not found'),
                $this->callback(
                    function (array $context): bool {
                        return $context['schemaId'] === 999;
                    }
                )
            );

        $result = $this->serializer->serialize(register: $register, extend: ['schemas']);

        $this->assertCount(3, $result['schemas']);
        $this->assertSame(10, $result['schemas'][0]['id']);
        $this->assertSame(999, $result['schemas'][1], 'orphan ID must be preserved at original position');
        $this->assertSame(20, $result['schemas'][2]['id']);
    }//end testOrphanSchemaIdIsRetainedInPlace()


    /**
     * 5.4 — Mixed numeric + UUID orphans → each orphan keeps its original type.
     */
    public function testOrphanIdsPreserveOriginalType(): void
    {
        $register = $this->makeRegister(id: 1, schemaIds: [777, 'uuid-abc-123']);

        $this->schemaMapper->method('find')
            ->willThrowException(new DoesNotExistException(msg: 'not found'));

        $result = $this->serializer->serialize(register: $register, extend: ['schemas']);

        $this->assertSame(777, $result['schemas'][0]);
        $this->assertSame('uuid-abc-123', $result['schemas'][1]);
        $this->assertIsInt($result['schemas'][0]);
        $this->assertIsString($result['schemas'][1]);
    }//end testOrphanIdsPreserveOriginalType()


    /**
     * 5.5 — `['schemas', '@self.stats']` with precomputed stats → stats.objects.total attached.
     */
    public function testStatsAreAttachedToExpandedSchemas(): void
    {
        $register = $this->makeRegister(id: 1, schemaIds: [10, 20]);

        $schema10 = $this->makeSchema(id: 10);
        $schema20 = $this->makeSchema(id: 20);

        $this->schemaMapper->method('find')
            ->willReturnCallback(
                function (int|string $id) use ($schema10, $schema20): Schema {
                    return ($id === 10) ? $schema10 : $schema20;
                }
            );

        $stats = [
            10 => ['total' => 5],
            20 => ['total' => 0],
        ];

        $result = $this->serializer->serialize(
            register: $register,
            extend: ['schemas', '@self.stats'],
            schemaStats: $stats
        );

        $this->assertSame(5, $result['schemas'][0]['stats']['objects']['total']);
        $this->assertSame(0, $result['schemas'][1]['stats']['objects']['total']);
    }//end testStatsAreAttachedToExpandedSchemas()


    /**
     * 5.6 — `['schemas', '@self.stats']` with orphan → expanded has stats; orphan stays bare ID.
     */
    public function testStatsAreNotAttachedToOrphanIds(): void
    {
        $register = $this->makeRegister(id: 1, schemaIds: [10, 999]);

        $schema10 = $this->makeSchema(id: 10);

        $this->schemaMapper->method('find')
            ->willReturnCallback(
                function (int|string $id) use ($schema10): Schema {
                    if ($id === 10) {
                        return $schema10;
                    }

                    throw new DoesNotExistException(msg: 'not found');
                }
            );

        $stats = [10 => ['total' => 3]];

        $result = $this->serializer->serialize(
            register: $register,
            extend: ['schemas', '@self.stats'],
            schemaStats: $stats
        );

        $this->assertIsArray($result['schemas'][0]);
        $this->assertSame(3, $result['schemas'][0]['stats']['objects']['total']);
        $this->assertSame(999, $result['schemas'][1], 'orphan ID stays bare; no stats wrapping');
    }//end testStatsAreNotAttachedToOrphanIds()


    /**
     * 5.7 — `['@self.stats']` alone (no `'schemas'`) → schemas field unchanged ID array; no stats.
     */
    public function testStatsAloneIsNoOp(): void
    {
        $register = $this->makeRegister(id: 1, schemaIds: [10, 20]);

        $this->schemaMapper->expects($this->never())->method('find');

        $result = $this->serializer->serialize(
            register: $register,
            extend: ['@self.stats'],
            schemaStats: [10 => ['total' => 5]]
        );

        $this->assertSame([10, 20], $result['schemas'], 'schemas must remain bare IDs without `schemas` extend');
    }//end testStatsAloneIsNoOp()


    /**
     * 5.8 — `['schemas', 'unknown-key']` → identical to `['schemas']`; no warnings for unknown keys.
     */
    public function testUnknownExtendKeyIsIgnoredSilently(): void
    {
        $register = $this->makeRegister(id: 1, schemaIds: [10]);

        $schema10 = $this->makeSchema(id: 10);
        $this->schemaMapper->method('find')->willReturn($schema10);

        $this->logger->expects($this->never())->method('warning');

        $resultOnlySchemas = $this->serializer->serialize(register: $register, extend: ['schemas']);
        $resultWithUnknown = $this->serializer->serialize(register: $register, extend: ['schemas', 'totally-unknown']);

        $this->assertEquals($resultOnlySchemas, $resultWithUnknown);
    }//end testUnknownExtendKeyIsIgnoredSilently()


    /**
     * 5.9 — Register::jsonSerialize() entity contract is unchanged → schemas is an ID array.
     *
     * Asserts that nothing in this change leaks expansion into the entity itself.
     */
    public function testRegisterEntityJsonSerializeStillReturnsIds(): void
    {
        $register = $this->makeRegister(id: 1, schemaIds: [10, 20]);

        $serialized = $register->jsonSerialize();

        $this->assertSame([10, 20], $serialized['schemas']);
    }//end testRegisterEntityJsonSerializeStillReturnsIds()


    /**
     * Bonus — `serializeMany` delegates per register and routes per-register stats correctly.
     */
    public function testSerializeManyRoutesPerRegisterStats(): void
    {
        $reg1 = $this->makeRegister(id: 1, schemaIds: [10]);
        $reg2 = $this->makeRegister(id: 2, schemaIds: [20]);

        $schema10 = $this->makeSchema(id: 10);
        $schema20 = $this->makeSchema(id: 20);

        $this->schemaMapper->method('find')
            ->willReturnCallback(
                function (int|string $id) use ($schema10, $schema20): Schema {
                    return ($id === 10) ? $schema10 : $schema20;
                }
            );

        $statsByRegisterId = [
            1 => [10 => ['total' => 7]],
            2 => [20 => ['total' => 11]],
        ];

        $result = $this->serializer->serializeMany(
            registers: [$reg1, $reg2],
            extend: ['schemas', '@self.stats'],
            schemaStatsByRegisterId: $statsByRegisterId
        );

        $this->assertCount(2, $result);
        $this->assertSame(7, $result[0]['schemas'][0]['stats']['objects']['total']);
        $this->assertSame(11, $result[1]['schemas'][0]['stats']['objects']['total']);
    }//end testSerializeManyRoutesPerRegisterStats()


}//end class
