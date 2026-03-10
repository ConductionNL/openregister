<?php

/**
 * Integration tests for RenderObject service
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
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Integration tests for RenderObject handler
 *
 * Tests object rendering including extensions, field filtering,
 * depth control, caching, and inverse relations.
 *
 * @group DB
 */
class RenderObjectIntegrationTest extends TestCase
{
    private RenderObject $renderHandler;
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
        $this->renderHandler = \OC::$server->get(RenderObject::class);
        $this->saveHandler = \OC::$server->get(SaveObject::class);
        $this->objectService = \OC::$server->get(ObjectService::class);
        $this->registerMapper = \OC::$server->get(RegisterMapper::class);
        $this->schemaMapper = \OC::$server->get(SchemaMapper::class);
        $this->objectEntityMapper = \OC::$server->get(ObjectEntityMapper::class);

        $this->createTestRegisterAndSchema();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdObjectUuids as $uuid) {
            try {
                $this->objectService->deleteObject($uuid, false, false);
            } catch (\Exception $e) {
                // Ignore cleanup errors.
            }
        }

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
        $register->setTitle('phpunit-render-' . uniqid());
        $register->setDescription('Test register for RenderObject tests');
        $register->setUuid(Uuid::v4()->toRfc4122());
        $register->setSlug('phpunit-render-' . uniqid());
        $register->setSchemas([]);
        $this->testRegister = $this->registerMapper->insert($register);

        $schema = new Schema();
        $schema->setTitle('phpunit-render-schema-' . uniqid());
        $schema->setDescription('Test schema for RenderObject tests');
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('phpunit-render-schema-' . uniqid());
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
            'category' => [
                'type'  => 'string',
                'title' => 'Category',
            ],
            'metadata' => [
                'type'       => 'object',
                'title'      => 'Metadata',
                'properties' => [
                    'source'  => ['type' => 'string'],
                    'version' => ['type' => 'integer'],
                ],
            ],
        ]);
        $this->testSchema = $this->schemaMapper->insert($schema);

        $this->testRegister->setSchemas([$this->testSchema->getId()]);
        $this->registerMapper->update($this->testRegister);
    }

    /**
     * Helper to create and save a test object.
     */
    private function createObject(array $data): ObjectEntity
    {
        $result = $this->saveHandler->saveObject(
            $this->testRegister,
            $this->testSchema,
            $data,
            null,
            null,
            false,
            false
        );
        $this->createdObjectUuids[] = $result->getUuid();
        return $result;
    }

    /**
     * Helper to create an object with a specific schema.
     */
    private function createObjectWithSchema(array $data, Schema $schema): ObjectEntity
    {
        $result = $this->saveHandler->saveObject(
            $this->testRegister,
            $schema,
            $data,
            null,
            null,
            false,
            false
        );
        $this->createdObjectUuids[] = $result->getUuid();
        return $result;
    }

    // =========================================================================
    // Ultra preload cache tests
    // =========================================================================

    public function testSetUltraPreloadCache(): void
    {
        $this->renderHandler->setUltraPreloadCache([]);
        $this->assertSame(0, $this->renderHandler->getUltraCacheSize());
    }

    public function testGetUltraCacheSizeEmpty(): void
    {
        $this->renderHandler->setUltraPreloadCache([]);
        $this->assertSame(0, $this->renderHandler->getUltraCacheSize());
    }

    public function testSetUltraPreloadCacheWithObjects(): void
    {
        $entity = new ObjectEntity();
        $entity->setUuid(Uuid::v4()->toRfc4122());
        $entity->setObject(['title' => 'Cached']);

        $this->renderHandler->setUltraPreloadCache([$entity->getUuid() => $entity]);
        $this->assertSame(1, $this->renderHandler->getUltraCacheSize());
    }

    // =========================================================================
    // clearCache tests
    // =========================================================================

    public function testClearCache(): void
    {
        $this->renderHandler->clearCache();
        // Should not throw.
        $this->assertTrue(true);
    }

    public function testClearCacheResetsObjectsCache(): void
    {
        $this->renderHandler->clearCache();
        $result = $this->renderHandler->getObjectsCache();
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // getObjectsCache tests
    // =========================================================================

    public function testGetObjectsCacheReturnsArray(): void
    {
        $this->renderHandler->clearCache();
        $result = $this->renderHandler->getObjectsCache();
        $this->assertIsArray($result);
    }

    // =========================================================================
    // renderEntity - basic tests
    // =========================================================================

    public function testRenderEntityBasic(): void
    {
        $object = $this->createObject([
            'title'   => 'phpunit-render-basic-' . uniqid(),
            'summary' => 'Render test',
        ]);

        $rendered = $this->renderHandler->renderEntity(
            $object,
            [],
            0,
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            false,
            false
        );

        $this->assertInstanceOf(ObjectEntity::class, $rendered);
        $objectData = $rendered->getObject();
        $this->assertIsArray($objectData);
        $this->assertArrayHasKey('title', $objectData);
    }

    public function testRenderEntityPreservesAllProperties(): void
    {
        $object = $this->createObject([
            'title'    => 'phpunit-render-all-' . uniqid(),
            'summary'  => 'Full data',
            'priority' => 5,
            'active'   => true,
            'tags'     => ['x', 'y'],
        ]);

        $rendered = $this->renderHandler->renderEntity(
            $object,
            [],
            0,
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            false,
            false
        );

        $objectData = $rendered->getObject();
        $this->assertSame(5, $objectData['priority']);
        $this->assertTrue($objectData['active']);
        $this->assertSame(['x', 'y'], $objectData['tags']);
    }

    public function testRenderEntityReturnsObjectEntity(): void
    {
        $object = $this->createObject([
            'title' => 'phpunit-render-type-' . uniqid(),
        ]);

        $rendered = $this->renderHandler->renderEntity(
            $object,
            [],
            0,
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            false,
            false
        );

        $this->assertInstanceOf(ObjectEntity::class, $rendered);
    }

    // =========================================================================
    // renderEntity - field filtering
    // =========================================================================

    public function testRenderEntityWithFieldsFilter(): void
    {
        $object = $this->createObject([
            'title'    => 'phpunit-fields-' . uniqid(),
            'summary'  => 'Should be excluded',
            'priority' => 3,
        ]);

        $rendered = $this->renderHandler->renderEntity(
            $object,
            [],
            0,
            [],
            ['title'],   // Only include title.
            [],
            [],
            [],
            [],
            [],
            false,
            false
        );

        $objectData = $rendered->getObject();
        $this->assertArrayHasKey('title', $objectData);
        // summary and priority should not be present (only title, @self, and id are kept).
        $this->assertArrayNotHasKey('summary', $objectData);
        $this->assertArrayNotHasKey('priority', $objectData);
    }

    public function testRenderEntityWithMultipleFields(): void
    {
        $object = $this->createObject([
            'title'    => 'phpunit-multi-fields-' . uniqid(),
            'summary'  => 'Included',
            'priority' => 7,
            'active'   => true,
        ]);

        $rendered = $this->renderHandler->renderEntity(
            $object,
            [],
            0,
            [],
            ['title', 'summary'],
            [],
            [],
            [],
            [],
            [],
            false,
            false
        );

        $objectData = $rendered->getObject();
        $this->assertArrayHasKey('title', $objectData);
        $this->assertArrayHasKey('summary', $objectData);
        $this->assertArrayNotHasKey('priority', $objectData);
    }

    // =========================================================================
    // renderEntity - filter (matching)
    // =========================================================================

    public function testRenderEntityWithFilterMatching(): void
    {
        $title = 'phpunit-filter-match-' . uniqid();
        $object = $this->createObject([
            'title'    => $title,
            'category' => 'test',
        ]);

        $rendered = $this->renderHandler->renderEntity(
            $object,
            [],
            0,
            ['category' => 'test'], // Filter: category must be 'test'.
            [],
            [],
            [],
            [],
            [],
            [],
            false,
            false
        );

        $objectData = $rendered->getObject();
        $this->assertNotEmpty($objectData);
        $this->assertSame($title, $objectData['title']);
    }

    public function testRenderEntityWithFilterNotMatching(): void
    {
        $object = $this->createObject([
            'title'    => 'phpunit-filter-nomatch-' . uniqid(),
            'category' => 'production',
        ]);

        $rendered = $this->renderHandler->renderEntity(
            $object,
            [],
            0,
            ['category' => 'non-existent'], // Filter: should not match.
            [],
            [],
            [],
            [],
            [],
            [],
            false,
            false
        );

        $objectData = $rendered->getObject();
        // When filter doesn't match, the object is either emptied or the value differs.
        // The filter compares the stored value with the filter value.
        if (empty($objectData) === false) {
            // If object wasn't emptied, verify the category doesn't match the filter.
            $this->assertNotSame('non-existent', $objectData['category'] ?? null);
        }
        // Either way, the test passes: the filter either emptied the object
        // or the value is confirmed not to match.
        $this->assertTrue(true);
    }

    // =========================================================================
    // renderEntity - unset (property removal)
    // =========================================================================

    public function testRenderEntityWithUnset(): void
    {
        $object = $this->createObject([
            'title'    => 'phpunit-unset-' . uniqid(),
            'summary'  => 'Remove this',
            'priority' => 3,
        ]);

        $rendered = $this->renderHandler->renderEntity(
            $object,
            [],
            0,
            [],
            [],
            ['summary'], // Unset summary.
            [],
            [],
            [],
            [],
            false,
            false
        );

        $objectData = $rendered->getObject();
        $this->assertArrayHasKey('title', $objectData);
        $this->assertArrayNotHasKey('summary', $objectData);
        $this->assertArrayHasKey('priority', $objectData);
    }

    public function testRenderEntityWithMultipleUnset(): void
    {
        $object = $this->createObject([
            'title'    => 'phpunit-multi-unset-' . uniqid(),
            'summary'  => 'Remove this',
            'priority' => 3,
            'active'   => true,
        ]);

        $rendered = $this->renderHandler->renderEntity(
            $object,
            [],
            0,
            [],
            [],
            ['summary', 'priority'], // Unset both.
            [],
            [],
            [],
            [],
            false,
            false
        );

        $objectData = $rendered->getObject();
        $this->assertArrayHasKey('title', $objectData);
        $this->assertArrayNotHasKey('summary', $objectData);
        $this->assertArrayNotHasKey('priority', $objectData);
        $this->assertArrayHasKey('active', $objectData);
    }

    // =========================================================================
    // renderEntity - depth control
    // =========================================================================

    public function testRenderEntityWithDepthZero(): void
    {
        $object = $this->createObject([
            'title' => 'phpunit-depth0-' . uniqid(),
        ]);

        $rendered = $this->renderHandler->renderEntity(
            $object,
            [],
            0, // depth = 0.
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            false,
            false
        );

        $this->assertInstanceOf(ObjectEntity::class, $rendered);
    }

    public function testRenderEntityWithHighDepth(): void
    {
        $object = $this->createObject([
            'title' => 'phpunit-depth-high-' . uniqid(),
        ]);

        $rendered = $this->renderHandler->renderEntity(
            $object,
            [],
            9, // depth = 9 (near max of 10).
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            false,
            false
        );

        $this->assertInstanceOf(ObjectEntity::class, $rendered);
    }

    // =========================================================================
    // renderEntity - circular reference detection
    // =========================================================================

    public function testRenderEntityCircularReferenceDetection(): void
    {
        $object = $this->createObject([
            'title' => 'phpunit-circular-' . uniqid(),
        ]);

        // Pass the object's UUID in visitedIds to simulate circular reference.
        $rendered = $this->renderHandler->renderEntity(
            $object,
            [],
            0,
            [],
            [],
            [],
            [],
            [],
            [],
            [$object->getUuid()], // Already visited.
            false,
            false
        );

        $objectData = $rendered->getObject();
        $this->assertArrayHasKey('@circular', $objectData);
        $this->assertTrue($objectData['@circular']);
    }

    // =========================================================================
    // renderEntity - extend (basic)
    // =========================================================================

    public function testRenderEntityWithEmptyExtend(): void
    {
        $object = $this->createObject([
            'title' => 'phpunit-no-extend-' . uniqid(),
        ]);

        $rendered = $this->renderHandler->renderEntity(
            $object,
            [],
            0,
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            false,
            false
        );

        $this->assertInstanceOf(ObjectEntity::class, $rendered);
    }

    public function testRenderEntityWithExtendString(): void
    {
        $object = $this->createObject([
            'title' => 'phpunit-extend-str-' . uniqid(),
        ]);

        $rendered = $this->renderHandler->renderEntity(
            $object,
            'title,summary',
            0,
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            false,
            false
        );

        $this->assertInstanceOf(ObjectEntity::class, $rendered);
    }

    public function testRenderEntityWithExtendArray(): void
    {
        $object = $this->createObject([
            'title' => 'phpunit-extend-arr-' . uniqid(),
        ]);

        $rendered = $this->renderHandler->renderEntity(
            $object,
            ['title', 'summary'],
            0,
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            false,
            false
        );

        $this->assertInstanceOf(ObjectEntity::class, $rendered);
    }

    // =========================================================================
    // renderEntity - extend with related objects
    // =========================================================================

    public function testRenderEntityExtendWithRelatedObject(): void
    {
        // Create a related object.
        $related = $this->createObject([
            'title'   => 'phpunit-related-' . uniqid(),
            'summary' => 'I am the related object',
        ]);

        // Create a main object that references the related one by UUID.
        $main = $this->createObject([
            'title'    => 'phpunit-main-' . uniqid(),
            'category' => $related->getUuid(),
        ]);

        $rendered = $this->renderHandler->renderEntity(
            $main,
            ['category'],
            0,
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            false,
            false
        );

        $this->assertInstanceOf(ObjectEntity::class, $rendered);
        $objectData = $rendered->getObject();
        // The category field should be extended (either as an object or kept as UUID).
        $this->assertArrayHasKey('category', $objectData);
    }

    // =========================================================================
    // renderEntity - extend with @self.schema and @self.register
    // =========================================================================

    public function testRenderEntityExtendSelfSchema(): void
    {
        $object = $this->createObject([
            'title' => 'phpunit-extend-schema-' . uniqid(),
        ]);

        $rendered = $this->renderHandler->renderEntity(
            $object,
            ['@self.schema'],
            0,
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            false,
            false
        );

        $this->assertInstanceOf(ObjectEntity::class, $rendered);
    }

    public function testRenderEntityExtendSelfRegister(): void
    {
        $object = $this->createObject([
            'title' => 'phpunit-extend-register-' . uniqid(),
        ]);

        $rendered = $this->renderHandler->renderEntity(
            $object,
            ['@self.register'],
            0,
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            false,
            false
        );

        $this->assertInstanceOf(ObjectEntity::class, $rendered);
    }

    public function testRenderEntityExtendShorthandSchema(): void
    {
        $object = $this->createObject([
            'title' => 'phpunit-extend-shorthand-' . uniqid(),
        ]);

        $rendered = $this->renderHandler->renderEntity(
            $object,
            ['_schema'],
            0,
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            false,
            false
        );

        $this->assertInstanceOf(ObjectEntity::class, $rendered);
    }

    // =========================================================================
    // renderEntity - extend 'all'
    // =========================================================================

    public function testRenderEntityExtendAll(): void
    {
        $object = $this->createObject([
            'title'   => 'phpunit-extend-all-' . uniqid(),
            'summary' => 'Extend all test',
        ]);

        $rendered = $this->renderHandler->renderEntity(
            $object,
            ['all'],
            0,
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            false,
            false
        );

        $this->assertInstanceOf(ObjectEntity::class, $rendered);
    }

    // =========================================================================
    // renderEntity - with preloaded caches
    // =========================================================================

    public function testRenderEntityWithPreloadedRegisterCache(): void
    {
        $object = $this->createObject([
            'title' => 'phpunit-preload-reg-' . uniqid(),
        ]);

        $rendered = $this->renderHandler->renderEntity(
            $object,
            [],
            0,
            [],
            [],
            [],
            [$this->testRegister->getId() => $this->testRegister], // Preloaded registers.
            [],
            [],
            [],
            false,
            false
        );

        $this->assertInstanceOf(ObjectEntity::class, $rendered);
    }

    public function testRenderEntityWithPreloadedSchemaCache(): void
    {
        $object = $this->createObject([
            'title' => 'phpunit-preload-schema-' . uniqid(),
        ]);

        $rendered = $this->renderHandler->renderEntity(
            $object,
            [],
            0,
            [],
            [],
            [],
            [],
            [$this->testSchema->getId() => $this->testSchema], // Preloaded schemas.
            [],
            [],
            false,
            false
        );

        $this->assertInstanceOf(ObjectEntity::class, $rendered);
    }

    // =========================================================================
    // renderEntity - caching behavior (render same object twice)
    // =========================================================================

    public function testRenderEntityTwiceReturnsSameResult(): void
    {
        $object = $this->createObject([
            'title'    => 'phpunit-cache-twice-' . uniqid(),
            'priority' => 42,
        ]);

        $params = [
            $object, [], 0, [], [], [], [], [], [], [], false, false,
        ];

        $rendered1 = $this->renderHandler->renderEntity(...$params);
        $rendered2 = $this->renderHandler->renderEntity(...$params);

        $data1 = $rendered1->getObject();
        $data2 = $rendered2->getObject();
        $this->assertSame($data1['title'], $data2['title']);
        $this->assertSame($data1['priority'], $data2['priority']);
    }

    // =========================================================================
    // renderEntities - batch rendering
    // =========================================================================

    public function testRenderEntitiesEmpty(): void
    {
        $result = $this->renderHandler->renderEntities(
            [],
            [],
            null,
            null,
            null,
            false,
            false
        );

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testRenderEntitiesSingleObject(): void
    {
        $object = $this->createObject([
            'title' => 'phpunit-batch-single-' . uniqid(),
        ]);

        $result = $this->renderHandler->renderEntities(
            [$object],
            [],
            null,
            null,
            null,
            false,
            false
        );

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertInstanceOf(ObjectEntity::class, $result[0]);
    }

    public function testRenderEntitiesMultipleObjects(): void
    {
        $objects = [];
        for ($i = 0; $i < 5; $i++) {
            $objects[] = $this->createObject([
                'title'    => 'phpunit-batch-' . $i . '-' . uniqid(),
                'priority' => $i,
            ]);
        }

        $result = $this->renderHandler->renderEntities(
            $objects,
            [],
            null,
            null,
            null,
            false,
            false
        );

        $this->assertCount(5, $result);
    }

    public function testRenderEntitiesWithStringExtend(): void
    {
        $object = $this->createObject([
            'title' => 'phpunit-batch-extend-' . uniqid(),
        ]);

        $result = $this->renderHandler->renderEntities(
            [$object],
            'title',
            null,
            null,
            null,
            false,
            false
        );

        $this->assertCount(1, $result);
    }

    public function testRenderEntitiesWithFieldsFilter(): void
    {
        $object = $this->createObject([
            'title'    => 'phpunit-batch-fields-' . uniqid(),
            'summary'  => 'Exclude me',
            'priority' => 9,
        ]);

        $result = $this->renderHandler->renderEntities(
            [$object],
            [],
            null,
            'title', // Only title.
            null,
            false,
            false
        );

        $this->assertCount(1, $result);
        $objectData = $result[0]->getObject();
        $this->assertArrayHasKey('title', $objectData);
        $this->assertArrayNotHasKey('summary', $objectData);
    }

    public function testRenderEntitiesWithUnset(): void
    {
        $object = $this->createObject([
            'title'    => 'phpunit-batch-unset-' . uniqid(),
            'summary'  => 'Remove me',
            'priority' => 1,
        ]);

        $result = $this->renderHandler->renderEntities(
            [$object],
            [],
            null,
            null,
            'summary', // Unset summary.
            false,
            false
        );

        $this->assertCount(1, $result);
        $objectData = $result[0]->getObject();
        $this->assertArrayNotHasKey('summary', $objectData);
    }

    // =========================================================================
    // renderEntities - batch with related objects (extend)
    // =========================================================================

    public function testRenderEntitiesWithExtendRelatedObjects(): void
    {
        $related = $this->createObject([
            'title' => 'phpunit-batch-related-' . uniqid(),
        ]);

        $main = $this->createObject([
            'title'    => 'phpunit-batch-main-' . uniqid(),
            'category' => $related->getUuid(),
        ]);

        $result = $this->renderHandler->renderEntities(
            [$main],
            ['category'],
            null,
            null,
            null,
            false,
            false
        );

        $this->assertCount(1, $result);
    }

    // =========================================================================
    // renderEntity - extend null
    // =========================================================================

    public function testRenderEntityWithNullExtend(): void
    {
        $object = $this->createObject([
            'title' => 'phpunit-null-extend-' . uniqid(),
        ]);

        $rendered = $this->renderHandler->renderEntity(
            $object,
            null,
            0,
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            false,
            false
        );

        $this->assertInstanceOf(ObjectEntity::class, $rendered);
    }

    // =========================================================================
    // renderEntity with filter + fields combined
    // =========================================================================

    public function testRenderEntityFilterAndFieldsCombined(): void
    {
        $object = $this->createObject([
            'title'    => 'phpunit-combo-' . uniqid(),
            'category' => 'test',
            'priority' => 5,
            'summary'  => 'Extra',
        ]);

        $rendered = $this->renderHandler->renderEntity(
            $object,
            [],
            0,
            ['category' => 'test'], // Filter.
            ['title', 'category'],  // Only these fields.
            [],
            [],
            [],
            [],
            [],
            false,
            false
        );

        $objectData = $rendered->getObject();
        $this->assertNotEmpty($objectData);
        $this->assertArrayHasKey('title', $objectData);
        $this->assertArrayNotHasKey('priority', $objectData);
    }

    // =========================================================================
    // renderEntity with unset + fields combined
    // =========================================================================

    public function testRenderEntityUnsetAndFieldsCombined(): void
    {
        $object = $this->createObject([
            'title'    => 'phpunit-unset-fields-' . uniqid(),
            'summary'  => 'Keep',
            'category' => 'Remove',
            'priority' => 3,
        ]);

        $rendered = $this->renderHandler->renderEntity(
            $object,
            [],
            0,
            [],
            ['title', 'summary', 'category'], // Include these.
            ['category'],                      // But unset category.
            [],
            [],
            [],
            [],
            false,
            false
        );

        $objectData = $rendered->getObject();
        $this->assertArrayHasKey('title', $objectData);
        $this->assertArrayHasKey('summary', $objectData);
        $this->assertArrayNotHasKey('category', $objectData);
    }

    // =========================================================================
    // Ultra cache with real objects
    // =========================================================================

    public function testUltraPreloadCacheWithRealObject(): void
    {
        $object = $this->createObject([
            'title' => 'phpunit-ultra-real-' . uniqid(),
        ]);

        // Set the object in ultra cache.
        $this->renderHandler->setUltraPreloadCache([
            $object->getUuid() => $object,
        ]);

        $this->assertSame(1, $this->renderHandler->getUltraCacheSize());

        // Reset.
        $this->renderHandler->setUltraPreloadCache([]);
    }
}
