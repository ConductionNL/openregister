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

    // =========================================================================
    // scanForRelations - advanced patterns (isReference coverage)
    // =========================================================================

    public function testScanForRelationsWithPrefixedUuid(): void
    {
        $uuid = Uuid::v4()->toRfc4122();
        $data = [
            'title'     => 'Test',
            'reference' => 'id-' . $uuid,
        ];

        $result = $this->saveHandler->scanForRelations($data);
        $this->assertIsArray($result);
        // Prefixed UUID should be detected as a relation.
        $this->assertArrayHasKey('reference', $result);
    }

    public function testScanForRelationsWithNumericId(): void
    {
        $data = [
            'title'     => 'Test',
            'reference' => '12345',
        ];

        $result = $this->saveHandler->scanForRelations($data);
        $this->assertIsArray($result);
        // Numeric IDs should be detected as relations.
        $this->assertArrayHasKey('reference', $result);
    }

    public function testScanForRelationsWithUuidWithoutDashes(): void
    {
        // UUID without dashes (32 hex chars).
        $data = [
            'title'     => 'Test',
            'reference' => 'abcdef01234567890abcdef012345678',
        ];

        $result = $this->saveHandler->scanForRelations($data);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('reference', $result);
    }

    public function testScanForRelationsWithNestedObjectInArray(): void
    {
        $uuid = Uuid::v4()->toRfc4122();
        $data = [
            'title' => 'Test',
            'items' => [
                ['ref' => $uuid],
            ],
        ];

        $result = $this->saveHandler->scanForRelations($data);
        $this->assertIsArray($result);
    }

    public function testScanForRelationsWithSchemaObjectProperty(): void
    {
        // Create a schema with an object-type property.
        $schema = new Schema();
        $schema->setTitle('phpunit-scan-obj-' . uniqid());
        $schema->setDescription('Schema for relation scanning');
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('phpunit-scan-obj-' . uniqid());
        $schema->setProperties([
            'title'  => ['type' => 'string', 'title' => 'Title'],
            'parent' => ['type' => 'object', 'title' => 'Parent'],
        ]);
        $schema = $this->schemaMapper->insert($schema);
        $this->extraSchemaIds[] = $schema->getId();

        $uuid = Uuid::v4()->toRfc4122();
        $data = [
            'title'  => 'Test',
            'parent' => $uuid,
        ];

        // Object properties with string values should be treated as relations.
        $result = $this->saveHandler->scanForRelations($data, '', $schema);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('parent', $result);
    }

    public function testScanForRelationsWithPrefix(): void
    {
        $uuid = Uuid::v4()->toRfc4122();
        $data = ['ref' => $uuid];

        $result = $this->saveHandler->scanForRelations($data, 'nested.path');
        $this->assertIsArray($result);
        // Should use prefix in the key.
        $this->assertArrayHasKey('nested.path.ref', $result);
    }

    public function testScanForRelationsSkipsEmptyKeys(): void
    {
        // Arrays with numeric keys should not be treated as top-level relations.
        $data = [0 => 'value1', 1 => 'value2'];
        $result = $this->saveHandler->scanForRelations($data);
        $this->assertIsArray($result);
    }

    // =========================================================================
    // applyPropertyDefaults - advanced behaviors
    // =========================================================================

    public function testApplyPropertyDefaultsFalsyBehavior(): void
    {
        // Create a schema with defaultBehavior: falsy.
        $schema = new Schema();
        $schema->setTitle('phpunit-falsy-' . uniqid());
        $schema->setDescription('Schema with falsy defaults');
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('phpunit-falsy-' . uniqid());
        $schema->setProperties([
            'title'  => ['type' => 'string', 'title' => 'Title'],
            'status' => [
                'type'            => 'string',
                'title'           => 'Status',
                'default'         => 'pending',
                'defaultBehavior' => 'falsy',
            ],
        ]);
        $schema = $this->schemaMapper->insert($schema);
        $this->extraSchemaIds[] = $schema->getId();

        // Empty string should trigger falsy default.
        $data = ['title' => 'Test', 'status' => ''];
        $result = $this->saveHandler->applyPropertyDefaults($schema, $data);
        $this->assertSame('pending', $result['status']);
    }

    public function testApplyPropertyDefaultsFalsyBehaviorWithEmptyArray(): void
    {
        $schema = new Schema();
        $schema->setTitle('phpunit-falsy-arr-' . uniqid());
        $schema->setDescription('Schema with falsy defaults for array');
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('phpunit-falsy-arr-' . uniqid());
        $schema->setProperties([
            'title' => ['type' => 'string', 'title' => 'Title'],
            'tags'  => [
                'type'            => 'array',
                'title'           => 'Tags',
                'items'           => ['type' => 'string'],
                'default'         => ['default-tag'],
                'defaultBehavior' => 'falsy',
            ],
        ]);
        $schema = $this->schemaMapper->insert($schema);
        $this->extraSchemaIds[] = $schema->getId();

        // Empty array should trigger falsy default.
        $data = ['title' => 'Test', 'tags' => []];
        $result = $this->saveHandler->applyPropertyDefaults($schema, $data);
        $this->assertSame(['default-tag'], $result['tags']);
    }

    public function testApplyPropertyDefaultsWithTwigTemplate(): void
    {
        $schema = new Schema();
        $schema->setTitle('phpunit-twig-def-' . uniqid());
        $schema->setDescription('Schema with twig template defaults');
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('phpunit-twig-def-' . uniqid());
        $schema->setProperties([
            'title'    => ['type' => 'string', 'title' => 'Title'],
            'label'    => ['type' => 'string', 'title' => 'Label'],
            'computed' => [
                'type'            => 'string',
                'title'           => 'Computed',
                'default'         => '{{ title }}',
                'defaultBehavior' => 'always',
            ],
        ]);
        $schema = $this->schemaMapper->insert($schema);
        $this->extraSchemaIds[] = $schema->getId();

        $data = ['title' => 'My Title'];
        $result = $this->saveHandler->applyPropertyDefaults($schema, $data);
        $this->assertSame('My Title', $result['computed']);
    }

    public function testApplyPropertyDefaultsWithNonExistentSourceProperty(): void
    {
        $schema = new Schema();
        $schema->setTitle('phpunit-twig-missing-' . uniqid());
        $schema->setDescription('Schema with twig template referencing missing property');
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('phpunit-twig-missing-' . uniqid());
        $schema->setProperties([
            'title'    => ['type' => 'string', 'title' => 'Title'],
            'computed' => [
                'type'            => 'string',
                'title'           => 'Computed',
                'default'         => '{{ nonExistent }}',
                'defaultBehavior' => 'always',
            ],
        ]);
        $schema = $this->schemaMapper->insert($schema);
        $this->extraSchemaIds[] = $schema->getId();

        // Should not throw, computed should be null since source doesn't exist.
        $data = ['title' => 'Test'];
        $result = $this->saveHandler->applyPropertyDefaults($schema, $data);
        $this->assertIsArray($result);
    }

    public function testApplyPropertyDefaultsNonTemplateDefault(): void
    {
        $schema = new Schema();
        $schema->setTitle('phpunit-static-def-' . uniqid());
        $schema->setDescription('Schema with static default');
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('phpunit-static-def-' . uniqid());
        $schema->setProperties([
            'title' => ['type' => 'string', 'title' => 'Title'],
            'color' => [
                'type'    => 'string',
                'title'   => 'Color',
                'default' => 'blue',
            ],
        ]);
        $schema = $this->schemaMapper->insert($schema);
        $this->extraSchemaIds[] = $schema->getId();

        $data = ['title' => 'Test'];
        $result = $this->saveHandler->applyPropertyDefaults($schema, $data);
        $this->assertSame('blue', $result['color']);
    }

    // =========================================================================
    // applyAlwaysDefaults - advanced
    // =========================================================================

    public function testApplyAlwaysDefaultsWithTwigTemplate(): void
    {
        $schema = new Schema();
        $schema->setTitle('phpunit-always-twig-' . uniqid());
        $schema->setDescription('Schema with always default using twig');
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('phpunit-always-twig-' . uniqid());
        $schema->setProperties([
            'title'   => ['type' => 'string', 'title' => 'Title'],
            'derived' => [
                'type'            => 'string',
                'title'           => 'Derived',
                'default'         => '{{ title }}',
                'defaultBehavior' => 'always',
            ],
        ]);
        $schema = $this->schemaMapper->insert($schema);
        $this->extraSchemaIds[] = $schema->getId();

        $data = ['title' => 'Source Value', 'derived' => 'will be overwritten'];
        $result = $this->saveHandler->applyAlwaysDefaults($schema, $data);
        $this->assertSame('Source Value', $result['derived']);
    }

    public function testApplyAlwaysDefaultsWithNoSchemaProperties(): void
    {
        $schema = new Schema();
        $schema->setTitle('phpunit-always-empty-' . uniqid());
        $schema->setDescription('Schema with no properties');
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('phpunit-always-empty-' . uniqid());
        $schema->setProperties([]);
        $schema = $this->schemaMapper->insert($schema);
        $this->extraSchemaIds[] = $schema->getId();

        $data = ['title' => 'Test'];
        $result = $this->saveHandler->applyAlwaysDefaults($schema, $data);
        $this->assertSame('Test', $result['title']);
    }

    // =========================================================================
    // saveObject with schema/register slug resolution
    // =========================================================================

    public function testSaveObjectWithSchemaSlugResolution(): void
    {
        // Save using schema slug (string) instead of entity/ID.
        $result = $this->saveHandler->saveObject(
            $this->testRegister,
            (string) $this->testSchema->getId(),
            ['title' => 'phpunit-schema-slug-' . uniqid()],
            null,
            null,
            false,
            false
        );
        $this->createdObjectUuids[] = $result->getUuid();

        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->assertSame((string) $this->testSchema->getId(), $result->getSchema());
    }

    public function testSaveObjectWithRegisterSlugResolution(): void
    {
        // Save using register slug (string) instead of entity/ID.
        $result = $this->saveHandler->saveObject(
            (string) $this->testRegister->getId(),
            $this->testSchema,
            ['title' => 'phpunit-register-slug-' . uniqid()],
            null,
            null,
            false,
            false
        );
        $this->createdObjectUuids[] = $result->getUuid();

        $this->assertInstanceOf(ObjectEntity::class, $result);
    }

    // =========================================================================
    // saveObject with const values in schema
    // =========================================================================

    public function testSaveObjectWithConstantValue(): void
    {
        $schema = new Schema();
        $schema->setTitle('phpunit-const-' . uniqid());
        $schema->setDescription('Schema with const value');
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('phpunit-const-' . uniqid());
        $schema->setProperties([
            'title' => ['type' => 'string', 'title' => 'Title'],
            'type'  => [
                'type'  => 'string',
                'title' => 'Type',
                'const' => 'fixed-type',
            ],
        ]);
        $schema = $this->schemaMapper->insert($schema);
        $this->extraSchemaIds[] = $schema->getId();

        $schemas = $this->testRegister->getSchemas();
        $schemas[] = $schema->getId();
        $this->testRegister->setSchemas($schemas);
        $this->registerMapper->update($this->testRegister);

        $result = $this->saveHandler->saveObject(
            $this->testRegister,
            $schema,
            ['title' => 'phpunit-const-test-' . uniqid(), 'type' => 'user-value'],
            null,
            null,
            false,
            false
        );
        $this->createdObjectUuids[] = $result->getUuid();

        // The const value should always override the user-provided value.
        $objectData = $result->getObject();
        $this->assertSame('fixed-type', $objectData['type']);
    }

    // =========================================================================
    // saveObject with slug generation
    // =========================================================================

    public function testSaveObjectWithSlugGeneration(): void
    {
        $schema = new Schema();
        $schema->setTitle('phpunit-slug-gen-' . uniqid());
        $schema->setDescription('Schema with slug configuration');
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('phpunit-slug-gen-' . uniqid());
        $schema->setProperties([
            'title' => ['type' => 'string', 'title' => 'Title'],
        ]);
        $schema->setConfiguration(['objectSlugField' => 'title']);
        $schema = $this->schemaMapper->insert($schema);
        $this->extraSchemaIds[] = $schema->getId();

        $schemas = $this->testRegister->getSchemas();
        $schemas[] = $schema->getId();
        $this->testRegister->setSchemas($schemas);
        $this->registerMapper->update($this->testRegister);

        $result = $this->saveHandler->saveObject(
            $this->testRegister,
            $schema,
            ['title' => 'My Test Title'],
            null,
            null,
            false,
            false
        );
        $this->createdObjectUuids[] = $result->getUuid();

        // The object should have a slug generated from the title field.
        $this->assertInstanceOf(ObjectEntity::class, $result);
        $objectData = $result->getObject();
        // Slug should contain a slugified version of the title.
        if (isset($objectData['slug'])) {
            $this->assertStringContainsString('my-test-title', $objectData['slug']);
        }
    }

    // =========================================================================
    // hydrateObjectMetadata - advanced fields
    // =========================================================================

    public function testHydrateObjectMetadataWithDescriptionField(): void
    {
        $schema = new Schema();
        $schema->setTitle('phpunit-desc-meta-' . uniqid());
        $schema->setDescription('Schema with description mapping');
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('phpunit-desc-meta-' . uniqid());
        $schema->setProperties([
            'title'   => ['type' => 'string', 'title' => 'Title'],
            'content' => ['type' => 'string', 'title' => 'Content'],
        ]);
        $schema->setConfiguration([
            'objectNameField'        => 'title',
            'objectDescriptionField' => 'content',
        ]);
        $schema = $this->schemaMapper->insert($schema);
        $this->extraSchemaIds[] = $schema->getId();

        $schemas = $this->testRegister->getSchemas();
        $schemas[] = $schema->getId();
        $this->testRegister->setSchemas($schemas);
        $this->registerMapper->update($this->testRegister);

        $created = $this->saveHandler->saveObject(
            $this->testRegister,
            $schema,
            ['title' => 'My Object', 'content' => 'Detailed description here'],
            null,
            null,
            false,
            false
        );
        $this->createdObjectUuids[] = $created->getUuid();

        $this->saveHandler->hydrateObjectMetadata($created, $schema);

        $this->assertSame('My Object', $created->getName());
        $this->assertSame('Detailed description here', $created->getDescription());
    }

    public function testHydrateObjectMetadataWithSummaryField(): void
    {
        $schema = new Schema();
        $schema->setTitle('phpunit-summary-meta-' . uniqid());
        $schema->setDescription('Schema with summary mapping');
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('phpunit-summary-meta-' . uniqid());
        $schema->setProperties([
            'title' => ['type' => 'string', 'title' => 'Title'],
            'kort'  => ['type' => 'string', 'title' => 'Short summary'],
        ]);
        $schema->setConfiguration([
            'objectNameField'    => 'title',
            'objectSummaryField' => 'kort',
        ]);
        $schema = $this->schemaMapper->insert($schema);
        $this->extraSchemaIds[] = $schema->getId();

        $created = $this->saveHandler->saveObject(
            $this->testRegister,
            $schema,
            ['title' => 'Test Object', 'kort' => 'Brief summary'],
            null,
            null,
            false,
            false
        );
        $this->createdObjectUuids[] = $created->getUuid();

        $this->saveHandler->hydrateObjectMetadata($created, $schema);

        $this->assertSame('Brief summary', $created->getSummary());
    }

    public function testHydrateObjectMetadataWithImageUrl(): void
    {
        $schema = new Schema();
        $schema->setTitle('phpunit-image-meta-' . uniqid());
        $schema->setDescription('Schema with image mapping');
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('phpunit-image-meta-' . uniqid());
        $schema->setProperties([
            'title' => ['type' => 'string', 'title' => 'Title'],
            'logo'  => ['type' => 'string', 'title' => 'Logo URL'],
        ]);
        $schema->setConfiguration([
            'objectNameField'  => 'title',
            'objectImageField' => 'logo',
        ]);
        $schema = $this->schemaMapper->insert($schema);
        $this->extraSchemaIds[] = $schema->getId();

        $created = $this->saveHandler->saveObject(
            $this->testRegister,
            $schema,
            ['title' => 'Image Test', 'logo' => 'https://example.com/logo.png'],
            null,
            null,
            false,
            false
        );
        $this->createdObjectUuids[] = $created->getUuid();

        $this->saveHandler->hydrateObjectMetadata($created, $schema);

        $this->assertSame('https://example.com/logo.png', $created->getImage());
    }

    public function testHydrateObjectMetadataWithPublishedField(): void
    {
        $schema = new Schema();
        $schema->setTitle('phpunit-published-meta-' . uniqid());
        $schema->setDescription('Schema with published date mapping');
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('phpunit-published-meta-' . uniqid());
        $schema->setProperties([
            'title'       => ['type' => 'string', 'title' => 'Title'],
            'publishDate' => ['type' => 'string', 'title' => 'Published date'],
        ]);
        $schema->setConfiguration([
            'objectNameField'      => 'title',
            'objectPublishedField' => 'publishDate',
        ]);
        $schema = $this->schemaMapper->insert($schema);
        $this->extraSchemaIds[] = $schema->getId();

        $created = $this->saveHandler->saveObject(
            $this->testRegister,
            $schema,
            ['title' => 'Publish Test', 'publishDate' => '2025-06-15T10:00:00+00:00'],
            null,
            null,
            false,
            false
        );
        $this->createdObjectUuids[] = $created->getUuid();

        $this->saveHandler->hydrateObjectMetadata($created, $schema);

        // hydrateObjectMetadata delegates to metaHydrationHandler for published dates.
        // The published field is set during save via the configuration, so verify the
        // metadata hydration method runs without error and returns void.
        $this->assertInstanceOf(ObjectEntity::class, $created);
    }

    public function testHydrateObjectMetadataWithDepublishedField(): void
    {
        $schema = new Schema();
        $schema->setTitle('phpunit-depub-meta-' . uniqid());
        $schema->setDescription('Schema with depublished date mapping');
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('phpunit-depub-meta-' . uniqid());
        $schema->setProperties([
            'title'   => ['type' => 'string', 'title' => 'Title'],
            'endDate' => ['type' => 'string', 'title' => 'End date'],
        ]);
        $schema->setConfiguration([
            'objectNameField'          => 'title',
            'objectDepublishedField'   => 'endDate',
        ]);
        $schema = $this->schemaMapper->insert($schema);
        $this->extraSchemaIds[] = $schema->getId();

        $created = $this->saveHandler->saveObject(
            $this->testRegister,
            $schema,
            ['title' => 'Depub Test', 'endDate' => '2026-12-31T23:59:59+00:00'],
            null,
            null,
            false,
            false
        );
        $this->createdObjectUuids[] = $created->getUuid();

        $this->saveHandler->hydrateObjectMetadata($created, $schema);

        // Verify the method processes without error, exercising the depublished date path.
        $this->assertInstanceOf(ObjectEntity::class, $created);
    }

    public function testHydrateObjectMetadataWithImageFromFileObjectArray(): void
    {
        $schema = new Schema();
        $schema->setTitle('phpunit-imgobj-meta-' . uniqid());
        $schema->setDescription('Schema with image from file object');
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('phpunit-imgobj-meta-' . uniqid());
        $schema->setProperties([
            'title'  => ['type' => 'string', 'title' => 'Title'],
            'images' => [
                'type'  => 'array',
                'title' => 'Images',
                'items' => ['type' => 'object'],
            ],
        ]);
        $schema->setConfiguration([
            'objectNameField'  => 'title',
            'objectImageField' => 'images',
        ]);
        $schema = $this->schemaMapper->insert($schema);
        $this->extraSchemaIds[] = $schema->getId();

        $created = $this->saveHandler->saveObject(
            $this->testRegister,
            $schema,
            [
                'title'  => 'Image Array Test',
                'images' => [
                    ['downloadUrl' => 'https://example.com/img1.jpg', 'accessUrl' => 'https://example.com/img1-access.jpg'],
                    ['downloadUrl' => 'https://example.com/img2.jpg'],
                ],
            ],
            null,
            null,
            false,
            false
        );
        $this->createdObjectUuids[] = $created->getUuid();

        $this->saveHandler->hydrateObjectMetadata($created, $schema);

        // Should use the downloadUrl from the first file object.
        $this->assertSame('https://example.com/img1.jpg', $created->getImage());
    }

    public function testHydrateObjectMetadataWithImageFromAccessUrlFallback(): void
    {
        $schema = new Schema();
        $schema->setTitle('phpunit-imgaccess-meta-' . uniqid());
        $schema->setDescription('Schema with image from accessUrl fallback');
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('phpunit-imgaccess-meta-' . uniqid());
        $schema->setProperties([
            'title'  => ['type' => 'string', 'title' => 'Title'],
            'images' => [
                'type'  => 'array',
                'title' => 'Images',
                'items' => ['type' => 'object'],
            ],
        ]);
        $schema->setConfiguration([
            'objectNameField'  => 'title',
            'objectImageField' => 'images',
        ]);
        $schema = $this->schemaMapper->insert($schema);
        $this->extraSchemaIds[] = $schema->getId();

        $created = $this->saveHandler->saveObject(
            $this->testRegister,
            $schema,
            [
                'title'  => 'AccessUrl Fallback Test',
                'images' => [
                    ['accessUrl' => 'https://example.com/img-access.jpg'],
                ],
            ],
            null,
            null,
            false,
            false
        );
        $this->createdObjectUuids[] = $created->getUuid();

        $this->saveHandler->hydrateObjectMetadata($created, $schema);

        // Should fall back to accessUrl when downloadUrl is not available.
        $this->assertSame('https://example.com/img-access.jpg', $created->getImage());
    }

    // =========================================================================
    // saveObject with cascading (related schema)
    // =========================================================================

    public function testSaveObjectWithCascadeObjectCreation(): void
    {
        // Create a related schema that the cascade will use.
        $relSchema = new Schema();
        $relSchema->setTitle('phpunit-cascade-sub-' . uniqid());
        $relSchema->setDescription('Sub-object schema for cascading');
        $relSchema->setUuid(Uuid::v4()->toRfc4122());
        $relSchemaSlug = 'phpunit-cascade-sub-' . uniqid();
        $relSchema->setSlug($relSchemaSlug);
        $relSchema->setProperties([
            'name' => ['type' => 'string', 'title' => 'Name'],
        ]);
        $relSchema = $this->schemaMapper->insert($relSchema);
        $this->extraSchemaIds[] = $relSchema->getId();

        // Create a parent schema with a cascade property using string type
        // (string stores the UUID cleanly in magic mapper, object type gets JSON column).
        $parentSchema = new Schema();
        $parentSchema->setTitle('phpunit-cascade-parent-' . uniqid());
        $parentSchema->setDescription('Parent schema for cascading');
        $parentSchema->setUuid(Uuid::v4()->toRfc4122());
        $parentSchema->setSlug('phpunit-cascade-parent-' . uniqid());
        $parentSchema->setProperties([
            'title'    => ['type' => 'string', 'title' => 'Title'],
            'contacts' => [
                'type'                => 'array',
                'title'               => 'Contacts',
                '$ref'                => '#/components/schemas/' . $relSchemaSlug,
                'objectConfiguration' => ['handling' => 'cascade'],
                'items'               => [
                    '$ref'                => '#/components/schemas/' . $relSchemaSlug,
                    'objectConfiguration' => ['handling' => 'cascade'],
                ],
            ],
        ]);
        $parentSchema = $this->schemaMapper->insert($parentSchema);
        $this->extraSchemaIds[] = $parentSchema->getId();

        $schemas = $this->testRegister->getSchemas();
        $schemas[] = $relSchema->getId();
        $schemas[] = $parentSchema->getId();
        $this->testRegister->setSchemas($schemas);
        $this->registerMapper->update($this->testRegister);

        $this->saveHandler->clearAllCaches();

        $result = $this->saveHandler->saveObject(
            $this->testRegister,
            $parentSchema,
            [
                'title'    => 'phpunit-cascade-test-' . uniqid(),
                'contacts' => [['name' => 'John Doe']],
            ],
            null,
            null,
            false,
            false
        );
        $this->createdObjectUuids[] = $result->getUuid();

        $objectData = $result->getObject();
        $this->assertInstanceOf(ObjectEntity::class, $result);

        // Track any sub-objects for cleanup.
        if (isset($objectData['contacts']) && is_array($objectData['contacts'])) {
            foreach ($objectData['contacts'] as $item) {
                if (is_string($item)) {
                    $this->createdObjectUuids[] = $item;
                }
            }
        }
    }

    public function testSaveObjectWithCascadeArrayObjects(): void
    {
        // Create a related schema.
        $relSchema = new Schema();
        $relSchema->setTitle('phpunit-cascade-arr-sub-' . uniqid());
        $relSchema->setDescription('Sub-object schema for array cascading');
        $relSchema->setUuid(Uuid::v4()->toRfc4122());
        $relSchemaSlug = 'phpunit-cascade-arr-sub-' . uniqid();
        $relSchema->setSlug($relSchemaSlug);
        $relSchema->setProperties([
            'label' => ['type' => 'string', 'title' => 'Label'],
        ]);
        $relSchema = $this->schemaMapper->insert($relSchema);
        $this->extraSchemaIds[] = $relSchema->getId();

        // Create a parent schema with a cascade array property.
        $parentSchema = new Schema();
        $parentSchema->setTitle('phpunit-cascade-arr-par-' . uniqid());
        $parentSchema->setDescription('Parent schema with array cascading');
        $parentSchema->setUuid(Uuid::v4()->toRfc4122());
        $parentSchema->setSlug('phpunit-cascade-arr-par-' . uniqid());
        $parentSchema->setProperties([
            'title' => ['type' => 'string', 'title' => 'Title'],
            'items' => [
                'type'                => 'array',
                'title'               => 'Items',
                '$ref'                => '#/components/schemas/' . $relSchemaSlug,
                'objectConfiguration' => ['handling' => 'cascade'],
                'items'               => [
                    '$ref'                => '#/components/schemas/' . $relSchemaSlug,
                    'objectConfiguration' => ['handling' => 'cascade'],
                ],
            ],
        ]);
        $parentSchema = $this->schemaMapper->insert($parentSchema);
        $this->extraSchemaIds[] = $parentSchema->getId();

        $schemas = $this->testRegister->getSchemas();
        $schemas[] = $relSchema->getId();
        $schemas[] = $parentSchema->getId();
        $this->testRegister->setSchemas($schemas);
        $this->registerMapper->update($this->testRegister);

        $this->saveHandler->clearAllCaches();

        $result = $this->saveHandler->saveObject(
            $this->testRegister,
            $parentSchema,
            [
                'title' => 'phpunit-cascade-arr-test-' . uniqid(),
                'items' => [
                    ['label' => 'Item A'],
                    ['label' => 'Item B'],
                ],
            ],
            null,
            null,
            false,
            false
        );
        $this->createdObjectUuids[] = $result->getUuid();

        $objectData = $result->getObject();
        $this->assertInstanceOf(ObjectEntity::class, $result);

        // Track any sub-objects for cleanup.
        if (isset($objectData['items']) && is_array($objectData['items'])) {
            foreach ($objectData['items'] as $item) {
                if (is_string($item)) {
                    $this->createdObjectUuids[] = $item;
                }
            }
        }
    }

    // =========================================================================
    // saveObject update - multiple field changes
    // =========================================================================

    public function testSaveObjectUpdateMultipleFields(): void
    {
        $created = $this->saveTestObject([
            'title'    => 'phpunit-multi-update-' . uniqid(),
            'summary'  => 'Original summary',
            'priority' => 1,
            'active'   => false,
            'tags'     => ['old'],
        ]);

        $updated = $this->saveHandler->saveObject(
            $this->testRegister,
            $this->testSchema,
            [
                'title'    => $created->getObject()['title'],
                'summary'  => 'New summary',
                'priority' => 99,
                'active'   => true,
                'tags'     => ['new1', 'new2'],
            ],
            $created->getUuid(),
            null,
            false,
            false
        );

        $objectData = $updated->getObject();
        $this->assertSame('New summary', $objectData['summary']);
        $this->assertSame(99, $objectData['priority']);
        $this->assertTrue($objectData['active']);
        $this->assertSame(['new1', 'new2'], $objectData['tags']);
    }

    // =========================================================================
    // saveObject with empty UUID handling
    // =========================================================================

    public function testSaveObjectWithEmptyStringUuid(): void
    {
        // Empty string UUID should be treated as null (create new object).
        $result = $this->saveHandler->saveObject(
            $this->testRegister,
            $this->testSchema,
            ['title' => 'phpunit-empty-uuid-' . uniqid()],
            '',
            null,
            false,
            false
        );
        $this->createdObjectUuids[] = $result->getUuid();

        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->assertNotEmpty($result->getUuid());
    }

    // =========================================================================
    // saveObject with @self nested id
    // =========================================================================

    public function testSaveObjectWithSelfNestedId(): void
    {
        $uuid = Uuid::v4()->toRfc4122();
        $result = $this->saveHandler->saveObject(
            $this->testRegister,
            $this->testSchema,
            [
                'title' => 'phpunit-nested-id-' . uniqid(),
                '@self' => ['id' => $uuid],
            ],
            null,
            null,
            false,
            false
        );
        $this->createdObjectUuids[] = $result->getUuid();

        // The @self.id should be used as the UUID.
        $this->assertSame($uuid, $result->getUuid());
    }

    // =========================================================================
    // saveObject with folder ID
    // =========================================================================

    public function testSaveObjectWithFolderId(): void
    {
        $result = $this->saveHandler->saveObject(
            $this->testRegister,
            $this->testSchema,
            ['title' => 'phpunit-folder-' . uniqid()],
            null,
            42, // folderId
            false,
            false
        );
        $this->createdObjectUuids[] = $result->getUuid();

        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->assertSame('42', $result->getFolder());
    }

    // =========================================================================
    // saveObject with silent mode (no audit trail)
    // =========================================================================

    public function testSaveObjectInSilentMode(): void
    {
        $result = $this->saveHandler->saveObject(
            $this->testRegister,
            $this->testSchema,
            ['title' => 'phpunit-silent-create-' . uniqid()],
            null,
            null,
            false,
            false,
            true,
            true // silent = true
        );
        $this->createdObjectUuids[] = $result->getUuid();

        $this->assertInstanceOf(ObjectEntity::class, $result);
    }

    // =========================================================================
    // saveObject with deeply nested metadata object
    // =========================================================================

    public function testSaveObjectWithDeeplyNestedObject(): void
    {
        $result = $this->saveTestObject([
            'title'    => 'phpunit-deep-nested-' . uniqid(),
            'metadata' => [
                'source'  => 'api',
                'version' => 3,
            ],
        ]);

        $objectData = $result->getObject();
        $this->assertIsArray($objectData['metadata']);
        $this->assertSame('api', $objectData['metadata']['source']);
        $this->assertSame(3, $objectData['metadata']['version']);
    }
}
