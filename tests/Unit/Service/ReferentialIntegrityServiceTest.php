<?php
/**
 * ReferentialIntegrityService Unit Tests
 *
 * Tests for the referential integrity service that enforces onDelete constraints
 * when objects are deleted. Covers relation index building, graph walking,
 * all five action types (CASCADE, RESTRICT, SET_NULL, SET_DEFAULT, NO_ACTION),
 * fallback chains, cycle detection, and depth limiting.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Test class requires many collaborator mocks
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)   Comprehensive coverage requires many test methods
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)   Comprehensive test suite
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Dto\DeletionAnalysis;
use OCA\OpenRegister\Service\Object\ReferentialIntegrityService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for ReferentialIntegrityService
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 */
class ReferentialIntegrityServiceTest extends TestCase
{

    /**
     * Service under test.
     *
     * @var ReferentialIntegrityService
     */
    private ReferentialIntegrityService $service;

    /**
     * Mock schema mapper.
     *
     * @var SchemaMapper&MockObject
     */
    private SchemaMapper $schemaMapper;

    /**
     * Mock object entity mapper.
     *
     * @var ObjectEntityMapper&MockObject
     */
    private ObjectEntityMapper $objectEntityMapper;

    /**
     * Mock audit trail mapper.
     *
     * @var AuditTrailMapper&MockObject
     */
    private AuditTrailMapper $auditTrailMapper;

    /**
     * Mock register mapper.
     *
     * @var RegisterMapper&MockObject
     */
    private RegisterMapper $registerMapper;

    /**
     * Mock logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    /**
     * Reflection for accessing private members.
     *
     * @var \ReflectionClass
     */
    private \ReflectionClass $reflection;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->schemaMapper       = $this->createMock(SchemaMapper::class);
        $this->registerMapper     = $this->createMock(RegisterMapper::class);
        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
        $this->auditTrailMapper   = $this->createMock(AuditTrailMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new ReferentialIntegrityService(
            schemaMapper: $this->schemaMapper,
            registerMapper: $this->registerMapper,
            objectEntityMapper: $this->objectEntityMapper,
            auditTrailMapper: $this->auditTrailMapper,
            logger: $this->logger
        );

        $this->reflection = new \ReflectionClass($this->service);
    }//end setUp()

    // ─── Helper methods ──────────────────────────────────────────────

    /**
     * Create a real Schema entity with given properties.
     *
     * Uses real Schema instances (not mocks) because Nextcloud Entity
     * uses __call magic for getters/setters which PHPUnit cannot mock.
     *
     * @param int         $id         Schema ID.
     * @param string      $slug       Schema slug.
     * @param array|null  $properties Schema properties array.
     * @param array       $required   Required property names.
     * @param string|null $uuid       Schema UUID.
     *
     * @return Schema
     */
    private function createTestSchema(
        int $id,
        string $slug,
        ?array $properties=null,
        array $required=[],
        ?string $uuid=null
    ): Schema {
        $schema = new Schema();
        $schema->setId($id);
        $schema->setSlug($slug);
        $schema->setUuid($uuid ?? "uuid-schema-{$id}");
        $schema->setProperties($properties);
        $schema->setRequired($required);
        return $schema;
    }//end createTestSchema()

    /**
     * Create a real ObjectEntity with given values.
     *
     * Uses real ObjectEntity instances (not mocks) because Nextcloud Entity
     * uses __call magic for getters/setters which PHPUnit cannot mock.
     *
     * @param string     $uuid    Object UUID.
     * @param string     $schema  Schema ID.
     * @param array      $object  Object data.
     * @param array|null $deleted Deleted metadata (null = not deleted).
     *
     * @return ObjectEntity
     */
    private function createTestObject(
        string $uuid,
        string $schema,
        array $object=[],
        ?array $deleted=null
    ): ObjectEntity {
        $entity = new ObjectEntity();
        $entity->setUuid($uuid);
        $entity->setSchema($schema);
        $entity->setObject($object);
        if ($deleted !== null) {
            $entity->setDeleted($deleted);
        }

        return $entity;
    }//end createTestObject()

    /**
     * Invoke a private method on the service.
     *
     * @param string $methodName Method name.
     * @param array  $args       Arguments.
     *
     * @return mixed Return value.
     */
    private function invokePrivate(string $methodName, array $args=[]): mixed
    {
        $method = $this->reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($this->service, $args);
    }//end invokePrivate()

    /**
     * Set a private property on the service.
     *
     * @param string $name  Property name.
     * @param mixed  $value Value to set.
     *
     * @return void
     */
    private function setProperty(string $name, mixed $value): void
    {
        $property = $this->reflection->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($this->service, $value);
    }//end setProperty()

    /**
     * Set up schemas on the mapper mock and build the relation index.
     *
     * @param Schema[] $schemas Array of Schema objects.
     *
     * @return void
     */
    private function setupSchemas(array $schemas): void
    {
        $this->schemaMapper->method('findAll')->willReturn($schemas);
        // Force index rebuild.
        $this->setProperty('relationIndex', null);
        $this->setProperty('schemaCache', null);
    }//end setupSchemas()

    // ─── isValidOnDeleteAction tests ─────────────────────────────────

    /**
     * Test valid onDelete action values.
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
    }//end testIsValidOnDeleteActionValid()

    /**
     * Test case-insensitive validation.
     *
     * @return void
     */
    public function testIsValidOnDeleteActionCaseInsensitive(): void
    {
        $this->assertTrue(ReferentialIntegrityService::isValidOnDeleteAction('cascade'));
        $this->assertTrue(ReferentialIntegrityService::isValidOnDeleteAction('Restrict'));
        $this->assertTrue(ReferentialIntegrityService::isValidOnDeleteAction('set_null'));
    }//end testIsValidOnDeleteActionCaseInsensitive()

    /**
     * Test invalid onDelete action values.
     *
     * @return void
     */
    public function testIsValidOnDeleteActionInvalid(): void
    {
        $this->assertFalse(ReferentialIntegrityService::isValidOnDeleteAction('DESTROY'));
        $this->assertFalse(ReferentialIntegrityService::isValidOnDeleteAction('DELETE'));
        $this->assertFalse(ReferentialIntegrityService::isValidOnDeleteAction(''));
        $this->assertFalse(ReferentialIntegrityService::isValidOnDeleteAction('NULLIFY'));
    }//end testIsValidOnDeleteActionInvalid()

    // ─── Relation index building tests ───────────────────────────────

    /**
     * Test that ensureRelationIndex builds index from schemas with onDelete.
     *
     * @return void
     */
    public function testEnsureRelationIndexBuildsFromSchemas(): void
    {
        $personSchema  = $this->createTestSchema(1, 'person');
        $contactSchema = $this->createTestSchema(
            id: 2,
            slug: 'contact-detail',
            properties: [
                'person' => [
                    'type'     => 'string',
                    '$ref'     => '1',
                    'onDelete' => 'CASCADE',
                ],
            ]
        );

        $this->setupSchemas([$personSchema, $contactSchema]);

        $result = $this->service->hasIncomingOnDeleteReferences('1');
        $this->assertTrue($result);
    }//end testEnsureRelationIndexBuildsFromSchemas()

    /**
     * Test that NO_ACTION schemas are excluded from the index.
     *
     * @return void
     */
    public function testRelationIndexExcludesNoAction(): void
    {
        $personSchema = $this->createTestSchema(1, 'person');
        $logSchema    = $this->createTestSchema(
            id: 2,
            slug: 'log',
            properties: [
                'user' => [
                    'type'     => 'string',
                    '$ref'     => '1',
                    'onDelete' => 'NO_ACTION',
                ],
            ]
        );

        $this->setupSchemas([$personSchema, $logSchema]);

        $result = $this->service->hasIncomingOnDeleteReferences('1');
        $this->assertFalse($result);
    }//end testRelationIndexExcludesNoAction()

    /**
     * Test that schemas without onDelete are excluded from the index.
     *
     * @return void
     */
    public function testRelationIndexExcludesNoOnDelete(): void
    {
        $personSchema = $this->createTestSchema(1, 'person');
        $logSchema    = $this->createTestSchema(
            id: 2,
            slug: 'log',
            properties: [
                'user' => [
                    'type' => 'string',
                    '$ref' => '1',
                    // No onDelete configured.
                ],
            ]
        );

        $this->setupSchemas([$personSchema, $logSchema]);

        $result = $this->service->hasIncomingOnDeleteReferences('1');
        $this->assertFalse($result);
    }//end testRelationIndexExcludesNoOnDelete()

    /**
     * Test that array $ref (items.$ref) is handled correctly.
     *
     * @return void
     */
    public function testRelationIndexHandlesArrayRef(): void
    {
        $personSchema = $this->createTestSchema(1, 'person');
        $teamSchema   = $this->createTestSchema(
            id: 2,
            slug: 'team',
            properties: [
                'members' => [
                    'type'     => 'array',
                    'items'    => ['$ref' => '1'],
                    'onDelete' => 'CASCADE',
                ],
            ]
        );

        $this->setupSchemas([$personSchema, $teamSchema]);

        $result = $this->service->hasIncomingOnDeleteReferences('1');
        $this->assertTrue($result);
    }//end testRelationIndexHandlesArrayRef()

    /**
     * Test slug-based $ref resolution.
     *
     * @return void
     */
    public function testRelationIndexResolvesSlugRef(): void
    {
        $personSchema  = $this->createTestSchema(1, 'person');
        $contactSchema = $this->createTestSchema(
            id: 2,
            slug: 'contact',
            properties: [
                'person' => [
                    'type'     => 'string',
                    '$ref'     => 'person',
                    'onDelete' => 'RESTRICT',
                ],
            ]
        );

        $this->setupSchemas([$personSchema, $contactSchema]);

        $result = $this->service->hasIncomingOnDeleteReferences('1');
        $this->assertTrue($result);
    }//end testRelationIndexResolvesSlugRef()

    /**
     * Test that multiple schemas referencing the same target are all indexed.
     *
     * @return void
     */
    public function testMultipleSchemasReferencingSameTarget(): void
    {
        $personSchema  = $this->createTestSchema(1, 'person');
        $contactSchema = $this->createTestSchema(
            id: 2,
            slug: 'contact',
            properties: [
                'person' => ['type' => 'string', '$ref' => '1', 'onDelete' => 'CASCADE'],
            ]
        );
        $serviceSchema = $this->createTestSchema(
            id: 3,
            slug: 'service',
            properties: [
                'manager' => ['type' => 'string', '$ref' => '1', 'onDelete' => 'RESTRICT'],
            ]
        );

        $this->setupSchemas([$personSchema, $contactSchema, $serviceSchema]);

        $this->assertTrue($this->service->hasIncomingOnDeleteReferences('1'));
    }//end testMultipleSchemasReferencingSameTarget()

    /**
     * Test that index is cached and not rebuilt on second call.
     *
     * @return void
     */
    public function testRelationIndexIsCached(): void
    {
        $personSchema = $this->createTestSchema(1, 'person');

        // findAll should only be called once.
        $this->schemaMapper->expects($this->once())
            ->method('findAll')
            ->willReturn([$personSchema]);

        $this->service->hasIncomingOnDeleteReferences('1');
        $this->service->hasIncomingOnDeleteReferences('1');
    }//end testRelationIndexIsCached()

    // ─── canDelete tests ─────────────────────────────────────────────

    /**
     * Test canDelete returns empty analysis when object has no schema.
     *
     * @return void
     */
    public function testCanDeleteNoSchema(): void
    {
        $object = $this->createTestObject('uuid-1', '999');
        $this->setupSchemas([]);

        $analysis = $this->service->canDelete($object);

        $this->assertTrue($analysis->deletable);
        $this->assertEmpty($analysis->cascadeTargets);
    }//end testCanDeleteNoSchema()

    /**
     * Test canDelete returns empty analysis when no schemas reference this schema.
     *
     * @return void
     */
    public function testCanDeleteNoIncomingReferences(): void
    {
        $personSchema = $this->createTestSchema(1, 'person');
        $this->setupSchemas([$personSchema]);

        $object = $this->createTestObject('person-uuid', '1');

        $analysis = $this->service->canDelete($object);

        $this->assertTrue($analysis->deletable);
        $this->assertEmpty($analysis->cascadeTargets);
    }//end testCanDeleteNoIncomingReferences()

    /**
     * Test canDelete detects CASCADE dependent.
     *
     * @return void
     */
    public function testCanDeleteDetectsCascade(): void
    {
        $personSchema  = $this->createTestSchema(1, 'person');
        $contactSchema = $this->createTestSchema(
            id: 2,
            slug: 'contact',
            properties: [
                'person' => ['type' => 'string', '$ref' => '1', 'onDelete' => 'CASCADE'],
            ]
        );

        $this->setupSchemas([$personSchema, $contactSchema]);

        $personObj  = $this->createTestObject('person-uuid', '1');
        $contactObj = $this->createTestObject(
            'contact-uuid',
            '2',
            ['person' => 'person-uuid']
        );

        $this->objectEntityMapper->method('findByRelation')
            ->willReturn([$contactObj]);

        $analysis = $this->service->canDelete($personObj);

        $this->assertTrue($analysis->deletable);
        $this->assertCount(1, $analysis->cascadeTargets);
        $this->assertSame('contact-uuid', $analysis->cascadeTargets[0]['objectUuid']);
    }//end testCanDeleteDetectsCascade()

    /**
     * Test canDelete detects RESTRICT blocker.
     *
     * @return void
     */
    public function testCanDeleteDetectsRestrict(): void
    {
        $personSchema  = $this->createTestSchema(1, 'person');
        $serviceSchema = $this->createTestSchema(
            id: 2,
            slug: 'service',
            properties: [
                'manager' => ['type' => 'string', '$ref' => '1', 'onDelete' => 'RESTRICT'],
            ]
        );

        $this->setupSchemas([$personSchema, $serviceSchema]);

        $personObj  = $this->createTestObject('person-uuid', '1');
        $serviceObj = $this->createTestObject(
            'service-uuid',
            '2',
            ['manager' => 'person-uuid']
        );

        $this->objectEntityMapper->method('findByRelation')
            ->willReturn([$serviceObj]);

        $analysis = $this->service->canDelete($personObj);

        $this->assertFalse($analysis->deletable);
        $this->assertCount(1, $analysis->blockers);
        $this->assertSame('RESTRICT', $analysis->blockers[0]['action']);
        $this->assertSame('service-uuid', $analysis->blockers[0]['objectUuid']);
    }//end testCanDeleteDetectsRestrict()

    /**
     * Test canDelete with SET_NULL on non-required property.
     *
     * @return void
     */
    public function testCanDeleteSetNullNonRequired(): void
    {
        $personSchema = $this->createTestSchema(1, 'person');
        $orderSchema  = $this->createTestSchema(
            id: 2,
            slug: 'order',
            properties: [
                'coupon' => ['type' => 'string', '$ref' => '1', 'onDelete' => 'SET_NULL'],
            ],
            required: []
        );

        $this->setupSchemas([$personSchema, $orderSchema]);

        $personObj = $this->createTestObject('person-uuid', '1');
        $orderObj  = $this->createTestObject(
            'order-uuid',
            '2',
            ['coupon' => 'person-uuid']
        );

        $this->objectEntityMapper->method('findByRelation')
            ->willReturn([$orderObj]);

        $analysis = $this->service->canDelete($personObj);

        $this->assertTrue($analysis->deletable);
        $this->assertCount(1, $analysis->nullifyTargets);
        $this->assertSame('order-uuid', $analysis->nullifyTargets[0]['objectUuid']);
    }//end testCanDeleteSetNullNonRequired()

    /**
     * Test SET_NULL on required property falls back to RESTRICT.
     *
     * @return void
     */
    public function testCanDeleteSetNullOnRequiredFallsBackToRestrict(): void
    {
        $personSchema = $this->createTestSchema(1, 'person');
        $orderSchema  = $this->createTestSchema(
            id: 2,
            slug: 'order',
            properties: [
                'coupon' => ['type' => 'string', '$ref' => '1', 'onDelete' => 'SET_NULL'],
            ],
            required: ['coupon']
        );

        $this->setupSchemas([$personSchema, $orderSchema]);

        $personObj = $this->createTestObject('person-uuid', '1');
        $orderObj  = $this->createTestObject(
            'order-uuid',
            '2',
            ['coupon' => 'person-uuid']
        );

        $this->objectEntityMapper->method('findByRelation')
            ->willReturn([$orderObj]);

        $analysis = $this->service->canDelete($personObj);

        $this->assertFalse($analysis->deletable);
        $this->assertCount(1, $analysis->blockers);
        $this->assertSame('RESTRICT', $analysis->blockers[0]['action']);
        $this->assertEmpty($analysis->nullifyTargets);
    }//end testCanDeleteSetNullOnRequiredFallsBackToRestrict()

    /**
     * Test SET_DEFAULT with a default value defined.
     *
     * @return void
     */
    public function testCanDeleteSetDefaultWithDefault(): void
    {
        $personSchema = $this->createTestSchema(1, 'person');
        $taskSchema   = $this->createTestSchema(
            id: 2,
            slug: 'task',
            properties: [
                'assignee' => [
                    'type'     => 'string',
                    '$ref'     => '1',
                    'onDelete' => 'SET_DEFAULT',
                    'default'  => 'unassigned-uuid',
                ],
            ]
        );

        $this->setupSchemas([$personSchema, $taskSchema]);

        $personObj = $this->createTestObject('person-uuid', '1');
        $taskObj   = $this->createTestObject(
            'task-uuid',
            '2',
            ['assignee' => 'person-uuid']
        );

        $this->objectEntityMapper->method('findByRelation')
            ->willReturn([$taskObj]);

        $analysis = $this->service->canDelete($personObj);

        $this->assertTrue($analysis->deletable);
        $this->assertCount(1, $analysis->defaultTargets);
        $this->assertSame('unassigned-uuid', $analysis->defaultTargets[0]['defaultValue']);
    }//end testCanDeleteSetDefaultWithDefault()

    /**
     * Test SET_DEFAULT without default value falls back to SET_NULL.
     *
     * @return void
     */
    public function testCanDeleteSetDefaultNoDefaultFallsToSetNull(): void
    {
        $personSchema = $this->createTestSchema(1, 'person');
        $taskSchema   = $this->createTestSchema(
            id: 2,
            slug: 'task',
            properties: [
                'assignee' => [
                    'type'     => 'string',
                    '$ref'     => '1',
                    'onDelete' => 'SET_DEFAULT',
                    // No default value.
                ],
            ],
            required: []
        );

        $this->setupSchemas([$personSchema, $taskSchema]);

        $personObj = $this->createTestObject('person-uuid', '1');
        $taskObj   = $this->createTestObject(
            'task-uuid',
            '2',
            ['assignee' => 'person-uuid']
        );

        $this->objectEntityMapper->method('findByRelation')
            ->willReturn([$taskObj]);

        $analysis = $this->service->canDelete($personObj);

        $this->assertTrue($analysis->deletable);
        $this->assertCount(1, $analysis->nullifyTargets);
        $this->assertEmpty($analysis->defaultTargets);
    }//end testCanDeleteSetDefaultNoDefaultFallsToSetNull()

    /**
     * Test SET_DEFAULT without default on required property falls to RESTRICT.
     *
     * @return void
     */
    public function testCanDeleteSetDefaultNoDefaultRequiredFallsToRestrict(): void
    {
        $personSchema = $this->createTestSchema(1, 'person');
        $taskSchema   = $this->createTestSchema(
            id: 2,
            slug: 'task',
            properties: [
                'assignee' => [
                    'type'     => 'string',
                    '$ref'     => '1',
                    'onDelete' => 'SET_DEFAULT',
                ],
            ],
            required: ['assignee']
        );

        $this->setupSchemas([$personSchema, $taskSchema]);

        $personObj = $this->createTestObject('person-uuid', '1');
        $taskObj   = $this->createTestObject(
            'task-uuid',
            '2',
            ['assignee' => 'person-uuid']
        );

        $this->objectEntityMapper->method('findByRelation')
            ->willReturn([$taskObj]);

        $analysis = $this->service->canDelete($personObj);

        $this->assertFalse($analysis->deletable);
        $this->assertCount(1, $analysis->blockers);
        $this->assertSame('RESTRICT', $analysis->blockers[0]['action']);
    }//end testCanDeleteSetDefaultNoDefaultRequiredFallsToRestrict()

    /**
     * Test RESTRICT with no actual dependents allows deletion.
     *
     * @return void
     */
    public function testCanDeleteRestrictWithNoDependentsAllowsDeletion(): void
    {
        $personSchema  = $this->createTestSchema(1, 'person');
        $serviceSchema = $this->createTestSchema(
            id: 2,
            slug: 'service',
            properties: [
                'manager' => ['type' => 'string', '$ref' => '1', 'onDelete' => 'RESTRICT'],
            ]
        );

        $this->setupSchemas([$personSchema, $serviceSchema]);

        $personObj = $this->createTestObject('person-uuid', '1');

        // No objects reference this person.
        $this->objectEntityMapper->method('findByRelation')
            ->willReturn([]);

        $analysis = $this->service->canDelete($personObj);

        $this->assertTrue($analysis->deletable);
        $this->assertEmpty($analysis->blockers);
    }//end testCanDeleteRestrictWithNoDependentsAllowsDeletion()

    /**
     * Test chained CASCADE: A → B (CASCADE) → C (CASCADE).
     *
     * @return void
     */
    public function testCanDeleteChainedCascade(): void
    {
        $personSchema  = $this->createTestSchema(1, 'person');
        $contactSchema = $this->createTestSchema(
            id: 2,
            slug: 'contact',
            properties: [
                'person' => ['type' => 'string', '$ref' => '1', 'onDelete' => 'CASCADE'],
            ]
        );
        $phoneSchema   = $this->createTestSchema(
            id: 3,
            slug: 'phone',
            properties: [
                'contact' => ['type' => 'string', '$ref' => '2', 'onDelete' => 'CASCADE'],
            ]
        );

        $this->setupSchemas([$personSchema, $contactSchema, $phoneSchema]);

        $personObj  = $this->createTestObject('person-uuid', '1');
        $contactObj = $this->createTestObject('contact-uuid', '2', ['person' => 'person-uuid']);
        $phoneObj   = $this->createTestObject('phone-uuid', '3', ['contact' => 'contact-uuid']);

        // First call (for person-uuid): returns contactObj.
        // Second call (for contact-uuid, recursion): returns phoneObj.
        $this->objectEntityMapper->method('findByRelation')
            ->willReturnCallback(
                    function (string $search) use ($contactObj, $phoneObj) {
                        if ($search === 'person-uuid') {
                            return [$contactObj];
                        }

                        if ($search === 'contact-uuid') {
                            return [$phoneObj];
                        }

                        return [];
                    }
                    );

        $analysis = $this->service->canDelete($personObj);

        $this->assertTrue($analysis->deletable);
        $this->assertCount(2, $analysis->cascadeTargets);
    }//end testCanDeleteChainedCascade()

    /**
     * Test chained CASCADE into RESTRICT blocks deletion.
     *
     * @return void
     */
    public function testCanDeleteChainedCascadeIntoRestrict(): void
    {
        $personSchema  = $this->createTestSchema(1, 'person');
        $contactSchema = $this->createTestSchema(
            id: 2,
            slug: 'contact',
            properties: [
                'person' => ['type' => 'string', '$ref' => '1', 'onDelete' => 'CASCADE'],
            ]
        );
        $auditSchema   = $this->createTestSchema(
            id: 3,
            slug: 'audit',
            properties: [
                'contact' => ['type' => 'string', '$ref' => '2', 'onDelete' => 'RESTRICT'],
            ]
        );

        $this->setupSchemas([$personSchema, $contactSchema, $auditSchema]);

        $personObj  = $this->createTestObject('person-uuid', '1');
        $contactObj = $this->createTestObject('contact-uuid', '2', ['person' => 'person-uuid']);
        $auditObj   = $this->createTestObject('audit-uuid', '3', ['contact' => 'contact-uuid']);

        $this->objectEntityMapper->method('findByRelation')
            ->willReturnCallback(
                    function (string $search) use ($contactObj, $auditObj) {
                        if ($search === 'person-uuid') {
                            return [$contactObj];
                        }

                        if ($search === 'contact-uuid') {
                            return [$auditObj];
                        }

                        return [];
                    }
                    );

        $analysis = $this->service->canDelete($personObj);

        $this->assertFalse($analysis->deletable);
        $this->assertCount(1, $analysis->blockers);
        $this->assertSame('audit-uuid', $analysis->blockers[0]['objectUuid']);
    }//end testCanDeleteChainedCascadeIntoRestrict()

    /**
     * Test that already soft-deleted dependents are skipped.
     *
     * @return void
     */
    public function testCanDeleteSkipsAlreadyDeletedDependents(): void
    {
        $personSchema  = $this->createTestSchema(1, 'person');
        $serviceSchema = $this->createTestSchema(
            id: 2,
            slug: 'service',
            properties: [
                'manager' => ['type' => 'string', '$ref' => '1', 'onDelete' => 'RESTRICT'],
            ]
        );

        $this->setupSchemas([$personSchema, $serviceSchema]);

        $personObj  = $this->createTestObject('person-uuid', '1');
        $serviceObj = $this->createTestObject(
            'service-uuid',
            '2',
            ['manager' => 'person-uuid'],
            ['deletedBy' => 'admin', 'deletedAt' => '2024-01-01']
        );

        $this->objectEntityMapper->method('findByRelation')
            ->willReturn([$serviceObj]);

        $analysis = $this->service->canDelete($personObj);

        // Soft-deleted dependent should be skipped — no blocker.
        $this->assertTrue($analysis->deletable);
        $this->assertEmpty($analysis->blockers);
    }//end testCanDeleteSkipsAlreadyDeletedDependents()

    /**
     * Test circular reference detection prevents infinite recursion.
     *
     * @return void
     */
    public function testCanDeleteCircularReferenceDetection(): void
    {
        $schemaA = $this->createTestSchema(
            id: 1,
            slug: 'schema-a',
            properties: [
                'refB' => ['type' => 'string', '$ref' => '2', 'onDelete' => 'CASCADE'],
            ]
        );
        $schemaB = $this->createTestSchema(
            id: 2,
            slug: 'schema-b',
            properties: [
                'refA' => ['type' => 'string', '$ref' => '1', 'onDelete' => 'CASCADE'],
            ]
        );

        $this->setupSchemas([$schemaA, $schemaB]);

        $objA = $this->createTestObject('uuid-a', '1', ['refB' => 'uuid-b']);
        $objB = $this->createTestObject('uuid-b', '2', ['refA' => 'uuid-a']);

        $this->objectEntityMapper->method('findByRelation')
            ->willReturnCallback(
                    function (string $search) use ($objA, $objB) {
                        if ($search === 'uuid-a') {
                            return [$objB];
                        }

                        if ($search === 'uuid-b') {
                            return [$objA];
                        }

                        return [];
                    }
                    );

        // Should not infinite loop — cycle detection kicks in.
        $analysis = $this->service->canDelete($objA);

        $this->assertTrue($analysis->deletable);
        // objB should be in cascade targets but objA should NOT be revisited.
        $this->assertNotEmpty($analysis->cascadeTargets);
    }//end testCanDeleteCircularReferenceDetection()

    /**
     * Test mixed action types: CASCADE + SET_NULL + RESTRICT.
     *
     * @return void
     */
    public function testCanDeleteMixedActionTypes(): void
    {
        $personSchema  = $this->createTestSchema(1, 'person');
        $contactSchema = $this->createTestSchema(
            id: 2,
            slug: 'contact',
            properties: [
                'person' => ['type' => 'string', '$ref' => '1', 'onDelete' => 'CASCADE'],
            ]
        );
        $orderSchema   = $this->createTestSchema(
            id: 3,
            slug: 'order',
            properties: [
                'assignee' => ['type' => 'string', '$ref' => '1', 'onDelete' => 'SET_NULL'],
            ],
            required: []
        );
        $serviceSchema = $this->createTestSchema(
            id: 4,
            slug: 'service',
            properties: [
                'manager' => ['type' => 'string', '$ref' => '1', 'onDelete' => 'RESTRICT'],
            ]
        );

        $this->setupSchemas([$personSchema, $contactSchema, $orderSchema, $serviceSchema]);

        $personObj  = $this->createTestObject('person-uuid', '1');
        $contactObj = $this->createTestObject('contact-uuid', '2', ['person' => 'person-uuid']);
        $orderObj   = $this->createTestObject('order-uuid', '3', ['assignee' => 'person-uuid']);
        $serviceObj = $this->createTestObject('service-uuid', '4', ['manager' => 'person-uuid']);

        $this->objectEntityMapper->method('findByRelation')
            ->willReturn([$contactObj, $orderObj, $serviceObj]);

        $analysis = $this->service->canDelete($personObj);

        // RESTRICT blocks it.
        $this->assertFalse($analysis->deletable);
        $this->assertNotEmpty($analysis->blockers);
        // But CASCADE and SET_NULL are still reported.
        $this->assertNotEmpty($analysis->cascadeTargets);
        $this->assertNotEmpty($analysis->nullifyTargets);
    }//end testCanDeleteMixedActionTypes()

    // ─── hasIncomingOnDeleteReferences tests ──────────────────────────

    /**
     * Test hasIncomingOnDeleteReferences returns false for unreferenced schema.
     *
     * @return void
     */
    public function testHasIncomingReferencesReturnsFalseForUnreferenced(): void
    {
        $personSchema = $this->createTestSchema(1, 'person');
        $this->setupSchemas([$personSchema]);

        $this->assertFalse($this->service->hasIncomingOnDeleteReferences('1'));
    }//end testHasIncomingReferencesReturnsFalseForUnreferenced()

    /**
     * Test hasIncomingOnDeleteReferences returns true for referenced schema.
     *
     * @return void
     */
    public function testHasIncomingReferencesReturnsTrueForReferenced(): void
    {
        $personSchema  = $this->createTestSchema(1, 'person');
        $contactSchema = $this->createTestSchema(
            id: 2,
            slug: 'contact',
            properties: [
                'person' => ['type' => 'string', '$ref' => '1', 'onDelete' => 'CASCADE'],
            ]
        );

        $this->setupSchemas([$personSchema, $contactSchema]);

        $this->assertTrue($this->service->hasIncomingOnDeleteReferences('1'));
    }//end testHasIncomingReferencesReturnsTrueForReferenced()

    // ─── applyDeletionActions tests ──────────────────────────────────

    /**
     * Test applyDeletionActions with empty analysis does nothing.
     *
     * @return void
     */
    public function testApplyDeletionActionsEmptyAnalysis(): void
    {
        $analysis = DeletionAnalysis::empty();

        // Should not call any mapper methods.
        $this->objectEntityMapper->expects($this->never())
            ->method('findAcrossAllSources');

        $this->service->applyDeletionActions(
            analysis: $analysis,
            userId: 'admin',
            cascadeSource: 'root-uuid'
        );

        // No exception means success.
        $this->assertTrue(true);
    }//end testApplyDeletionActionsEmptyAnalysis()

    /**
     * Test that applyDeletionActions processes targets in correct order.
     *
     * Order: SET_NULL → SET_DEFAULT → CASCADE.
     *
     * @return void
     */
    public function testApplyDeletionActionsExecutionOrder(): void
    {
        $callOrder = [];

        $nullifyTarget = [
            'objectUuid' => 'null-uuid',
            'schema'     => '2',
            'property'   => 'ref',
            'isArray'    => false,
            'sourceUuid' => 'root-uuid',
        ];
        $defaultTarget = [
            'objectUuid'   => 'default-uuid',
            'schema'       => '3',
            'property'     => 'ref',
            'defaultValue' => 'fallback',
        ];
        $cascadeTarget = [
            'objectUuid' => 'cascade-uuid',
            'schema'     => '4',
            'property'   => 'ref',
            'chain'      => [],
        ];

        $analysis = new DeletionAnalysis(
            deletable: true,
            cascadeTargets: [$cascadeTarget],
            nullifyTargets: [$nullifyTarget],
            defaultTargets: [$defaultTarget]
        );

        // Create real entities for findAcrossAllSources results.
        $nullObj    = $this->createTestObject('null-uuid', '2', ['ref' => 'root-uuid']);
        $defaultObj = $this->createTestObject('default-uuid', '3', ['ref' => 'root-uuid']);
        $cascadeObj = $this->createTestObject('cascade-uuid', '4', ['ref' => 'root-uuid']);

        $mockRegister = new Register();
        $mockSchema   = new Schema();

        $this->objectEntityMapper->method('findAcrossAllSources')
            ->willReturnCallback(
                function (string $identifier) use (&$callOrder, $nullObj, $defaultObj, $cascadeObj, $mockRegister, $mockSchema) {
                    $callOrder[] = $identifier;
                    $objMap      = [
                        'null-uuid'    => $nullObj,
                        'default-uuid' => $defaultObj,
                        'cascade-uuid' => $cascadeObj,
                    ];
                    return [
                        'object'   => $objMap[$identifier],
                        'register' => $mockRegister,
                        'schema'   => $mockSchema,
                    ];
                }
            );

        $this->objectEntityMapper->method('update')->willReturnArgument(0);

        // CASCADE now goes through applyBatchCascadeDelete which calls
        // registerMapper->find() + schemaMapper->find() + deleteObjects(),
        // not findAcrossAllSources. Track cascade via deleteObjects.
        $cascadeDeletedUuids = [];
        $this->registerMapper->method('find')->willReturn($mockRegister);
        $this->schemaMapper->method('find')->willReturn($mockSchema);
        $this->objectEntityMapper->method('deleteObjects')
            ->willReturnCallback(
                function (array $uuids) use (&$callOrder, &$cascadeDeletedUuids) {
                    $cascadeDeletedUuids = array_merge($cascadeDeletedUuids, $uuids);
                    // Track that cascade happened after SET_NULL and SET_DEFAULT.
                    $callOrder[] = 'cascade-batch';
                    return ['deleted' => $uuids];
                }
            );

        $this->service->applyDeletionActions(
            analysis: $analysis,
            userId: 'admin',
            cascadeSource: 'root-uuid'
        );

        // SET_NULL first, SET_DEFAULT second, CASCADE third.
        $this->assertSame('null-uuid', $callOrder[0]);
        $this->assertSame('default-uuid', $callOrder[1]);
        $this->assertSame('cascade-batch', $callOrder[2]);
        $this->assertContains('cascade-uuid', $cascadeDeletedUuids);
    }//end testApplyDeletionActionsExecutionOrder()
}//end class
