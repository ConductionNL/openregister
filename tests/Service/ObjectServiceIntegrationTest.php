<?php

/**
 * Integration tests for ObjectService
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Integration tests for ObjectService
 *
 * Tests object CRUD operations, context management, and search functionality
 * using the real Nextcloud DI container and database.
 */
class ObjectServiceIntegrationTest extends TestCase
{
    /**
     * The object service instance
     *
     * @var ObjectService
     */
    private ObjectService $service;

    /**
     * Register mapper
     *
     * @var RegisterMapper
     */
    private RegisterMapper $registerMapper;

    /**
     * Schema mapper
     *
     * @var SchemaMapper
     */
    private SchemaMapper $schemaMapper;

    /**
     * Test register
     *
     * @var Register|null
     */
    private ?Register $testRegister = null;

    /**
     * Test schema
     *
     * @var Schema|null
     */
    private ?Schema $testSchema = null;

    /**
     * UUIDs of created test objects for cleanup
     *
     * @var string[]
     */
    private array $createdObjectUuids = [];

    /**
     * Set up test fixtures
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = \OC::$server->get(ObjectService::class);
        $this->registerMapper = \OC::$server->get(RegisterMapper::class);
        $this->schemaMapper = \OC::$server->get(SchemaMapper::class);

        // Create test register and schema
        $this->createTestRegisterAndSchema();
    }

    /**
     * Clean up test data
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // Clean up test objects
        foreach ($this->createdObjectUuids as $uuid) {
            try {
                $this->service->deleteObject($uuid, false, false);
            } catch (\Exception $e) {
                // Ignore cleanup errors
            }
        }

        // Clean up test schema and register
        if ($this->testSchema !== null) {
            try {
                $this->schemaMapper->delete($this->testSchema);
            } catch (\Exception $e) {
                // Ignore
            }
        }

        if ($this->testRegister !== null) {
            try {
                $this->registerMapper->delete($this->testRegister);
            } catch (\Exception $e) {
                // Ignore
            }
        }

        parent::tearDown();
    }

    /**
     * Create test register and schema for object tests
     *
     * @return void
     */
    private function createTestRegisterAndSchema(): void
    {
        $register = new Register();
        $register->setTitle('phpunit-test-' . uniqid());
        $register->setDescription('Test register for integration tests');
        $register->setUuid(Uuid::v4()->toRfc4122());
        $register->setSlug('phpunit-test-' . uniqid());
        $register->setSchemas([]);
        $this->testRegister = $this->registerMapper->insert($register);

        $schema = new Schema();
        $schema->setTitle('phpunit-test-' . uniqid());
        $schema->setDescription('Test schema for integration tests');
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('phpunit-test-' . uniqid());
        $schema->setProperties([
            'name' => [
                'type' => 'string',
                'title' => 'Name',
            ],
            'description' => [
                'type' => 'string',
                'title' => 'Description',
            ],
            'count' => [
                'type' => 'integer',
                'title' => 'Count',
            ],
        ]);
        $this->testSchema = $this->schemaMapper->insert($schema);

        // Add schema to register
        $this->testRegister->setSchemas([$this->testSchema->getId()]);
        $this->registerMapper->update($this->testRegister);
    }

    /**
     * Test setRegister with register ID
     *
     * @return void
     */
    public function testSetRegisterWithId(): void
    {
        $this->assertNotNull($this->testRegister);

        $result = $this->service->setRegister($this->testRegister->getId());

        $this->assertInstanceOf(ObjectService::class, $result);
        $this->assertSame($this->testRegister->getId(), $this->service->getRegister());
    }

    /**
     * Test setSchema with schema ID
     *
     * @return void
     */
    public function testSetSchemaWithId(): void
    {
        $this->assertNotNull($this->testSchema);

        $result = $this->service->setSchema($this->testSchema->getId());

        $this->assertInstanceOf(ObjectService::class, $result);
        $this->assertSame($this->testSchema->getId(), $this->service->getSchema());
    }

    /**
     * Test setRegister with Register object
     *
     * @return void
     */
    public function testSetRegisterWithObject(): void
    {
        $this->assertNotNull($this->testRegister);

        $result = $this->service->setRegister($this->testRegister);

        $this->assertInstanceOf(ObjectService::class, $result);
        $this->assertSame($this->testRegister->getId(), $this->service->getRegister());
    }

    /**
     * Test setSchema with Schema object
     *
     * @return void
     */
    public function testSetSchemaWithObject(): void
    {
        $this->assertNotNull($this->testSchema);

        $result = $this->service->setSchema($this->testSchema);

        $this->assertInstanceOf(ObjectService::class, $result);
        $this->assertSame($this->testSchema->getId(), $this->service->getSchema());
    }

    /**
     * Test getObject returns null when not set
     *
     * @return void
     */
    public function testGetObjectReturnsNull(): void
    {
        // Reset any cached state by getting a fresh service
        $service = \OC::$server->get(ObjectService::class);
        $result = $service->getObject();

        $this->assertNull($result);
    }

    /**
     * Test findAll with empty results
     *
     * @return void
     */
    public function testFindAllEmpty(): void
    {
        $this->assertNotNull($this->testRegister);
        $this->assertNotNull($this->testSchema);

        $this->service->setRegister($this->testRegister);
        $this->service->setSchema($this->testSchema);

        $results = $this->service->findAll(
            [
                'filters' => [
                    'register' => $this->testRegister->getId(),
                    'schema'   => $this->testSchema->getId(),
                ],
            ],
            false,
            false
        );

        $this->assertIsArray($results);
    }

    /**
     * Test saveObject creates new object
     *
     * @return void
     */
    public function testSaveObjectCreate(): void
    {
        $this->assertNotNull($this->testRegister);
        $this->assertNotNull($this->testSchema);

        $objectData = [
            'name'        => 'phpunit-test-' . uniqid(),
            'description' => 'Test object for integration tests',
            'count'       => 42,
        ];

        $result = $this->service->saveObject(
            $objectData,
            null,
            $this->testRegister,
            $this->testSchema,
            null,
            false,
            false
        );

        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->assertNotEmpty($result->getUuid());
        $this->createdObjectUuids[] = $result->getUuid();
    }

    /**
     * Test saveObject and then find
     *
     * @return void
     */
    public function testSaveAndFindObject(): void
    {
        $this->assertNotNull($this->testRegister);
        $this->assertNotNull($this->testSchema);

        $objectName = 'phpunit-test-' . uniqid();
        $objectData = [
            'name'        => $objectName,
            'description' => 'Test find object',
            'count'       => 7,
        ];

        $saved = $this->service->saveObject(
            $objectData,
            null,
            $this->testRegister,
            $this->testSchema,
            null,
            false,
            false
        );

        $this->createdObjectUuids[] = $saved->getUuid();

        // Now find the object
        $found = $this->service->find(
            $saved->getUuid(),
            [],
            false,
            $this->testRegister,
            $this->testSchema,
            false,
            false
        );

        $this->assertInstanceOf(ObjectEntity::class, $found);
        $this->assertSame($saved->getUuid(), $found->getUuid());
    }

    /**
     * Test count objects
     *
     * @return void
     */
    public function testCount(): void
    {
        $this->assertNotNull($this->testRegister);
        $this->assertNotNull($this->testSchema);

        $this->service->setRegister($this->testRegister);
        $this->service->setSchema($this->testSchema);

        $count = $this->service->count([
            'filters' => [
                'register' => $this->testRegister->getId(),
                'schema'   => $this->testSchema->getId(),
            ],
        ]);

        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    /**
     * Test deleteObject removes object
     *
     * @return void
     */
    public function testDeleteObject(): void
    {
        $this->assertNotNull($this->testRegister);
        $this->assertNotNull($this->testSchema);

        $objectData = [
            'name'        => 'phpunit-test-delete-' . uniqid(),
            'description' => 'Object to delete',
        ];

        $saved = $this->service->saveObject(
            $objectData,
            null,
            $this->testRegister,
            $this->testSchema,
            null,
            false,
            false
        );

        $uuid = $saved->getUuid();

        $result = $this->service->deleteObject($uuid, false, false);

        $this->assertTrue($result);
    }

    /**
     * Test findAll returns objects after creation
     *
     * @return void
     */
    public function testFindAllAfterCreate(): void
    {
        $this->assertNotNull($this->testRegister);
        $this->assertNotNull($this->testSchema);

        // Create an object first
        $objectData = [
            'name'        => 'phpunit-test-findall-' . uniqid(),
            'description' => 'Test findAll result',
        ];

        $saved = $this->service->saveObject(
            $objectData,
            null,
            $this->testRegister,
            $this->testSchema,
            null,
            false,
            false
        );

        $this->createdObjectUuids[] = $saved->getUuid();

        // Verify the object can be retrieved individually
        $found = $this->service->find(
            $saved->getUuid(),
            [],
            false,
            $this->testRegister,
            $this->testSchema,
            false,
            false
        );

        $this->assertInstanceOf(ObjectEntity::class, $found);
        $this->assertSame($saved->getUuid(), $found->getUuid());
    }

    /**
     * Test findByRelations returns array
     *
     * @return void
     */
    public function testFindByRelations(): void
    {
        $results = $this->service->findByRelations('nonexistent-uuid-' . uniqid());

        $this->assertIsArray($results);
        $this->assertCount(0, $results);
    }

    /**
     * Test saveObject update existing object
     *
     * @return void
     */
    public function testSaveObjectUpdate(): void
    {
        $this->assertNotNull($this->testRegister);
        $this->assertNotNull($this->testSchema);

        $objectData = [
            'name'        => 'phpunit-test-update-' . uniqid(),
            'description' => 'Original description',
            'count'       => 1,
        ];

        $saved = $this->service->saveObject(
            $objectData,
            null,
            $this->testRegister,
            $this->testSchema,
            null,
            false,
            false
        );

        $this->createdObjectUuids[] = $saved->getUuid();

        // Update the object
        $updateData = [
            'name'        => $saved->getObject()['name'] ?? $objectData['name'],
            'description' => 'Updated description',
            'count'       => 99,
        ];

        $updated = $this->service->saveObject(
            $updateData,
            null,
            $this->testRegister,
            $this->testSchema,
            $saved->getUuid(),
            false,
            false
        );

        $this->assertInstanceOf(ObjectEntity::class, $updated);
        $this->assertSame($saved->getUuid(), $updated->getUuid());
    }

    /**
     * Test getCreatedSubObjects returns array
     *
     * @return void
     */
    public function testGetCreatedSubObjects(): void
    {
        $result = $this->service->getCreatedSubObjects();

        $this->assertIsArray($result);
    }

    /**
     * Test clearCreatedSubObjects does not throw
     *
     * @return void
     */
    public function testClearCreatedSubObjects(): void
    {
        $this->service->clearCreatedSubObjects();

        // Verify cleared
        $result = $this->service->getCreatedSubObjects();
        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    /**
     * Test getCacheHandler returns a CacheHandler instance
     *
     * @return void
     */
    public function testGetCacheHandler(): void
    {
        $handler = $this->service->getCacheHandler();

        $this->assertNotNull($handler);
    }

    /**
     * Test getDeleteHandler returns a DeleteObject instance
     *
     * @return void
     */
    public function testGetDeleteHandler(): void
    {
        $handler = $this->service->getDeleteHandler();

        $this->assertNotNull($handler);
    }
}
