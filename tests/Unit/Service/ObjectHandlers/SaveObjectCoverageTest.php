<?php

/**
 * SaveObject Coverage Tests
 *
 * Tests for uncovered branches in SaveObject: validateReferences, validateReferenceExists,
 * findAndValidateExistingObject, cascadeMultipleObjects, deleteOrphanedRelatedObjects,
 * preCacheParentName, clearImageMetadataIfFileProperty, processFilePropertiesWithRollback,
 * updateInverseRelations, cascadeSingleObject, handleObjectUpdate, handleObjectCreation,
 * and resolveSchemaAndRegister.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectHandlers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectHandlers;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use stdClass;
use Twig\Loader\ArrayLoader;

/**
 * Testable Schema subclass to avoid mocking Entity __call magic methods.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class CoverageTestableSchema extends Schema
{
    public ?stdClass $testSchemaObject = null;
    public ?array $testConfiguration = null;
    public ?array $testProperties = null;
    private bool $testHasPropertyAuth = false;

    /**
     * @param IURLGenerator $urlGenerator URL generator
     *
     * @return stdClass
     */
    public function getSchemaObject(IURLGenerator $urlGenerator): stdClass
    {
        return $this->testSchemaObject ?? new stdClass();
    }

    /**
     * @return array|null
     */
    public function getConfiguration(): ?array
    {
        return $this->testConfiguration;
    }

    /**
     * @return array
     */
    public function getProperties(): array
    {
        return $this->testProperties ?? [];
    }

    /**
     * @return bool
     */
    public function hasPropertyAuthorization(): bool
    {
        return $this->testHasPropertyAuth;
    }

    /**
     * @param bool $value Whether schema has property auth
     *
     * @return void
     */
    public function setTestHasPropertyAuth(bool $value): void
    {
        $this->testHasPropertyAuth = $value;
    }
}

/**
 * Coverage tests for SaveObject service.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class SaveObjectCoverageTest extends TestCase
{
    private SaveObject $saveObject;
    private ReflectionClass $reflection;

    /** @var MockObject&ObjectEntityMapper */
    private $objectEntityMapper;

    /** @var MockObject&MagicMapper */
    private $unifiedObjectMapper;

    /** @var MockObject&MetadataHydrationHandler */
    private $metaHydrationHandler;

    /** @var MockObject&FilePropertyHandler */
    private $filePropertyHandler;

    /** @var MockObject&IUserSession */
    private $userSession;

    /** @var MockObject&AuditTrailMapper */
    private $auditTrailMapper;

    /** @var MockObject&SchemaMapper */
    private $schemaMapper;

    /** @var MockObject&RegisterMapper */
    private $registerMapper;

    /** @var MockObject&IURLGenerator */
    private $urlGenerator;

    /** @var MockObject&OrganisationService */
    private $organisationService;

    /** @var MockObject&CacheHandler */
    private $cacheHandler;

    /** @var MockObject&SettingsService */
    private $settingsService;

    /** @var MockObject&PropertyRbacHandler */
    private $propertyRbacHandler;

    /** @var MockObject&LoggerInterface */
    private $logger;

    /**
     * Set up test environment before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
        $this->unifiedObjectMapper = $this->createMock(MagicMapper::class);
        $this->metaHydrationHandler = $this->createMock(MetadataHydrationHandler::class);
        $this->filePropertyHandler = $this->createMock(FilePropertyHandler::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->organisationService = $this->createMock(OrganisationService::class);
        $this->cacheHandler = $this->createMock(CacheHandler::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->propertyRbacHandler = $this->createMock(PropertyRbacHandler::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->saveObject = new SaveObject(
            $this->objectEntityMapper,
            $this->unifiedObjectMapper,
            $this->metaHydrationHandler,
            $this->filePropertyHandler,
            $this->userSession,
            $this->auditTrailMapper,
            $this->schemaMapper,
            $this->registerMapper,
            $this->urlGenerator,
            $this->organisationService,
            $this->cacheHandler,
            $this->settingsService,
            $this->propertyRbacHandler,
            $this->logger,
            new ArrayLoader()
        );

        $this->reflection = new ReflectionClass($this->saveObject);
    }

    /**
     * Helper to invoke private methods via reflection.
     *
     * @param string $method Method name
     * @param array  $args   Method arguments
     *
     * @return mixed
     */
    private function invokePrivate(string $method, array $args = [])
    {
        $m = $this->reflection->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($this->saveObject, $args);
    }

    /**
     * Helper to set private property via reflection.
     *
     * @param string $property Property name
     * @param mixed  $value    Property value
     *
     * @return void
     */
    private function setPrivateProperty(string $property, $value): void
    {
        $p = $this->reflection->getProperty($property);
        $p->setAccessible(true);
        $p->setValue($this->saveObject, $value);
    }

    /**
     * Helper to create a Schema entity with reflection for id.
     *
     * @param int    $id   Schema ID
     * @param string $slug Schema slug
     *
     * @return CoverageTestableSchema
     */
    private function createSchema(int $id, string $slug = 'test-schema'): CoverageTestableSchema
    {
        $schema = new CoverageTestableSchema();
        $ref = new ReflectionClass(Schema::class);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($schema, $id);
        $schema->setSlug($slug);
        $schema->setTitle('Test Schema');
        return $schema;
    }

    /**
     * Helper to create a Register entity with reflection for id.
     *
     * @param int    $id    Register ID
     * @param string $title Register title
     *
     * @return Register
     */
    private function createRegister(int $id, string $title = 'Test Register'): Register
    {
        $register = new Register();
        $ref = new ReflectionClass($register);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($register, $id);
        $register->setTitle($title);
        $register->setSlug('test-register');
        return $register;
    }

    /**
     * Helper to create an ObjectEntity with a given id and uuid.
     *
     * @param int         $id   Entity ID
     * @param string|null $uuid Entity UUID
     * @param array       $data Object data
     *
     * @return ObjectEntity
     */
    private function createObjectEntity(int $id, ?string $uuid = null, array $data = []): ObjectEntity
    {
        $entity = new ObjectEntity();
        $ref = new ReflectionClass($entity);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($entity, $id);
        if ($uuid !== null) {
            $entity->setUuid($uuid);
        }
        $entity->setObject($data);
        $entity->setRegister('1');
        $entity->setSchema('1');
        return $entity;
    }

    // =========================================================================
    // validateReferences
    // =========================================================================

    /**
     * Test validateReferences with null properties returns early.
     *
     * @return void
     */
    public function testValidateReferencesNullProperties(): void
    {
        $schema = $this->createSchema(1);
        $schema->testProperties = null;

        // Should not throw, just return.
        $this->invokePrivate('validateReferences', [$schema, ['field' => 'value'], '1', null]);

        $this->assertTrue(true);
    }

    /**
     * Test validateReferences skips properties without validateReference.
     *
     * @return void
     */
    public function testValidateReferencesSkipsNonValidatedProperties(): void
    {
        $schema = $this->createSchema(1);
        $schema->testProperties = [
            'name' => ['type' => 'string'],
        ];

        $this->invokePrivate('validateReferences', [$schema, ['name' => 'Test'], '1', null]);

        $this->assertTrue(true);
    }

    /**
     * Test validateReferences skips when value is null or empty.
     *
     * @return void
     */
    public function testValidateReferencesSkipsNullAndEmptyValues(): void
    {
        $schema = $this->createSchema(1);
        $schema->testProperties = [
            'ref_field' => [
                'type' => 'string',
                'validateReference' => true,
                '$ref' => '#/components/schemas/Target',
            ],
        ];

        $this->invokePrivate('validateReferences', [$schema, ['ref_field' => null], '1', null]);
        $this->invokePrivate('validateReferences', [$schema, ['ref_field' => ''], '1', null]);

        $this->assertTrue(true);
    }

    /**
     * Test validateReferences skips unchanged values on update.
     *
     * @return void
     */
    public function testValidateReferencesSkipsUnchangedValuesOnUpdate(): void
    {
        $schema = $this->createSchema(1);
        $schema->testProperties = [
            'ref_field' => [
                'type' => 'string',
                'validateReference' => true,
                '$ref' => '#/components/schemas/Target',
            ],
        ];

        $uuid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $oldData = ['ref_field' => $uuid];
        $data = ['ref_field' => $uuid];

        // Should not call any mapper because value is unchanged.
        $this->invokePrivate('validateReferences', [$schema, $data, '1', $oldData]);

        $this->assertTrue(true);
    }

    /**
     * Test validateReferences skips when no $ref configured.
     *
     * @return void
     */
    public function testValidateReferencesSkipsWithoutRef(): void
    {
        $schema = $this->createSchema(1);
        $schema->testProperties = [
            'ref_field' => [
                'type' => 'string',
                'validateReference' => true,
                // No $ref
            ],
        ];

        $this->invokePrivate('validateReferences', [$schema, ['ref_field' => 'some-uuid'], '1', null]);

        $this->assertTrue(true);
    }

    /**
     * Test validateReferences validates array of UUIDs.
     *
     * @return void
     */
    public function testValidateReferencesArrayProperty(): void
    {
        $schema = $this->createSchema(1);
        $schema->testProperties = [
            'refs' => [
                'type' => 'array',
                'validateReference' => true,
                'items' => ['$ref' => '#/components/schemas/Target'],
            ],
        ];

        // resolveSchemaReference needs a schema
        $targetSchema = $this->createSchema(2, 'Target');
        $this->schemaMapper->method('findAll')
            ->willReturn([$targetSchema]);

        // find should find the referenced objects
        $this->unifiedObjectMapper->method('find')
            ->willReturn($this->createObjectEntity(10, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'));

        $data = ['refs' => ['aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', '']];
        $this->invokePrivate('validateReferences', [$schema, $data, '1', null]);

        $this->assertTrue(true);
    }

    // =========================================================================
    // validateReferenceExists
    // =========================================================================

    /**
     * Test validateReferenceExists throws ValidationException when object not found.
     *
     * @return void
     */
    public function testValidateReferenceExistsThrowsOnNotFound(): void
    {
        $targetSchema = $this->createSchema(2, 'target-schema');

        // Pre-populate schema cache so resolveSchemaReference returns '2'.
        $this->setPrivateProperty('schemaCache', ['2' => $targetSchema]);
        $this->setPrivateProperty('schemaReferenceCache', ['2' => '2']);

        $register = $this->createRegister(1);
        $this->setPrivateProperty('registerCache', ['1' => $register]);

        $this->unifiedObjectMapper->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        $this->expectException(ValidationException::class);
        $this->expectExceptionCode(422);

        $this->invokePrivate('validateReferenceExists', [
            'myProp',
            'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            '2',
            '1',
        ]);
    }

    /**
     * Test validateReferenceExists logs warning on non-existence exception.
     *
     * @return void
     */
    public function testValidateReferenceExistsLogsWarningOnGeneralException(): void
    {
        $targetSchema = $this->createSchema(2, 'target-schema');
        $this->setPrivateProperty('schemaCache', ['2' => $targetSchema]);
        $this->setPrivateProperty('schemaReferenceCache', ['2' => '2']);

        $register = $this->createRegister(1);
        $this->setPrivateProperty('registerCache', ['1' => $register]);

        $this->unifiedObjectMapper->method('find')
            ->willThrowException(new Exception('database error'));

        $this->logger->expects($this->atLeastOnce())
            ->method('warning');

        // Should not throw, just log.
        $this->invokePrivate('validateReferenceExists', [
            'myProp',
            'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            '2',
            '1',
        ]);
    }

    /**
     * Test validateReferenceExists returns early when schema reference unresolvable.
     *
     * @return void
     */
    public function testValidateReferenceExistsReturnsWhenSchemaUnresolvable(): void
    {
        // Empty schema reference cache, findAll returns empty, find throws.
        $this->schemaMapper->method('findAll')->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        // Should not throw, just log warning and return.
        $this->invokePrivate('validateReferenceExists', [
            'myProp',
            'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'nonexistent-schema',
            '1',
        ]);

        $this->assertTrue(true);
    }

    // =========================================================================
    // findAndValidateExistingObject
    // =========================================================================

    /**
     * Test findAndValidateExistingObject returns null when not found.
     *
     * @return void
     */
    public function testFindAndValidateExistingObjectReturnsNullOnNotFound(): void
    {
        $this->objectEntityMapper->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        $result = $this->invokePrivate('findAndValidateExistingObject', [
            'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            null,
            null,
            true,
            true,
        ]);

        $this->assertNull($result);
    }

    /**
     * Test findAndValidateExistingObject returns object when found and not locked.
     *
     * @return void
     */
    public function testFindAndValidateExistingObjectReturnsWhenNotLocked(): void
    {
        $entity = $this->createObjectEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        $entity->setLocked(null);

        $this->objectEntityMapper->method('find')
            ->willReturn($entity);

        $result = $this->invokePrivate('findAndValidateExistingObject', [
            'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            null,
            null,
            true,
            true,
        ]);

        $this->assertSame($entity, $result);
    }

    /**
     * Test findAndValidateExistingObject throws when locked by different user.
     *
     * @return void
     */
    public function testFindAndValidateExistingObjectThrowsWhenLockedByDifferentUser(): void
    {
        $entity = $this->createObjectEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        $entity->setLocked(['userId' => 'other-user']);

        $this->objectEntityMapper->method('find')
            ->willReturn($entity);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('current-user');
        $this->userSession->method('getUser')->willReturn($user);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Cannot update object: Object is locked by user');

        $this->invokePrivate('findAndValidateExistingObject', [
            'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            null,
            null,
            true,
            true,
        ]);
    }

    /**
     * Test findAndValidateExistingObject allows when locked by same user.
     *
     * @return void
     */
    public function testFindAndValidateExistingObjectAllowsLockBySameUser(): void
    {
        $entity = $this->createObjectEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        $entity->setLocked(['userId' => 'current-user']);

        $this->objectEntityMapper->method('find')
            ->willReturn($entity);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('current-user');
        $this->userSession->method('getUser')->willReturn($user);

        $result = $this->invokePrivate('findAndValidateExistingObject', [
            'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            null,
            null,
            true,
            true,
        ]);

        $this->assertSame($entity, $result);
    }

    /**
     * Test findAndValidateExistingObject allows lock when no current user.
     *
     * @return void
     */
    public function testFindAndValidateExistingObjectLockNoCurrentUser(): void
    {
        $entity = $this->createObjectEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        $entity->setLocked(['userId' => 'some-user']);

        $this->objectEntityMapper->method('find')
            ->willReturn($entity);

        $this->userSession->method('getUser')->willReturn(null);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Cannot update object: Object is locked');

        $this->invokePrivate('findAndValidateExistingObject', [
            'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            null,
            null,
            true,
            true,
        ]);
    }

    // =========================================================================
    // cascadeMultipleObjects
    // =========================================================================

    /**
     * Test cascadeMultipleObjects returns empty when propData is not a list.
     *
     * @return void
     */
    public function testCascadeMultipleObjectsReturnsEmptyForNonList(): void
    {
        $entity = $this->createObjectEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        $property = ['$ref' => '#/components/schemas/Sub', 'items' => ['$ref' => '#/components/schemas/Sub']];

        // Associative array is not a list.
        $result = $this->invokePrivate('cascadeMultipleObjects', [$entity, $property, ['key' => 'value']]);

        $this->assertSame([], $result);
    }

    /**
     * Test cascadeMultipleObjects returns empty when all items are UUIDs (strings).
     *
     * @return void
     */
    public function testCascadeMultipleObjectsReturnsEmptyForAllStringUuids(): void
    {
        $entity = $this->createObjectEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        $property = ['$ref' => '#/components/schemas/Sub', 'items' => ['$ref' => '#/components/schemas/Sub']];

        // All items are UUID strings - nothing to cascade.
        $result = $this->invokePrivate('cascadeMultipleObjects', [
            $entity,
            $property,
            ['aaaaaaaa-bbbb-cccc-dddd-111111111111', 'bbbbbbbb-cccc-dddd-eeee-222222222222'],
        ]);

        $this->assertSame([], $result);
    }

    /**
     * Test cascadeMultipleObjects returns empty when no valid objects.
     *
     * @return void
     */
    public function testCascadeMultipleObjectsReturnsEmptyForInvalidItems(): void
    {
        $entity = $this->createObjectEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        $property = ['$ref' => '#/components/schemas/Sub'];

        // Empty arrays and null are filtered out.
        $result = $this->invokePrivate('cascadeMultipleObjects', [$entity, $property, [[], null, false]]);

        $this->assertSame([], $result);
    }

    /**
     * Test cascadeMultipleObjects returns empty when no items $ref configured.
     *
     * @return void
     */
    public function testCascadeMultipleObjectsReturnsEmptyWhenNoItemsRef(): void
    {
        $entity = $this->createObjectEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        // No $ref at property or items level.
        $property = ['type' => 'array'];

        $result = $this->invokePrivate('cascadeMultipleObjects', [
            $entity,
            $property,
            [['name' => 'test']],
        ]);

        $this->assertSame([], $result);
    }

    /**
     * Test cascadeMultipleObjects with valid objects containing only empty id.
     *
     * @return void
     */
    public function testCascadeMultipleObjectsFiltersObjectsWithEmptyId(): void
    {
        $entity = $this->createObjectEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        $property = ['$ref' => '#/components/schemas/Sub', 'items' => ['$ref' => '#/components/schemas/Sub']];

        // Object with only empty id should be filtered out.
        $result = $this->invokePrivate('cascadeMultipleObjects', [
            $entity,
            $property,
            [['id' => '']],
        ]);

        $this->assertSame([], $result);
    }

    /**
     * Test cascadeMultipleObjects recognizes various identifier types.
     *
     * @return void
     */
    public function testCascadeMultipleObjectsRecognizesIdentifierTypes(): void
    {
        $entity = $this->createObjectEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        $property = ['$ref' => '#/components/schemas/Sub', 'items' => ['$ref' => '#/components/schemas/Sub']];

        $propData = [
            'aaaaaaaa-bbbb-cccc-dddd-111111111111',  // Standard UUID
            'aabbccddaabbccddaabbccddaabbccdd',       // UUID without dashes
            'id-aaaaaaaa-bbbb-cccc-dddd-111111111111', // Prefixed UUID
            '12345',                                    // Numeric ID
        ];

        // All are strings/identifiers, so nothing to cascade.
        $result = $this->invokePrivate('cascadeMultipleObjects', [$entity, $property, $propData]);

        $this->assertSame([], $result);
    }

    // =========================================================================
    // deleteOrphanedRelatedObjects
    // =========================================================================

    /**
     * Test deleteOrphanedRelatedObjects soft-deletes found objects.
     *
     * @return void
     */
    public function testDeleteOrphanedRelatedObjectsSoftDeletes(): void
    {
        $orphan = $this->createObjectEntity(10, 'aaaaaaaa-bbbb-cccc-dddd-111111111111');

        $this->objectEntityMapper->method('find')
            ->willReturn($orphan);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('test-user');
        $this->userSession->method('getUser')->willReturn($user);

        $this->objectEntityMapper->expects($this->once())
            ->method('update');

        $this->invokePrivate('deleteOrphanedRelatedObjects', [
            ['aaaaaaaa-bbbb-cccc-dddd-111111111111'],
            null,
            null,
        ]);
    }

    /**
     * Test deleteOrphanedRelatedObjects uses system user when no session user.
     *
     * @return void
     */
    public function testDeleteOrphanedRelatedObjectsSystemUser(): void
    {
        $orphan = $this->createObjectEntity(10, 'aaaaaaaa-bbbb-cccc-dddd-111111111111');

        $this->objectEntityMapper->method('find')
            ->willReturn($orphan);

        $this->userSession->method('getUser')->willReturn(null);

        $this->objectEntityMapper->expects($this->once())
            ->method('update');

        $this->invokePrivate('deleteOrphanedRelatedObjects', [
            ['aaaaaaaa-bbbb-cccc-dddd-111111111111'],
            null,
            null,
        ]);
    }

    /**
     * Test deleteOrphanedRelatedObjects handles DoesNotExistException.
     *
     * @return void
     */
    public function testDeleteOrphanedRelatedObjectsHandlesNotFound(): void
    {
        $this->objectEntityMapper->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        // Should not throw.
        $this->invokePrivate('deleteOrphanedRelatedObjects', [
            ['aaaaaaaa-bbbb-cccc-dddd-111111111111'],
            null,
            null,
        ]);

        $this->assertTrue(true);
    }

    /**
     * Test deleteOrphanedRelatedObjects handles general exception.
     *
     * @return void
     */
    public function testDeleteOrphanedRelatedObjectsHandlesGeneralException(): void
    {
        $this->objectEntityMapper->method('find')
            ->willThrowException(new Exception('db error'));

        $this->logger->expects($this->atLeastOnce())
            ->method('warning');

        // Should not throw.
        $this->invokePrivate('deleteOrphanedRelatedObjects', [
            ['aaaaaaaa-bbbb-cccc-dddd-111111111111'],
            null,
            null,
        ]);
    }

    // =========================================================================
    // preCacheParentName
    // =========================================================================

    /**
     * Test preCacheParentName returns early when UUID is null.
     *
     * @return void
     */
    public function testPreCacheParentNameReturnsWhenNullUuid(): void
    {
        $entity = $this->createObjectEntity(1, null);
        $schema = $this->createSchema(1);

        // Should not call hydration handler.
        $this->metaHydrationHandler->expects($this->never())
            ->method('hydrateObjectMetadata');

        $this->invokePrivate('preCacheParentName', [$entity, $schema, ['name' => 'Test']]);
    }

    /**
     * Test preCacheParentName caches name when hydration succeeds.
     *
     * @return void
     */
    public function testPreCacheParentNameCachesName(): void
    {
        $uuid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $entity = $this->createObjectEntity(1, $uuid);
        $schema = $this->createSchema(1);

        $this->metaHydrationHandler->expects($this->once())
            ->method('hydrateObjectMetadata')
            ->willReturnCallback(function ($e) {
                $e->setName('My Object Name');
            });

        $this->cacheHandler->expects($this->once())
            ->method('setObjectName')
            ->with($uuid, 'My Object Name');

        $this->invokePrivate('preCacheParentName', [$entity, $schema, ['name' => 'Test']]);
    }

    /**
     * Test preCacheParentName falls back to naam field.
     *
     * @return void
     */
    public function testPreCacheParentNameFallsBackToNaam(): void
    {
        $uuid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $entity = $this->createObjectEntity(1, $uuid);
        $schema = $this->createSchema(1);

        // Hydration does not set name.
        $this->metaHydrationHandler->expects($this->once())
            ->method('hydrateObjectMetadata');

        $this->cacheHandler->expects($this->once())
            ->method('setObjectName')
            ->with($uuid, 'Mijn Naam');

        $this->invokePrivate('preCacheParentName', [$entity, $schema, ['naam' => 'Mijn Naam']]);
    }

    /**
     * Test preCacheParentName handles hydration exception gracefully.
     *
     * @return void
     */
    public function testPreCacheParentNameHandlesHydrationException(): void
    {
        $uuid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $entity = $this->createObjectEntity(1, $uuid);
        $schema = $this->createSchema(1);

        $this->metaHydrationHandler->expects($this->once())
            ->method('hydrateObjectMetadata')
            ->willThrowException(new Exception('hydration failed'));

        // Should not throw.
        $this->invokePrivate('preCacheParentName', [$entity, $schema, ['name' => 'Test']]);

        $this->assertTrue(true);
    }

    // =========================================================================
    // clearImageMetadataIfFileProperty
    // =========================================================================

    /**
     * Test clearImageMetadataIfFileProperty returns when no objectImageField.
     *
     * @return void
     */
    public function testClearImageMetadataNoObjectImageField(): void
    {
        $entity = $this->createObjectEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        $schema = $this->createSchema(1);
        $schema->testConfiguration = [];

        $this->invokePrivate('clearImageMetadataIfFileProperty', [$entity, $schema]);

        // Entity image should remain unchanged (null by default).
        $this->assertNull($entity->getImage());
    }

    /**
     * Test clearImageMetadataIfFileProperty clears image when file property.
     *
     * @return void
     */
    public function testClearImageMetadataIfFilePropertyClearsWhenFile(): void
    {
        $entity = $this->createObjectEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        $entity->setImage('http://example.com/image.png');
        $schema = $this->createSchema(1);
        $schema->testConfiguration = ['objectImageField' => 'logo'];
        $schema->testProperties = ['logo' => ['type' => 'file']];

        $this->invokePrivate('clearImageMetadataIfFileProperty', [$entity, $schema]);

        $this->assertNull($entity->getImage());
    }

    /**
     * Test clearImageMetadataIfFileProperty does not clear when non-file property.
     *
     * @return void
     */
    public function testClearImageMetadataIfFilePropertySkipsNonFile(): void
    {
        $entity = $this->createObjectEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        $entity->setImage('http://example.com/image.png');
        $schema = $this->createSchema(1);
        $schema->testConfiguration = ['objectImageField' => 'logo'];
        $schema->testProperties = ['logo' => ['type' => 'string']];

        $this->invokePrivate('clearImageMetadataIfFileProperty', [$entity, $schema]);

        $this->assertSame('http://example.com/image.png', $entity->getImage());
    }

    // =========================================================================
    // resolveSchemaAndRegister
    // =========================================================================

    /**
     * Test resolveSchemaAndRegister with Schema and Register entities.
     *
     * @return void
     */
    public function testResolveSchemaAndRegisterWithEntities(): void
    {
        $schema = $this->createSchema(1);
        $register = $this->createRegister(2);

        $result = $this->invokePrivate('resolveSchemaAndRegister', [$schema, $register]);

        $this->assertSame($schema, $result[0]);
        $this->assertSame(1, $result[1]);
        $this->assertSame($register, $result[2]);
        $this->assertSame(2, $result[3]);
    }

    /**
     * Test resolveSchemaAndRegister with integer IDs.
     *
     * @return void
     */
    public function testResolveSchemaAndRegisterWithIntIds(): void
    {
        $schema = $this->createSchema(1);
        $register = $this->createRegister(2);

        $this->schemaMapper->method('find')->willReturn($schema);
        $this->registerMapper->method('find')->willReturn($register);

        $result = $this->invokePrivate('resolveSchemaAndRegister', [1, 2]);

        $this->assertSame(1, $result[1]);
        $this->assertSame(2, $result[3]);
    }

    /**
     * Test resolveSchemaAndRegister with null register.
     *
     * @return void
     */
    public function testResolveSchemaAndRegisterWithNullRegister(): void
    {
        $schema = $this->createSchema(1);

        $result = $this->invokePrivate('resolveSchemaAndRegister', [$schema, null]);

        $this->assertSame($schema, $result[0]);
        $this->assertNull($result[2]);
        $this->assertNull($result[3]);
    }

    /**
     * Test resolveSchemaAndRegister throws for invalid string schema.
     *
     * @return void
     */
    public function testResolveSchemaAndRegisterThrowsForInvalidStringSchema(): void
    {
        // resolveSchemaReference will return null for invalid schema.
        $this->schemaMapper->method('findAll')->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Could not resolve schema reference');

        $this->invokePrivate('resolveSchemaAndRegister', ['nonexistent-schema', null]);
    }

    /**
     * Test resolveSchemaAndRegister throws for invalid string register.
     *
     * @return void
     */
    public function testResolveSchemaAndRegisterThrowsForInvalidStringRegister(): void
    {
        $schema = $this->createSchema(1);

        $this->registerMapper->method('findAll')->willReturn([]);
        $this->registerMapper->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Could not resolve register reference');

        $this->invokePrivate('resolveSchemaAndRegister', [$schema, 'nonexistent-register']);
    }

    // =========================================================================
    // cascadeSingleObject
    // =========================================================================

    /**
     * Test cascadeSingleObject returns null when no $ref in definition.
     *
     * @return void
     */
    public function testCascadeSingleObjectReturnsNullWithoutRef(): void
    {
        $entity = $this->createObjectEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');

        $result = $this->invokePrivate('cascadeSingleObject', [$entity, [], ['name' => 'Test']]);

        $this->assertNull($result);
    }

    /**
     * Test cascadeSingleObject returns null for empty object.
     *
     * @return void
     */
    public function testCascadeSingleObjectReturnsNullForEmptyObject(): void
    {
        $entity = $this->createObjectEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        $definition = ['$ref' => '#/components/schemas/Sub'];

        $result = $this->invokePrivate('cascadeSingleObject', [$entity, $definition, []]);

        $this->assertNull($result);
    }

    /**
     * Test cascadeSingleObject returns null when parent UUID is empty.
     *
     * @return void
     */
    public function testCascadeSingleObjectReturnsNullWhenParentUuidEmpty(): void
    {
        $entity = $this->createObjectEntity(1, null);
        $definition = ['$ref' => '#/components/schemas/Sub'];

        $result = $this->invokePrivate('cascadeSingleObject', [$entity, $definition, ['name' => 'Test']]);

        $this->assertNull($result);
    }

    /**
     * Test cascadeSingleObject returns null for object with only empty id.
     *
     * @return void
     */
    public function testCascadeSingleObjectReturnsNullForObjectWithEmptyId(): void
    {
        $entity = $this->createObjectEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        $definition = ['$ref' => '#/components/schemas/Sub'];

        $result = $this->invokePrivate('cascadeSingleObject', [$entity, $definition, ['id' => '']]);

        $this->assertNull($result);
    }

    // =========================================================================
    // updateInverseRelations
    // =========================================================================

    /**
     * Test updateInverseRelations returns early when no relations.
     *
     * @return void
     */
    public function testUpdateInverseRelationsReturnsEarlyWhenEmpty(): void
    {
        $entity = $this->createObjectEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        $entity->setRelations([]);

        $register = $this->createRegister(1);
        $schema = $this->createSchema(1);
        $schema->testProperties = [];

        // Should not call objectEntityMapper.
        $this->objectEntityMapper->expects($this->never())
            ->method('update');

        $this->invokePrivate('updateInverseRelations', [$entity, $register, $schema]);
    }

    /**
     * Test updateInverseRelations skips non-UUID relations.
     *
     * @return void
     */
    public function testUpdateInverseRelationsSkipsNonUuidRelations(): void
    {
        $entity = $this->createObjectEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        $entity->setRelations(['field' => 'not-a-uuid']);

        $register = $this->createRegister(1);
        $schema = $this->createSchema(1);
        $schema->testProperties = ['field' => ['type' => 'string']];

        $this->objectEntityMapper->expects($this->never())
            ->method('update');

        $this->invokePrivate('updateInverseRelations', [$entity, $register, $schema]);
    }

    /**
     * Test updateInverseRelations skips when no property config.
     *
     * @return void
     */
    public function testUpdateInverseRelationsSkipsNoPropertyConfig(): void
    {
        $uuid = 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff';
        $entity = $this->createObjectEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        $entity->setRelations(['missingProp' => $uuid]);

        $register = $this->createRegister(1);
        $schema = $this->createSchema(1);
        $schema->testProperties = [];

        $this->objectEntityMapper->expects($this->never())
            ->method('update');

        $this->invokePrivate('updateInverseRelations', [$entity, $register, $schema]);
    }

    /**
     * Test updateInverseRelations skips when no target schema in $ref.
     *
     * @return void
     */
    public function testUpdateInverseRelationsSkipsNoTargetSchema(): void
    {
        $uuid = 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff';
        $entity = $this->createObjectEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        $entity->setRelations(['orgField' => $uuid]);

        $register = $this->createRegister(1);
        $schema = $this->createSchema(1);
        $schema->testProperties = [
            'orgField' => [
                'type' => 'string',
                // No $ref.
            ],
        ];

        $this->objectEntityMapper->expects($this->never())
            ->method('update');

        $this->invokePrivate('updateInverseRelations', [$entity, $register, $schema]);
    }

    /**
     * Test updateInverseRelations skips when null relations.
     *
     * @return void
     */
    public function testUpdateInverseRelationsWithNullRelations(): void
    {
        $entity = $this->createObjectEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        $entity->setRelations(null);

        $register = $this->createRegister(1);
        $schema = $this->createSchema(1);

        $this->objectEntityMapper->expects($this->never())
            ->method('update');

        $this->invokePrivate('updateInverseRelations', [$entity, $register, $schema]);
    }

    // =========================================================================
    // resolveRegisterReference - additional edge cases
    // =========================================================================

    /**
     * Test resolveRegisterReference with UUID reference.
     *
     * @return void
     */
    public function testResolveRegisterReferenceWithUuid(): void
    {
        $register = $this->createRegister(5);

        $this->registerMapper->expects($this->once())
            ->method('find')
            ->with('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee')
            ->willReturn($register);

        $result = $this->invokePrivate('resolveRegisterReference', ['aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee']);

        $this->assertSame('5', $result);
    }

    /**
     * Test resolveRegisterReference with UUID not found falls through to slug.
     *
     * @return void
     */
    public function testResolveRegisterReferenceUuidNotFoundFallsToSlug(): void
    {
        $register = $this->createRegister(5);
        $register->setSlug('myregister');

        $this->registerMapper->method('find')
            ->willReturnCallback(function ($id) use ($register) {
                if ($id === 'myregister') {
                    return $register;
                }
                throw new DoesNotExistException('not found');
            });

        $this->registerMapper->method('findAll')
            ->willReturn([$register]);

        $result = $this->invokePrivate('resolveRegisterReference', ['myregister']);

        $this->assertSame('5', $result);
    }

    // =========================================================================
    // resolveSchemaReference - additional edge cases
    // =========================================================================

    /**
     * Test resolveSchemaReference with query parameters in cleaned reference cache.
     *
     * @return void
     */
    public function testResolveSchemaReferenceWithQueryParamsAndCleanedCache(): void
    {
        // Pre-populate cache for cleaned reference.
        $this->setPrivateProperty('schemaReferenceCache', ['myschema' => '5']);

        $result = $this->invokePrivate('resolveSchemaReference', ['myschema?version=1']);

        $this->assertSame('5', $result);
    }

    /**
     * Test resolveSchemaReference with UUID that throws exception.
     *
     * @return void
     */
    public function testResolveSchemaReferenceUuidThrowsDoesNotExist(): void
    {
        $this->schemaMapper->method('find')
            ->willThrowException(new DoesNotExistException('not found'));
        $this->schemaMapper->method('findAll')
            ->willReturn([]);

        $result = $this->invokePrivate('resolveSchemaReference', ['aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee']);

        // Should cache null and return null.
        $this->assertNull($result);
    }

    // =========================================================================
    // extractUuidAndSelfData
    // =========================================================================

    /**
     * Test extractUuidAndSelfData with empty UUID normalizes to null.
     *
     * @return void
     */
    public function testExtractUuidAndSelfDataEmptyUuidToNull(): void
    {
        $data = ['name' => 'Test'];

        $result = $this->invokePrivate('extractUuidAndSelfData', [$data, '', null]);

        $this->assertNull($result[0]);
    }

    /**
     * Test extractUuidAndSelfData processes uploaded files.
     *
     * @return void
     */
    public function testExtractUuidAndSelfDataProcessesUploadedFiles(): void
    {
        $data = ['name' => 'Test'];
        $uploadedFiles = ['logo' => ['tmp_name' => '/tmp/file']];

        $this->filePropertyHandler->expects($this->once())
            ->method('processUploadedFiles')
            ->with($uploadedFiles, $this->anything())
            ->willReturn(['name' => 'Test', 'logo' => 'file-id']);

        $result = $this->invokePrivate('extractUuidAndSelfData', [$data, null, $uploadedFiles]);

        $this->assertSame('file-id', $result[2]['logo']);
    }

    /**
     * Test extractUuidAndSelfData extracts id from data.
     *
     * @return void
     */
    public function testExtractUuidAndSelfDataExtractsIdFromData(): void
    {
        $data = ['id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'name' => 'Test'];

        $result = $this->invokePrivate('extractUuidAndSelfData', [$data, null, null]);

        $this->assertSame('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $result[0]);
        $this->assertArrayNotHasKey('id', $result[2]);
    }

    /**
     * Test extractUuidAndSelfData extracts @self.id.
     *
     * @return void
     */
    public function testExtractUuidAndSelfDataExtractsSelfId(): void
    {
        $data = [
            '@self' => ['id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'],
            'name' => 'Test',
        ];

        $result = $this->invokePrivate('extractUuidAndSelfData', [$data, null, null]);

        $this->assertSame('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $result[0]);
        $this->assertArrayNotHasKey('@self', $result[2]);
    }

    // =========================================================================
    // setSelfMetadata - additional branches
    // =========================================================================

    /**
     * Test setSelfMetadata sets depublished date.
     *
     * @return void
     */
    public function testSetSelfMetadataWithDepublishedDate(): void
    {
        $entity = $this->createObjectEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        $selfData = ['depublished' => '2025-12-31'];

        $this->invokePrivate('setSelfMetadata', [$entity, $selfData, []]);

        $this->assertNotNull($entity->getDepublished());
    }

    /**
     * Test setSelfMetadata ignores invalid depublished date.
     *
     * @return void
     */
    public function testSetSelfMetadataWithInvalidDepublishedDate(): void
    {
        $entity = $this->createObjectEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        $selfData = ['depublished' => 'not-a-date-format!!!'];

        $this->invokePrivate('setSelfMetadata', [$entity, $selfData, []]);

        // Should still be null due to exception being silently caught.
        $this->assertNull($entity->getDepublished());
    }

    /**
     * Test setSelfMetadata sets owner.
     *
     * @return void
     */
    public function testSetSelfMetadataWithOwner(): void
    {
        $entity = $this->createObjectEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        $selfData = ['owner' => 'user123'];

        $this->invokePrivate('setSelfMetadata', [$entity, $selfData, []]);

        $this->assertSame('user123', $entity->getOwner());
    }

    /**
     * Test setSelfMetadata sets organisation.
     *
     * @return void
     */
    public function testSetSelfMetadataWithOrganisation(): void
    {
        $entity = $this->createObjectEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        $selfData = ['organisation' => 'org-uuid'];

        $this->invokePrivate('setSelfMetadata', [$entity, $selfData, []]);

        $this->assertSame('org-uuid', $entity->getOrganisation());
    }

    /**
     * Test setSelfMetadata sets published to null when empty.
     *
     * @return void
     */
    public function testSetSelfMetadataWithEmptyPublished(): void
    {
        $entity = $this->createObjectEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        $entity->setPublished(new DateTime());
        $selfData = ['published' => ''];

        $this->invokePrivate('setSelfMetadata', [$entity, $selfData, []]);

        $this->assertNull($entity->getPublished());
    }

    /**
     * Test setSelfMetadata sets depublished to null when not in selfData.
     *
     * @return void
     */
    public function testSetSelfMetadataNoDepublishedInSelfData(): void
    {
        $entity = $this->createObjectEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        $selfData = [];

        $this->invokePrivate('setSelfMetadata', [$entity, $selfData, []]);

        $this->assertNull($entity->getDepublished());
    }
}
