<?php

/**
 * Integration tests for RegisterService::findAllSerialized() / findSerialized().
 *
 * Exercises the `register-service-extensions` capability end-to-end against
 * a live database: schema expansion, orphan-ID retention, per-schema stats,
 * and HTTP/DI parity.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/extend-schemas-in-register-service/specs/register-service-extensions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\RegisterService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Integration tests for `findAllSerialized` / `findSerialized`.
 *
 * @group DB
 */
class RegisterSerializerIntegrationTest extends TestCase
{

    private RegisterService $registerService;
    private RegisterMapper $registerMapper;
    private SchemaMapper $schemaMapper;
    private ?Register $testRegister  = null;
    private array $createdSchemaIds  = [];


    protected function setUp(): void
    {
        parent::setUp();
        $this->registerService = \OC::$server->get(RegisterService::class);
        $this->registerMapper  = \OC::$server->get(RegisterMapper::class);
        $this->schemaMapper    = \OC::$server->get(SchemaMapper::class);

        $this->createTestRegisterWithSchemas();

    }//end setUp()


    protected function tearDown(): void
    {
        if ($this->testRegister !== null) {
            try {
                $this->registerMapper->delete($this->testRegister);
            } catch (\Exception $e) {
                // Ignore.
            }
        }

        foreach ($this->createdSchemaIds as $id) {
            try {
                $schema = $this->schemaMapper->find($id);
                $this->schemaMapper->delete($schema);
            } catch (\Exception $e) {
                // Ignore.
            }
        }

        parent::tearDown();

    }//end tearDown()


    /**
     * Create a register with two real schemas for the tests to operate on.
     *
     * @return void
     */
    private function createTestRegisterWithSchemas(): void
    {
        $schemaA = new Schema();
        $schemaA->setTitle('phpunit-serializer-A-'.uniqid());
        $schemaA->setUuid(Uuid::v4()->toRfc4122());
        $schemaA->setSlug('phpunit-serializer-a-'.uniqid());
        $schemaA->setProperties(
            [
                'name' => [
                    'type'  => 'string',
                    'title' => 'Name',
                ],
            ]
        );
        $schemaA = $this->schemaMapper->insert($schemaA);
        $this->createdSchemaIds[] = $schemaA->getId();

        $schemaB = new Schema();
        $schemaB->setTitle('phpunit-serializer-B-'.uniqid());
        $schemaB->setUuid(Uuid::v4()->toRfc4122());
        $schemaB->setSlug('phpunit-serializer-b-'.uniqid());
        $schemaB->setProperties(
            [
                'email' => [
                    'type'  => 'string',
                    'title' => 'Email',
                ],
            ]
        );
        $schemaB = $this->schemaMapper->insert($schemaB);
        $this->createdSchemaIds[] = $schemaB->getId();

        $register = new Register();
        $register->setTitle('phpunit-serializer-reg-'.uniqid());
        $register->setUuid(Uuid::v4()->toRfc4122());
        $register->setSlug('phpunit-serializer-reg-'.uniqid());
        $register->setSchemas([$schemaA->getId(), $schemaB->getId()]);
        $this->testRegister = $this->registerMapper->insert($register);

    }//end createTestRegisterWithSchemas()


    /**
     * 6.4 — DI caller invokes findAllSerialized with `['schemas']`.
     * Returned shape must match what the HTTP path produces (objects, not IDs).
     */
    public function testFindAllSerializedExpandsSchemasViaDi(): void
    {
        $registers = $this->registerService->findAllSerialized(
            filters: ['id' => $this->testRegister->getId()],
            _extend: ['schemas'],
            _multitenancy: false
        );

        $match = null;
        foreach ($registers as $reg) {
            if ((int) $reg['id'] === (int) $this->testRegister->getId()) {
                $match = $reg;
                break;
            }
        }

        $this->assertNotNull($match, 'expected to find the test register in the result');
        $this->assertCount(2, $match['schemas']);
        $this->assertIsArray($match['schemas'][0]);
        $this->assertArrayHasKey('id', $match['schemas'][0]);
        $this->assertArrayHasKey('title', $match['schemas'][0]);
        $this->assertArrayHasKey('properties', $match['schemas'][0]);

    }//end testFindAllSerializedExpandsSchemasViaDi()


    /**
     * 6.2 — Orphan ID is preserved when a schema referenced by the register has been deleted.
     */
    public function testOrphanSchemaIdIsRetained(): void
    {
        // Mutate the register so it references a non-existent schema between two real ones.
        $schemas   = $this->testRegister->getSchemas();
        $orphanId  = 999000999;
        $newSchemas = [$schemas[0], $orphanId, $schemas[1]];
        $this->testRegister->setSchemas($newSchemas);
        $this->testRegister = $this->registerMapper->update($this->testRegister);

        $serialized = $this->registerService->findSerialized(
            id: $this->testRegister->getId(),
            _extend: ['schemas'],
            _multitenancy: false
        );

        $this->assertCount(3, $serialized['schemas']);
        $this->assertIsArray($serialized['schemas'][0]);
        $this->assertSame($orphanId, $serialized['schemas'][1], 'orphan ID retained at original position');
        $this->assertIsArray($serialized['schemas'][2]);

    }//end testOrphanSchemaIdIsRetained()


    /**
     * 6.3 — `_extend: ['schemas', '@self.stats']` attaches stats.objects.total to expanded schemas.
     */
    public function testStatsAreAttachedToExpandedSchemas(): void
    {
        $serialized = $this->registerService->findSerialized(
            id: $this->testRegister->getId(),
            _extend: ['schemas', '@self.stats'],
            _multitenancy: false
        );

        foreach ($serialized['schemas'] as $schema) {
            $this->assertIsArray($schema, 'each schema must be expanded for the stats test');
            $this->assertArrayHasKey('stats', $schema);
            $this->assertArrayHasKey('objects', $schema['stats']);
            $this->assertArrayHasKey('total', $schema['stats']['objects']);
            $this->assertIsInt($schema['stats']['objects']['total']);
        }

    }//end testStatsAreAttachedToExpandedSchemas()


    /**
     * 6.1 — Without `_extend`, schemas remains an ID-only array (entity contract preserved).
     */
    public function testNoExtendKeepsSchemasAsIds(): void
    {
        $serialized = $this->registerService->findSerialized(
            id: $this->testRegister->getId(),
            _multitenancy: false
        );

        foreach ($serialized['schemas'] as $entry) {
            $this->assertTrue(
                is_int($entry) === true || is_string($entry) === true,
                'schemas must remain a list of bare IDs without `_extend`'
            );
        }

    }//end testNoExtendKeepsSchemasAsIds()


}//end class
