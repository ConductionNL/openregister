<?php

/**
 * Integration tests for ReferentialIntegrityService
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
use OCA\OpenRegister\Db\UnifiedObjectMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Dto\DeletionAnalysis;
use OCA\OpenRegister\Service\Object\ReferentialIntegrityService;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Integration tests for ReferentialIntegrityService
 *
 * Tests referential integrity analysis, onDelete validation, and deletion graph walking.
 */
class ReferentialIntegrityServiceIntegrationTest extends TestCase
{
    /**
     * The referential integrity service instance
     *
     * @var ReferentialIntegrityService
     */
    private ReferentialIntegrityService $service;

    /**
     * Object service
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
        $this->service = \OC::$server->get(ReferentialIntegrityService::class);
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
                // Ignore
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
        $register->setTitle('phpunit-test-ri-' . uniqid());
        $register->setDescription('Test register for referential integrity tests');
        $register->setUuid(Uuid::v4()->toRfc4122());
        $register->setSlug('phpunit-test-' . uniqid());
        $register->setSchemas([]);
        $this->testRegister = $this->registerMapper->insert($register);

        $schema = new Schema();
        $schema->setTitle('phpunit-test-ri-schema-' . uniqid());
        $schema->setDescription('Test schema for referential integrity tests');
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('phpunit-test-' . uniqid());
        $schema->setProperties([
            'name' => ['type' => 'string', 'title' => 'Name'],
        ]);
        $this->testSchema = $this->schemaMapper->insert($schema);

        $this->testRegister->setSchemas([$this->testSchema->getId()]);
        $this->registerMapper->update($this->testRegister);
    }

    /**
     * Test isValidOnDeleteAction with valid actions
     *
     * @return void
     */
    public function testIsValidOnDeleteActionValid(): void
    {
        $this->assertTrue(ReferentialIntegrityService::isValidOnDeleteAction('CASCADE'));
        $this->assertTrue(ReferentialIntegrityService::isValidOnDeleteAction('RESTRICT'));
        $this->assertTrue(ReferentialIntegrityService::isValidOnDeleteAction('SET_NULL'));
        $this->assertTrue(ReferentialIntegrityService::isValidOnDeleteAction('SET_DEFAULT'));
        $this->assertTrue(ReferentialIntegrityService::isValidOnDeleteAction('NO_ACTION'));
    }

    /**
     * Test isValidOnDeleteAction is case-insensitive
     *
     * @return void
     */
    public function testIsValidOnDeleteActionCaseInsensitive(): void
    {
        $this->assertTrue(ReferentialIntegrityService::isValidOnDeleteAction('cascade'));
        $this->assertTrue(ReferentialIntegrityService::isValidOnDeleteAction('Restrict'));
        $this->assertTrue(ReferentialIntegrityService::isValidOnDeleteAction('set_null'));
    }

    /**
     * Test isValidOnDeleteAction with invalid actions
     *
     * @return void
     */
    public function testIsValidOnDeleteActionInvalid(): void
    {
        $this->assertFalse(ReferentialIntegrityService::isValidOnDeleteAction('INVALID'));
        $this->assertFalse(ReferentialIntegrityService::isValidOnDeleteAction(''));
        $this->assertFalse(ReferentialIntegrityService::isValidOnDeleteAction('DELETE'));
        $this->assertFalse(ReferentialIntegrityService::isValidOnDeleteAction('REMOVE'));
    }

    /**
     * Test canDelete with object that has no references
     *
     * @return void
     */
    public function testCanDeleteNoReferences(): void
    {
        $this->assertNotNull($this->testRegister);
        $this->assertNotNull($this->testSchema);

        $objectData = [
            'name' => 'phpunit-test-ri-' . uniqid(),
        ];

        $saved = $this->objectService->saveObject(
            $objectData,
            null,
            $this->testRegister,
            $this->testSchema,
            null,
            false,
            false
        );

        $this->createdObjectUuids[] = $saved->getUuid();

        $result = $this->service->canDelete($saved);

        $this->assertInstanceOf(DeletionAnalysis::class, $result);
        $this->assertTrue($result->deletable);
        $this->assertEmpty($result->blockers);
    }

    /**
     * Test hasIncomingOnDeleteReferences with nonexistent schema
     *
     * @return void
     */
    public function testHasIncomingOnDeleteReferencesNonexistent(): void
    {
        $result = $this->service->hasIncomingOnDeleteReferences('99999999');

        $this->assertFalse($result);
    }

    /**
     * Test canDelete with null schema object
     *
     * @return void
     */
    public function testCanDeleteNullSchema(): void
    {
        $object = new ObjectEntity();
        // Object without a schema set
        $object->setUuid(\Symfony\Component\Uid\Uuid::v4()->toRfc4122());

        $result = $this->service->canDelete($object);

        $this->assertInstanceOf(DeletionAnalysis::class, $result);
        $this->assertTrue($result->deletable);
    }

    /**
     * Test DeletionAnalysis empty factory method
     *
     * @return void
     */
    public function testDeletionAnalysisEmpty(): void
    {
        $result = DeletionAnalysis::empty();

        $this->assertTrue($result->deletable);
        $this->assertEmpty($result->cascadeTargets);
        $this->assertEmpty($result->nullifyTargets);
        $this->assertEmpty($result->defaultTargets);
        $this->assertEmpty($result->blockers);
    }

    /**
     * Test DeletionAnalysis toArray
     *
     * @return void
     */
    public function testDeletionAnalysisToArray(): void
    {
        $analysis = new DeletionAnalysis(
            true,
            ['cascade1'],
            ['nullify1'],
            ['default1'],
            [],
            ['path1']
        );

        $array = $analysis->toArray();

        $this->assertIsArray($array);
        $this->assertTrue($array['deletable']);
        $this->assertSame(['cascade1'], $array['cascadeTargets']);
        $this->assertSame(['nullify1'], $array['nullifyTargets']);
        $this->assertSame(['default1'], $array['defaultTargets']);
        $this->assertEmpty($array['blockers']);
        $this->assertSame(['path1'], $array['chainPaths']);
    }

    /**
     * Test DeletionAnalysis with blockers
     *
     * @return void
     */
    public function testDeletionAnalysisWithBlockers(): void
    {
        $analysis = new DeletionAnalysis(
            false,
            [],
            [],
            [],
            [
                ['schema' => '1', 'property' => 'ref', 'objectUuid' => 'abc'],
            ]
        );

        $this->assertFalse($analysis->deletable);
        $this->assertCount(1, $analysis->blockers);
    }

    /**
     * Test logRestrictBlock does not throw
     *
     * @return void
     */
    public function testLogRestrictBlock(): void
    {
        $analysis = new DeletionAnalysis(
            false,
            [],
            [],
            [],
            [
                ['schema' => '1', 'property' => 'ref', 'objectUuid' => 'test-uuid'],
            ]
        );

        // Should not throw
        $this->service->logRestrictBlock(
            'test-object-uuid',
            '1',
            $analysis,
            'admin'
        );

        $this->assertTrue(true);
    }
}
