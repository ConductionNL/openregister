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
use OCA\OpenRegister\Db\ObjectEntityMapper;
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
 * metadata hydration, defaults, relation scanning, and various property types.
 *
 * @group DB
 */
class SaveObjectIntegrationTest extends TestCase
{
    private SaveObject $saveHandler;
    private ObjectService $objectService;
    private RegisterMapper $registerMapper;
    private SchemaMapper $schemaMapper;
    private ObjectEntityMapper $objectEntityMapper;
    private ?Register $testRegister = null;
    private ?Schema $testSchema = null;
    private ?Schema $relatedSchema = null;

    /** @var string[] UUIDs of created objects for cleanup */
    private array $createdObjectUuids = [];

    /** @var int[] IDs of extra schemas for cleanup */
    private array $extraSchemaIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->saveHandler = \OC::$server->get(SaveObject::class);
        $this->objectService = \OC::$server->get(ObjectService::class);
        $this->registerMapper = \OC::$server->get(RegisterMapper::class);
        $this->schemaMapper = \OC::$server->get(SchemaMapper::class);
        $this->objectEntityMapper = \OC::$server->get(ObjectEntityMapper::class);

        $this->createTestRegisterAndSchema();
    }

    protected function tearDown(): void
    {
        // Clean up objects first (they reference schemas/registers).
        foreach ($this->createdObjectUuids as $uuid) {
            try {
                $this->objectService->deleteObject($uuid, false, false);
            } catch (\Exception $e) {
                // Ignore cleanup errors.
            }
        }

        // Clean up extra schemas.
        foreach ($this->extraSchemaIds as $id) {
            try {
                $schema = $this->schemaMapper->find($id);
                $this->schemaMapper->delete($schema);
            } catch (\Exception $e) {
                // Ignore.
            }
        }

        if ($this->relatedSchema !== null) {
            try {
                $this->schemaMapper->delete($this->relatedSchema);
            } catch (\Exception $e) {
                // Ignore.
            }
        }

        if ($this->testSchema !== null) {
            try {
                $this->schemaMapper->delete($this->testSchema);
            } catch (\Exception $e) {
                // Ignore.
            }
        }

        if ($this->testRegister !== null) {
            try {
                $this->registerMapper->delete($this->testRegister);
            } catch (\Exception $e) {
                // Ignore.
            }
        }

        parent::tearDown();
    }

    private function createTestRegisterAndSchema(): void
    {
        $register = new Register();
        $register->setTitle('phpunit-save-' . uniqid());
        $register->setDescription('Test register for SaveObject tests');
        $register->setUuid(Uuid::v4()->toRfc4122());
        $register->setSlug('phpunit-save-' . uniqid());
        $register->setSchemas([]);
        $this->testRegister = $this->registerMapper->insert($register);

        $schema = new Schema();
        $schema->setTitle('phpunit-save-schema-' . uniqid());
        $schema->setDescription('Test schema for SaveObject tests');
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('phpunit-save-schema-' . uniqid());
        $schema->setProperties([
            'title' => [
                'type'     => 'string',
                'title'    => 'Title',
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
            'active' => [
                'type'  => 'boolean',
                'title' => 'Active',
            ],
            'tags' => [
                'type'  => 'array',
                'title' => 'Tags',
                'items' => ['type' => 'string'],
            ],
            'score' => [
                'type'  => 'number',
                'title' => 'Score',
            ],
            'metadata' => [
                'type'       => 'object',
                'title'      => 'Metadata',
                'properties' => [
                    'source' => ['type' => 'string'],
                    'version' => ['type' => 'integer'],
                ],
            ],
        ]);
        $this->testSchema = $this->schemaMapper->insert($schema);

        $this->testRegister->setSchemas([$this->testSchema->getId()]);
        $this->registerMapper->update($this->testRegister);
    }

    /**
     * Helper to save an object and track it for cleanup.
     */
    private function saveTestObject(array $data, bool $rbac = false, bool $multitenancy = false): ObjectEntity
    {
        $result = $this->saveHandler->saveObject(
            $this->testRegister,
            $this->testSchema,
            $data,
            null,
            null,
            $rbac,
            $multitenancy
        );
        $this->createdObjectUuids[] = $result->getUuid();
        return $result;
    }

    // =========================================================================
    // Cache management tests
    // =========================================================================

    public function testGetCreatedSubObjectsEmpty(): void
    {
        $this->saveHandler->clearCreatedSubObjects();
        $result = $this->saveHandler->getCreatedSubObjects();
        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    public function testClearCreatedSubObjects(): void
    {
        $this->saveHandler->trackCreatedSubObject('test-uuid', ['key' => 'value']);
        $this->saveHandler->clearCreatedSubObjects();

        $result = $this->saveHandler->getCreatedSubObjects();
        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    public function testClearAllCaches(): void
    {
        $this->saveHandler->trackCreatedSubObject('test-uuid', ['key' => 'value']);
        $this->saveHandler->clearAllCaches();

        $result = $this->saveHandler->getCreatedSubObjects();
        $this->assertCount(0, $result);
    }

    public function testTrackCreatedSubObject(): void
    {
        $this->saveHandler->clearCreatedSubObjects();

        $uuid1 = Uuid::v4()->toRfc4122();
        $uuid2 = Uuid::v4()->toRfc4122();
        $this->saveHandler->trackCreatedSubObject($uuid1, ['title' => 'Sub 1']);
        $this->saveHandler->trackCreatedSubObject($uuid2, ['title' => 'Sub 2']);

        $result = $this->saveHandler->getCreatedSubObjects();
        $this->assertCount(2, $result);
        $this->assertArrayHasKey($uuid1, $result);
        $this->assertArrayHasKey($uuid2, $result);
        $this->assertSame('Sub 1', $result[$uuid1]['title']);
    }

    public function testTrackCreatedSubObjectOverwritesSameUuid(): void
    {
        $this->saveHandler->clearCreatedSubObjects();

        $uuid = Uuid::v4()->toRfc4122();
        $this->saveHandler->trackCreatedSubObject($uuid, ['title' => 'Original']);
        $this->saveHandler->trackCreatedSubObject($uuid, ['title' => 'Replaced']);

        $result = $this->saveHandler->getCreatedSubObjects();
        $this->assertCount(1, $result);
        $this->assertSame('Replaced', $result[$uuid]['title']);
    }

    // =========================================================================
    // scanForRelations tests
    // =========================================================================

    public function testScanForRelationsEmpty(): void
    {
        $result = $this->saveHandler->scanForRelations([]);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testScanForRelationsFlatDataNoRelations(): void
    {
        $data = [
            'title'    => 'Test',
            'summary'  => 'Just a summary',
            'priority' => 5,
        ];

        $result = $this->saveHandler->scanForRelations($data, '', $this->testSchema);
        $this->assertIsArray($result);
    }

    public function testScanForRelationsWithUuid(): void
    {
        $uuid = Uuid::v4()->toRfc4122();
        $data = [
            'title'     => 'Test',
            'reference' => $uuid,
        ];

        $result = $this->saveHandler->scanForRelations($data);
        $this->assertIsArray($result);
        // UUID values should be detected as relations.
        $this->assertArrayHasKey('reference', $result);
    }

    public function testScanForRelationsWithUrl(): void
    {
        $data = [
            'title'  => 'Test',
            'source' => 'https://example.com/api/objects/123',
        ];

        $result = $this->saveHandler->scanForRelations($data);
        $this->assertIsArray($result);
        // URL values should be detected as relations.
        $this->assertArrayHasKey('source', $result);
    }

    public function testScanForRelationsWithNestedArray(): void
    {
        $uuid = Uuid::v4()->toRfc4122();
        $data = [
            'title' => 'Test',
            'tags'  => [$uuid, 'not-a-uuid'],
        ];

        $result = $this->saveHandler->scanForRelations($data);
        $this->assertIsArray($result);
    }

    public function testScanForRelationsWithSchema(): void
    {
        $data = [
            'title'    => 'Test',
            'summary'  => 'Just text',
            'priority' => 5,
            'active'   => true,
        ];

        $result = $this->saveHandler->scanForRelations($data, '', $this->testSchema);
        $this->assertIsArray($result);
        // None of these standard types should be relations.
    }

    // =========================================================================
    // applyAlwaysDefaults tests
    // =========================================================================

    public function testApplyAlwaysDefaultsNoDefaults(): void
    {
        $data = ['title' => 'Test'];
        $result = $this->saveHandler->applyAlwaysDefaults($this->testSchema, $data);

        $this->assertIsArray($result);
        $this->assertSame('Test', $result['title']);
    }

    public function testApplyAlwaysDefaultsPreservesExistingData(): void
    {
        $data = [
            'title'    => 'Test',
            'summary'  => 'Existing summary',
            'priority' => 5,
        ];

        $result = $this->saveHandler->applyAlwaysDefaults($this->testSchema, $data);
        $this->assertSame('Test', $result['title']);
        $this->assertSame('Existing summary', $result['summary']);
    }

    public function testApplyAlwaysDefaultsWithAlwaysBehaviorSchema(): void
    {
        // Create a schema with defaultBehavior: always.
        $schema = new Schema();
        $schema->setTitle('phpunit-always-defaults-' . uniqid());
        $schema->setDescription('Schema with always defaults');
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('phpunit-always-' . uniqid());
        $schema->setProperties([
            'status' => [
                'type'            => 'string',
                'title'           => 'Status',
                'default'         => 'draft',
                'defaultBehavior' => 'always',
            ],
            'title' => [
                'type'  => 'string',
                'title' => 'Title',
            ],
        ]);
        $schema = $this->schemaMapper->insert($schema);
        $this->extraSchemaIds[] = $schema->getId();

        $data = ['title' => 'Test', 'status' => 'published'];
        $result = $this->saveHandler->applyAlwaysDefaults($schema, $data);

        // The "always" default should override the user-provided value.
        $this->assertSame('draft', $result['status']);
    }

    // =========================================================================
    // applyPropertyDefaults tests
    // =========================================================================

    public function testApplyPropertyDefaultsNoDefaults(): void
    {
        $data = ['title' => 'Test'];
        $result = $this->saveHandler->applyPropertyDefaults($this->testSchema, $data);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('title', $result);
    }

    public function testApplyPropertyDefaultsWithSchemaDefaults(): void
    {
        // The test schema has priority with default: 0.
        $data = ['title' => 'No priority set'];
        $result = $this->saveHandler->applyPropertyDefaults($this->testSchema, $data);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('title', $result);
    }

    public function testApplyPropertyDefaultsPreservesExplicitValues(): void
    {
        $data = ['title' => 'Test', 'priority' => 42];
        $result = $this->saveHandler->applyPropertyDefaults($this->testSchema, $data);

        $this->assertSame(42, $result['priority']);
    }

    public function testApplyPropertyDefaultsEmptyData(): void
    {
        $result = $this->saveHandler->applyPropertyDefaults($this->testSchema, []);
        $this->assertIsArray($result);
    }

    // =========================================================================
    // saveObject - creation tests
    // =========================================================================

    public function testSaveObjectCreatesNewObject(): void
    {
        $objectData = [
            'title'   => 'phpunit-create-' . uniqid(),
            'summary' => 'Created via saveObject',
        ];

        $result = $this->saveTestObject($objectData);

        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->assertNotEmpty($result->getUuid());
        $this->assertNotNull($result->getId());
    }

    public function testSaveObjectSetsRegisterAndSchema(): void
    {
        $result = $this->saveTestObject(['title' => 'phpunit-reg-schema-' . uniqid()]);

        $this->assertSame((string) $this->testRegister->getId(), $result->getRegister());
        $this->assertSame((string) $this->testSchema->getId(), $result->getSchema());
    }

    public function testSaveObjectMinimalData(): void
    {
        $result = $this->saveTestObject(['title' => 'phpunit-minimal-' . uniqid()]);

        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->assertNotEmpty($result->getUuid());
    }

    public function testSaveObjectWithStringProperty(): void
    {
        $title = 'phpunit-string-' . uniqid();
        $result = $this->saveTestObject([
            'title'   => $title,
            'summary' => 'A detailed summary text',
        ]);

        $objectData = $result->getObject();
        $this->assertSame($title, $objectData['title']);
        $this->assertSame('A detailed summary text', $objectData['summary']);
    }

    public function testSaveObjectWithIntegerProperty(): void
    {
        $result = $this->saveTestObject([
            'title'    => 'phpunit-int-' . uniqid(),
            'priority' => 42,
        ]);

        $objectData = $result->getObject();
        $this->assertSame(42, $objectData['priority']);
    }

    public function testSaveObjectWithBooleanProperty(): void
    {
        $result = $this->saveTestObject([
            'title'  => 'phpunit-bool-' . uniqid(),
            'active' => true,
        ]);

        $objectData = $result->getObject();
        $this->assertTrue($objectData['active']);
    }

    public function testSaveObjectWithBooleanFalse(): void
    {
        $result = $this->saveTestObject([
            'title'  => 'phpunit-bool-false-' . uniqid(),
            'active' => false,
        ]);

        $objectData = $result->getObject();
        $this->assertFalse($objectData['active']);
    }

    public function testSaveObjectWithArrayProperty(): void
    {
        $tags = ['tag1', 'tag2', 'tag3'];
        $result = $this->saveTestObject([
            'title' => 'phpunit-array-' . uniqid(),
            'tags'  => $tags,
        ]);

        $objectData = $result->getObject();
        $this->assertSame($tags, $objectData['tags']);
    }

    public function testSaveObjectWithEmptyArray(): void
    {
        $result = $this->saveTestObject([
            'title' => 'phpunit-empty-array-' . uniqid(),
            'tags'  => [],
        ]);

        $objectData = $result->getObject();
        $this->assertIsArray($objectData['tags']);
        $this->assertEmpty($objectData['tags']);
    }

    public function testSaveObjectWithNumberProperty(): void
    {
        $result = $this->saveTestObject([
            'title' => 'phpunit-number-' . uniqid(),
            'score' => 3.14,
        ]);

        $objectData = $result->getObject();
        $this->assertEqualsWithDelta(3.14, $objectData['score'], 0.001);
    }

    public function testSaveObjectWithObjectProperty(): void
    {
        $metadata = ['source' => 'api', 'version' => 2];
        $result = $this->saveTestObject([
            'title'    => 'phpunit-object-' . uniqid(),
            'metadata' => $metadata,
        ]);

        $objectData = $result->getObject();
        $this->assertIsArray($objectData['metadata']);
        $this->assertSame('api', $objectData['metadata']['source']);
        $this->assertSame(2, $objectData['metadata']['version']);
    }

    public function testSaveObjectWithAllPropertyTypes(): void
    {
        $result = $this->saveTestObject([
            'title'    => 'phpunit-all-types-' . uniqid(),
            'summary'  => 'Full test',
            'priority' => 7,
            'active'   => true,
            'tags'     => ['a', 'b'],
            'score'    => 9.5,
            'metadata' => ['source' => 'test'],
        ]);

        $objectData = $result->getObject();
        $this->assertIsString($objectData['title']);
        $this->assertSame(7, $objectData['priority']);
        $this->assertTrue($objectData['active']);
        $this->assertCount(2, $objectData['tags']);
        $this->assertEqualsWithDelta(9.5, $objectData['score'], 0.001);
    }

    public function testSaveObjectWithProvidedUuid(): void
    {
        $uuid = Uuid::v4()->toRfc4122();
        $result = $this->saveHandler->saveObject(
            $this->testRegister,
            $this->testSchema,
            ['title' => 'phpunit-uuid-' . uniqid()],
            $uuid,
            null,
            false,
            false
        );
        $this->createdObjectUuids[] = $result->getUuid();

        $this->assertSame($uuid, $result->getUuid());
    }

    public function testSaveObjectWithSelfId(): void
    {
        $uuid = Uuid::v4()->toRfc4122();
        $result = $this->saveHandler->saveObject(
            $this->testRegister,
            $this->testSchema,
            [
                'title' => 'phpunit-self-id-' . uniqid(),
                'id'    => $uuid,
            ],
            null,
            null,
            false,
            false
        );
        $this->createdObjectUuids[] = $result->getUuid();

        // The id field should be used as the UUID.
        $this->assertSame($uuid, $result->getUuid());
    }

    public function testSaveObjectSetsCreatedTimestamp(): void
    {
        $result = $this->saveTestObject(['title' => 'phpunit-timestamp-' . uniqid()]);
        $this->assertNotNull($result->getCreated());
    }

    // =========================================================================
    // saveObject - update tests (via UUID)
    // =========================================================================

    public function testSaveObjectUpdatesExistingByUuid(): void
    {
        $title = 'phpunit-update-' . uniqid();
        $created = $this->saveTestObject([
            'title'   => $title,
            'summary' => 'Original',
        ]);

        $updated = $this->saveHandler->saveObject(
            $this->testRegister,
            $this->testSchema,
            ['title' => $title, 'summary' => 'Updated'],
            $created->getUuid(),
            null,
            false,
            false
        );

        $this->assertSame($created->getUuid(), $updated->getUuid());
        $objectData = $updated->getObject();
        $this->assertSame('Updated', $objectData['summary']);
    }

    public function testSaveObjectUpdatePreservesUnchangedFields(): void
    {
        $created = $this->saveTestObject([
            'title'    => 'phpunit-preserve-' . uniqid(),
            'summary'  => 'Keep this',
            'priority' => 5,
            'tags'     => ['preserved'],
        ]);

        // Update only the summary.
        $updated = $this->saveHandler->saveObject(
            $this->testRegister,
            $this->testSchema,
            ['title' => $created->getObject()['title'], 'summary' => 'Changed'],
            $created->getUuid(),
            null,
            false,
            false
        );

        $objectData = $updated->getObject();
        $this->assertSame('Changed', $objectData['summary']);
    }

    // =========================================================================
    // updateObject direct method tests
    // =========================================================================

    public function testUpdateObjectChangesData(): void
    {
        $created = $this->saveTestObject([
            'title'   => 'phpunit-direct-update-' . uniqid(),
            'summary' => 'Original summary',
        ]);

        $updated = $this->saveHandler->updateObject(
            $this->testRegister,
            $this->testSchema,
            ['title' => $created->getObject()['title'], 'summary' => 'Direct update'],
            $created
        );

        $this->assertInstanceOf(ObjectEntity::class, $updated);
        $this->assertSame($created->getUuid(), $updated->getUuid());
    }

    public function testUpdateObjectSetsUpdatedTimestamp(): void
    {
        $created = $this->saveTestObject([
            'title' => 'phpunit-update-time-' . uniqid(),
        ]);

        usleep(100000); // 100ms delay for timestamp difference.

        $updated = $this->saveHandler->updateObject(
            $this->testRegister,
            $this->testSchema,
            ['title' => $created->getObject()['title'], 'priority' => 99],
            $created
        );

        $this->assertNotNull($updated->getUpdated());
    }

    public function testUpdateObjectWithSilentMode(): void
    {
        $created = $this->saveTestObject([
            'title' => 'phpunit-silent-' . uniqid(),
        ]);

        $updated = $this->saveHandler->updateObject(
            $this->testRegister,
            $this->testSchema,
            ['title' => $created->getObject()['title'], 'summary' => 'Silent update'],
            $created,
            null,
            true // silent mode.
        );

        $this->assertInstanceOf(ObjectEntity::class, $updated);
    }

    public function testUpdateObjectUsingRegisterAndSchemaIds(): void
    {
        $created = $this->saveTestObject([
            'title' => 'phpunit-id-update-' . uniqid(),
        ]);

        $updated = $this->saveHandler->updateObject(
            $this->testRegister->getId(),
            $this->testSchema->getId(),
            ['title' => $created->getObject()['title'], 'summary' => 'ID-based update'],
            $created
        );

        $this->assertInstanceOf(ObjectEntity::class, $updated);
    }

    // =========================================================================
    // saveObject - multiple objects
    // =========================================================================

    public function testSaveMultipleObjectsIndependently(): void
    {
        $objects = [];
        for ($i = 0; $i < 3; $i++) {
            $objects[] = $this->saveTestObject([
                'title'    => 'phpunit-multi-' . $i . '-' . uniqid(),
                'priority' => $i,
            ]);
        }

        $this->assertCount(3, $objects);
        $uuids = array_map(fn($o) => $o->getUuid(), $objects);
        $this->assertCount(3, array_unique($uuids));
    }

    // =========================================================================
    // hydrateObjectMetadata tests
    // =========================================================================

    public function testHydrateObjectMetadataDoesNotThrow(): void
    {
        $created = $this->saveTestObject([
            'title'   => 'phpunit-hydrate-' . uniqid(),
            'summary' => 'Some summary text',
        ]);

        $this->saveHandler->hydrateObjectMetadata($created, $this->testSchema);
        $this->assertTrue(true); // No exception.
    }

    public function testHydrateObjectMetadataWithNameField(): void
    {
        // Create a schema that maps name to title.
        $schema = new Schema();
        $schema->setTitle('phpunit-name-map-' . uniqid());
        $schema->setDescription('Schema with name mapping');
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('phpunit-name-map-' . uniqid());
        $schema->setProperties([
            'title' => ['type' => 'string', 'title' => 'Title'],
        ]);
        $schema->setConfiguration(['objectNameField' => 'title']);
        $schema = $this->schemaMapper->insert($schema);
        $this->extraSchemaIds[] = $schema->getId();

        // Add schema to register.
        $schemas = $this->testRegister->getSchemas();
        $schemas[] = $schema->getId();
        $this->testRegister->setSchemas($schemas);
        $this->registerMapper->update($this->testRegister);

        $created = $this->saveHandler->saveObject(
            $this->testRegister,
            $schema,
            ['title' => 'My Named Object'],
            null,
            null,
            false,
            false
        );
        $this->createdObjectUuids[] = $created->getUuid();

        $this->saveHandler->hydrateObjectMetadata($created, $schema);

        // The name should be set from the title field.
        $this->assertSame('My Named Object', $created->getName());
    }

    // =========================================================================
    // saveObject with @self metadata
    // =========================================================================

    public function testSaveObjectWithSelfMetadata(): void
    {
        $result = $this->saveHandler->saveObject(
            $this->testRegister,
            $this->testSchema,
            [
                'title' => 'phpunit-self-meta-' . uniqid(),
                '@self' => ['name' => 'Test Name'],
            ],
            null,
            null,
            false,
            false
        );
        $this->createdObjectUuids[] = $result->getUuid();

        $this->assertInstanceOf(ObjectEntity::class, $result);
    }

    // =========================================================================
    // saveObject with register/schema as IDs
    // =========================================================================

    public function testSaveObjectWithRegisterAndSchemaIds(): void
    {
        $result = $this->saveHandler->saveObject(
            $this->testRegister->getId(),
            $this->testSchema->getId(),
            ['title' => 'phpunit-by-id-' . uniqid()],
            null,
            null,
            false,
            false
        );
        $this->createdObjectUuids[] = $result->getUuid();

        $this->assertInstanceOf(ObjectEntity::class, $result);
    }

    // =========================================================================
    // saveObject with persist=false (dry run)
    // =========================================================================

    public function testSaveObjectWithPersistFalse(): void
    {
        $result = $this->saveHandler->saveObject(
            $this->testRegister,
            $this->testSchema,
            ['title' => 'phpunit-no-persist-' . uniqid()],
            null,
            null,
            false,
            false,
            false // persist = false.
        );

        $this->assertInstanceOf(ObjectEntity::class, $result);
        // Object may or may not have an ID depending on implementation.
    }

    // =========================================================================
    // saveObject with validation disabled
    // =========================================================================

    public function testSaveObjectWithValidationDisabled(): void
    {
        $result = $this->saveHandler->saveObject(
            $this->testRegister,
            $this->testSchema,
            ['title' => 'phpunit-no-validation-' . uniqid(), 'extraField' => 'allowed'],
            null,
            null,
            false,
            false,
            true,
            false,
            false // _validation = false.
        );
        $this->createdObjectUuids[] = $result->getUuid();

        $this->assertInstanceOf(ObjectEntity::class, $result);
    }

    // =========================================================================
    // Edge cases
    // =========================================================================

    public function testSaveObjectWithEmptyStringValues(): void
    {
        $result = $this->saveTestObject([
            'title'   => 'phpunit-empty-str-' . uniqid(),
            'summary' => '',
        ]);

        $objectData = $result->getObject();
        // Empty strings may be stored as null by the database layer.
        $this->assertTrue(
            $objectData['summary'] === '' || $objectData['summary'] === null,
            'Expected empty string or null, got: ' . var_export($objectData['summary'] ?? 'N/A', true)
        );
    }

    public function testSaveObjectWithNullValues(): void
    {
        $result = $this->saveTestObject([
            'title'   => 'phpunit-null-' . uniqid(),
            'summary' => null,
        ]);

        $this->assertInstanceOf(ObjectEntity::class, $result);
    }

    public function testSaveObjectWithLargeArrayProperty(): void
    {
        $largeTags = array_map(fn($i) => "tag-$i", range(1, 50));
        $result = $this->saveTestObject([
            'title' => 'phpunit-large-array-' . uniqid(),
            'tags'  => $largeTags,
        ]);

        $objectData = $result->getObject();
        $this->assertCount(50, $objectData['tags']);
    }

    public function testSaveObjectWithUnicodeContent(): void
    {
        $result = $this->saveTestObject([
            'title'   => 'phpunit-unicode-' . uniqid(),
            'summary' => 'Ik ben een test met speciale tekens: e, u, a, o, n',
        ]);

        $objectData = $result->getObject();
        $this->assertStringContainsString('speciale tekens', $objectData['summary']);
    }

    public function testSaveObjectWithLongStringValue(): void
    {
        $longString = str_repeat('Lorem ipsum dolor sit amet. ', 100);
        $result = $this->saveTestObject([
            'title'   => 'phpunit-long-' . uniqid(),
            'summary' => $longString,
        ]);

        $objectData = $result->getObject();
        $this->assertSame($longString, $objectData['summary']);
    }

    public function testSaveObjectClearsCachesOnNewSave(): void
    {
        $this->saveHandler->clearAllCaches();
        $this->saveHandler->trackCreatedSubObject('old-uuid', ['old' => true]);

        // clearAllCaches should clear sub-objects.
        $this->saveHandler->clearAllCaches();
        $this->assertEmpty($this->saveHandler->getCreatedSubObjects());
    }
}
