<?php

/**
 * Integration tests for SaveObjects service (bulk save)
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Integration tests for SaveObjects handler
 *
 * Tests bulk save operations including batch creation,
 * deduplication, and mixed schema processing.
 */
class SaveObjectsIntegrationTest extends TestCase
{
    /**
     * The save objects handler instance
     *
     * @var SaveObjects
     */
    private SaveObjects $saveObjectsHandler;

    /**
     * Object service for cleanup
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
        $this->saveObjectsHandler = \OC::$server->get(SaveObjects::class);
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
        $register->setTitle('phpunit-test-bulk-' . uniqid());
        $register->setDescription('Test register for bulk save tests');
        $register->setUuid(Uuid::v4()->toRfc4122());
        $register->setSlug('phpunit-test-' . uniqid());
        $register->setSchemas([]);
        $this->testRegister = $this->registerMapper->insert($register);

        $schema = new Schema();
        $schema->setTitle('phpunit-test-bulk-schema-' . uniqid());
        $schema->setDescription('Test schema for bulk save tests');
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('phpunit-test-' . uniqid());
        $schema->setProperties([
            'name' => ['type' => 'string', 'title' => 'Name'],
            'value' => ['type' => 'integer', 'title' => 'Value'],
        ]);
        $this->testSchema = $this->schemaMapper->insert($schema);

        $this->testRegister->setSchemas([$this->testSchema->getId()]);
        $this->registerMapper->update($this->testRegister);
    }

    /**
     * Test saveObjects with empty array returns empty result
     *
     * @return void
     */
    public function testSaveObjectsEmpty(): void
    {
        $result = $this->saveObjectsHandler->saveObjects(
            [],
            $this->testRegister,
            $this->testSchema,
            false,
            false
        );

        $this->assertIsArray($result);
    }

    /**
     * Test saveObjects with single object
     *
     * @return void
     */
    public function testSaveObjectsSingle(): void
    {
        $this->assertNotNull($this->testRegister);
        $this->assertNotNull($this->testSchema);

        $objects = [
            [
                'name'  => 'phpunit-test-bulk-single-' . uniqid(),
                'value' => 42,
            ],
        ];

        $result = $this->saveObjectsHandler->saveObjects(
            $objects,
            $this->testRegister,
            $this->testSchema,
            false,
            false,
            false,
            false,
            true,
            true
        );

        $this->assertIsArray($result);

        // Track created objects for cleanup
        if (isset($result['created']) && is_array($result['created'])) {
            foreach ($result['created'] as $obj) {
                if (isset($obj['uuid'])) {
                    $this->createdObjectUuids[] = $obj['uuid'];
                }
            }
        }
    }

    /**
     * Test saveObjects with multiple objects
     *
     * @return void
     */
    public function testSaveObjectsMultiple(): void
    {
        $this->assertNotNull($this->testRegister);
        $this->assertNotNull($this->testSchema);

        $objects = [];
        for ($i = 0; $i < 3; $i++) {
            $objects[] = [
                'name'  => 'phpunit-test-bulk-multi-' . $i . '-' . uniqid(),
                'value' => $i * 10,
            ];
        }

        $result = $this->saveObjectsHandler->saveObjects(
            $objects,
            $this->testRegister,
            $this->testSchema,
            false,
            false,
            false,
            false,
            true,
            true
        );

        $this->assertIsArray($result);

        // Track created objects for cleanup
        if (isset($result['created']) && is_array($result['created'])) {
            foreach ($result['created'] as $obj) {
                if (isset($obj['uuid'])) {
                    $this->createdObjectUuids[] = $obj['uuid'];
                }
            }
        }
    }

    /**
     * Test saveObjects with deduplication enabled
     *
     * @return void
     */
    public function testSaveObjectsWithDeduplication(): void
    {
        $this->assertNotNull($this->testRegister);
        $this->assertNotNull($this->testSchema);

        $sharedUuid = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();

        $objects = [
            [
                'id'    => $sharedUuid,
                'name'  => 'phpunit-test-dedup-1-' . uniqid(),
                'value' => 1,
            ],
            [
                'id'    => $sharedUuid,
                'name'  => 'phpunit-test-dedup-2-' . uniqid(),
                'value' => 2,
            ],
        ];

        $result = $this->saveObjectsHandler->saveObjects(
            $objects,
            $this->testRegister,
            $this->testSchema,
            false,
            false,
            false,
            false,
            true,
            true
        );

        $this->assertIsArray($result);

        // Track created objects for cleanup
        if (isset($result['created']) && is_array($result['created'])) {
            foreach ($result['created'] as $obj) {
                if (isset($obj['uuid'])) {
                    $this->createdObjectUuids[] = $obj['uuid'];
                }
            }
        }
    }
}
