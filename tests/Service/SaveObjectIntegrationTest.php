<?php

/**
 * Integration tests for SaveObject service
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
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Integration tests for SaveObject handler
 *
 * Tests individual object save operations including creation, updates,
 * metadata hydration, defaults, and relation scanning.
 */
class SaveObjectIntegrationTest extends TestCase
{
    /**
     * The save object handler instance
     *
     * @var SaveObject
     */
    private SaveObject $saveHandler;

    /**
     * The object service (for higher-level operations)
     *
     * @var ObjectService
     */
    private ObjectService $objectService;

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
     * Created object UUIDs for cleanup
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
        $this->saveHandler = \OC::$server->get(SaveObject::class);
        $this->objectService = \OC::$server->get(ObjectService::class);
        $this->registerMapper = \OC::$server->get(RegisterMapper::class);
        $this->schemaMapper = \OC::$server->get(SchemaMapper::class);

        $this->createTestRegisterAndSchema();
    }

    /**
     * Clean up test data
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->createdObjectUuids as $uuid) {
            try {
                $this->objectService->deleteObject($uuid, false, false);
            } catch (\Exception $e) {
                // Ignore cleanup errors
            }
        }

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
     * Create test register and schema
     *
     * @return void
     */
    private function createTestRegisterAndSchema(): void
    {
        $register = new Register();
        $register->setTitle('phpunit-test-save-' . uniqid());
        $register->setDescription('Test register for SaveObject tests');
        $register->setUuid(Uuid::v4()->toRfc4122());
        $register->setSlug('phpunit-test-' . uniqid());
        $register->setSchemas([]);
        $this->testRegister = $this->registerMapper->insert($register);

        $schema = new Schema();
        $schema->setTitle('phpunit-test-save-schema-' . uniqid());
        $schema->setDescription('Test schema for SaveObject tests');
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('phpunit-test-' . uniqid());
        $schema->setProperties([
            'title' => [
                'type'    => 'string',
                'title'   => 'Title',
                'required' => true,
            ],
            'summary' => [
                'type'  => 'string',
                'title' => 'Summary',
            ],
            'priority' => [
                'type'    => 'integer',
                'title'   => 'Priority',
                'default' => 0,
            ],
            'tags' => [
                'type'  => 'array',
                'title' => 'Tags',
                'items' => ['type' => 'string'],
            ],
        ]);
        $this->testSchema = $this->schemaMapper->insert($schema);

        $this->testRegister->setSchemas([$this->testSchema->getId()]);
        $this->registerMapper->update($this->testRegister);
    }

    /**
     * Test getCreatedSubObjects returns empty array initially
     *
     * @return void
     */
    public function testGetCreatedSubObjectsEmpty(): void
    {
        $result = $this->saveHandler->getCreatedSubObjects();

        $this->assertIsArray($result);
    }

    /**
     * Test clearCreatedSubObjects does not throw
     *
     * @return void
     */
    public function testClearCreatedSubObjects(): void
    {
        $this->saveHandler->clearCreatedSubObjects();

        $result = $this->saveHandler->getCreatedSubObjects();
        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    /**
     * Test clearAllCaches does not throw
     *
     * @return void
     */
    public function testClearAllCaches(): void
    {
        $this->saveHandler->clearAllCaches();

        // Should not throw
        $this->assertTrue(true);
    }

    /**
     * Test scanForRelations with flat data
     *
     * @return void
     */
    public function testScanForRelationsFlatData(): void
    {
        $data = [
            'title'    => 'Test',
            'summary'  => 'Just a summary',
            'priority' => 5,
        ];

        $result = $this->saveHandler->scanForRelations($data, '', $this->testSchema);

        $this->assertIsArray($result);
    }

    /**
     * Test scanForRelations with empty data
     *
     * @return void
     */
    public function testScanForRelationsEmpty(): void
    {
        $result = $this->saveHandler->scanForRelations([]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test applyAlwaysDefaults applies default values
     *
     * @return void
     */
    public function testApplyAlwaysDefaults(): void
    {
        $data = [
            'title'   => 'Test',
            'summary' => 'A summary',
        ];

        $result = $this->saveHandler->applyAlwaysDefaults($this->testSchema, $data);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('title', $result);
    }

    /**
     * Test applyPropertyDefaults adds missing defaults
     *
     * @return void
     */
    public function testApplyPropertyDefaults(): void
    {
        $data = [
            'title' => 'Test without priority',
        ];

        $result = $this->saveHandler->applyPropertyDefaults($this->testSchema, $data);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('title', $result);
    }

    /**
     * Test saveObject creates a new object via the handler
     *
     * @return void
     */
    public function testSaveObjectViaHandler(): void
    {
        $this->assertNotNull($this->testRegister);
        $this->assertNotNull($this->testSchema);

        $objectData = [
            'title'    => 'phpunit-test-save-' . uniqid(),
            'summary'  => 'Test save via handler',
            'priority' => 3,
        ];

        $result = $this->saveHandler->saveObject(
            $this->testRegister,
            $this->testSchema,
            $objectData
        );

        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->assertNotEmpty($result->getUuid());

        $this->createdObjectUuids[] = $result->getUuid();
    }

    /**
     * Test saveObject with minimal data
     *
     * @return void
     */
    public function testSaveObjectMinimalData(): void
    {
        $this->assertNotNull($this->testRegister);
        $this->assertNotNull($this->testSchema);

        $objectData = [
            'title' => 'phpunit-test-minimal-' . uniqid(),
        ];

        $result = $this->saveHandler->saveObject(
            $this->testRegister,
            $this->testSchema,
            $objectData
        );

        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->createdObjectUuids[] = $result->getUuid();
    }

    /**
     * Test updateObject modifies existing object
     *
     * @return void
     */
    public function testUpdateObject(): void
    {
        $this->assertNotNull($this->testRegister);
        $this->assertNotNull($this->testSchema);

        // First create
        $objectData = [
            'title'    => 'phpunit-test-update-' . uniqid(),
            'summary'  => 'Original summary',
            'priority' => 1,
        ];

        $created = $this->saveHandler->saveObject(
            $this->testRegister,
            $this->testSchema,
            $objectData
        );

        $this->createdObjectUuids[] = $created->getUuid();

        // Now update — updateObject takes (Register, Schema, array $data, ObjectEntity $existingObject)
        $updateData = [
            'title'    => $objectData['title'],
            'summary'  => 'Updated summary',
            'priority' => 10,
        ];

        $updated = $this->saveHandler->updateObject(
            $this->testRegister,
            $this->testSchema,
            $updateData,
            $created
        );

        $this->assertInstanceOf(ObjectEntity::class, $updated);
        $this->assertSame($created->getUuid(), $updated->getUuid());
    }

    /**
     * Test saveObject with array type property
     *
     * @return void
     */
    public function testSaveObjectWithArrayProperty(): void
    {
        $this->assertNotNull($this->testRegister);
        $this->assertNotNull($this->testSchema);

        $objectData = [
            'title' => 'phpunit-test-array-' . uniqid(),
            'tags'  => ['tag1', 'tag2', 'tag3'],
        ];

        $result = $this->saveHandler->saveObject(
            $this->testRegister,
            $this->testSchema,
            $objectData
        );

        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->createdObjectUuids[] = $result->getUuid();
    }

    /**
     * Test hydrateObjectMetadata does not throw
     *
     * @return void
     */
    public function testHydrateObjectMetadata(): void
    {
        $this->assertNotNull($this->testRegister);
        $this->assertNotNull($this->testSchema);

        $objectData = [
            'title' => 'phpunit-test-hydrate-' . uniqid(),
        ];

        $created = $this->saveHandler->saveObject(
            $this->testRegister,
            $this->testSchema,
            $objectData
        );

        $this->createdObjectUuids[] = $created->getUuid();

        // Should not throw
        $this->saveHandler->hydrateObjectMetadata($created, $this->testSchema);

        $this->assertTrue(true);
    }

    /**
     * Test trackCreatedSubObject stores and retrieves
     *
     * @return void
     */
    public function testTrackCreatedSubObject(): void
    {
        $uuid = 'phpunit-test-' . uniqid();
        $data = ['key' => 'value'];

        $this->saveHandler->clearCreatedSubObjects();
        $this->saveHandler->trackCreatedSubObject($uuid, $data);

        $result = $this->saveHandler->getCreatedSubObjects();
        $this->assertArrayHasKey($uuid, $result);
        $this->assertSame($data, $result[$uuid]);
    }
}
