<?php

/**
 * SaveObject Refactored Methods Unit Tests
 *
 * Comprehensive tests for the private methods extracted during Phase 1 refactoring.
 * These tests protect the 411M NPath complexity reduction achieved.
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
 */

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectHandlers;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\LinkedEntityPropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\TmloService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Twig\Loader\ArrayLoader;
use Symfony\Component\Uid\Uuid;
use ReflectionClass;
use ReflectionMethod;
use stdClass;
use Psr\Log\LoggerInterface;

/**
 * Testable Schema subclass for SaveObjectRefactoredMethods tests.
 *
 * Allows overriding getSchemaObject, getConfiguration, and getProperties without relying
 * on PHPUnit mocks of Entity __call methods.
 */
class RefactoredTestableSchema extends Schema
{
    public ?stdClass $testSchemaObject = null;
    public ?array $testConfiguration = null;
    public ?array $testProperties = null;

    /**
     * Override getSchemaObject to return the test value.
     *
     * @param IURLGenerator $urlGenerator URL generator (unused in test double).
     *
     * @return stdClass
     */
    public function getSchemaObject(IURLGenerator $urlGenerator): stdClass
    {
        return $this->testSchemaObject ?? new stdClass();
    }

    /**
     * Override getConfiguration to return the test value.
     *
     * @return array|null
     */
    public function getConfiguration(): ?array
    {
        return $this->testConfiguration;
    }

    /**
     * Override getProperties to return the test value.
     *
     * @return array
     */
    public function getProperties(): array
    {
        return $this->testProperties ?? [];
    }

    /**
     * Override hasPropertyAuthorization to return false in tests.
     *
     * @return bool
     */
    public function hasPropertyAuthorization(): bool
    {
        return false;
    }
}

/**
 * Unit tests for SaveObject refactored methods.
 *
 * Tests the extracted private methods using reflection:
 * 1. extractUuidAndSelfData()
 * 2. resolveSchemaAndRegister()
 * 3. findAndValidateExistingObject()
 * 4. clearImageMetadataIfFileProperty()
 */
class SaveObjectRefactoredMethodsTest extends TestCase
{
    private SaveObject $saveObject;
    private ReflectionClass $reflection;

    /** @var MockObject|MagicMapper */
    private $objectEntityMapper;

    /** @var MockObject|MagicMapper */
    private $unifiedObjectMapper;

    /** @var MockObject|MetadataHydrationHandler */
    private $metaHydrationHandler;

    /** @var MockObject|FilePropertyHandler */
    private $filePropertyHandler;

    /** @var MockObject|IUserSession */
    private $userSession;

    /** @var MockObject|AuditTrailMapper */
    private $auditTrailMapper;

    /** @var MockObject|SchemaMapper */
    private $schemaMapper;

    /** @var MockObject|RegisterMapper */
    private $registerMapper;

    /** @var MockObject|IURLGenerator */
    private $urlGenerator;

    /** @var MockObject|OrganisationService */
    private $organisationService;

    /** @var MockObject|CacheHandler */
    private $cacheHandler;

    /** @var MockObject|SettingsService */
    private $settingsService;

    /** @var MockObject|PropertyRbacHandler */
    private $propertyRbacHandler;

    /** @var MockObject|LoggerInterface */
    private $logger;

    /** @var Register */
    private Register $mockRegister;

    /** @var RefactoredTestableSchema */
    private RefactoredTestableSchema $mockSchema;

    /** @var MockObject|IUser */
    private $mockUser;

    /**
     * Set up test environment before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create mocks for all dependencies.
        $this->objectEntityMapper = $this->createMock(MagicMapper::class);
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
        // ArrayLoader is final, so we create a real instance instead of mocking.
        $arrayLoader = new ArrayLoader([]);

        // Create real entity instances (Entity __call methods cannot be mocked in PHPUnit 10+).
        $this->mockRegister = new Register();
        $this->mockRegister->setId(1);
        $this->mockRegister->setSlug('test-register');

        $this->mockSchema = new RefactoredTestableSchema();
        $this->mockSchema->setId(1);
        $this->mockSchema->setSlug('test-schema');
        $this->mockSchema->testSchemaObject = (object)[
            'properties' => []
        ];

        $this->mockUser = $this->createMock(IUser::class);

        $this->mockUser->method('getUID')->willReturn('testuser');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        $this->organisationService->method('getOrganisationForNewEntity')->willReturn('org-123');

        // Create SaveObject instance with positional params (correct constructor order).
        $this->saveObject = new SaveObject(
            $this->objectEntityMapper,
            $this->unifiedObjectMapper,
            $this->metaHydrationHandler,
            $this->filePropertyHandler,
            $this->createMock(LinkedEntityPropertyHandler::class),
            $this->userSession,
            $this->auditTrailMapper,
            $this->schemaMapper,
            $this->registerMapper,
            $this->urlGenerator,
            $this->organisationService,
            $this->cacheHandler,
            $this->settingsService,
            $this->propertyRbacHandler,
            $this->createMock(ComputedFieldHandler::class),
            $this->createMock(TranslationHandler::class),
            $this->logger,
            $this->createMock(TmloService::class),
            $arrayLoader
        );

        // Set up reflection for accessing private methods.
        $this->reflection = new ReflectionClass(SaveObject::class);
    }

    /**
     * Helper method to invoke private methods using reflection.
     *
     * @param string $methodName The name of the private method.
     * @param array  $parameters The parameters to pass to the method.
     *
     * @return mixed The result of the method invocation.
     */
    private function invokePrivateMethod(string $methodName, array $parameters = []): mixed
    {
        $method = $this->reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($this->saveObject, $parameters);
    }

    // ==================== extractUuidAndSelfData() Tests ====================

    /**
     * Test extractUuidAndSelfData with data containing 'id' field.
     *
     * @return void
     */
    public function testExtractUuidAndSelfDataWithIdField(): void
    {
        $uuid = Uuid::v4()->toRfc4122();
        $data = [
            'id' => $uuid,
            'name' => 'Test Object'
        ];

        // Method signature: extractUuidAndSelfData(array $data, ?string $uuid, ?array $uploadedFiles)
        // Returns: [$uuid, $selfData, $data]
        [$extractedUuid, $selfData, $extractedData] = $this->invokePrivateMethod(
            'extractUuidAndSelfData',
            [$data, null, null]
        );

        $this->assertEquals($uuid, $extractedUuid, 'UUID should be extracted from id field.');
        $this->assertArrayNotHasKey('id', $extractedData, 'id should be removed from data.');
        $this->assertEquals('Test Object', $extractedData['name'], 'Other data should be preserved.');
    }

    /**
     * Test extractUuidAndSelfData with explicit UUID parameter.
     *
     * @return void
     */
    public function testExtractUuidAndSelfDataWithExplicitUuid(): void
    {
        $explicitUuid = Uuid::v4()->toRfc4122();
        $dataUuid = Uuid::v4()->toRfc4122();
        $data = [
            'id' => $dataUuid,
            'name' => 'Test Object'
        ];

        [$extractedUuid, $selfData, $extractedData] = $this->invokePrivateMethod(
            'extractUuidAndSelfData',
            [$data, $explicitUuid, null]
        );

        $this->assertEquals($explicitUuid, $extractedUuid, 'Explicit UUID parameter should take precedence.');
        $this->assertArrayNotHasKey('id', $extractedData, 'id should still be removed from data.');
    }

    /**
     * Test extractUuidAndSelfData without UUID returns null.
     *
     * @return void
     */
    public function testExtractUuidAndSelfDataWithoutUuid(): void
    {
        $data = ['name' => 'Test Object'];

        [$extractedUuid, $selfData, $extractedData] = $this->invokePrivateMethod(
            'extractUuidAndSelfData',
            [$data, null, null]
        );

        $this->assertNull($extractedUuid, 'UUID should be null when not provided in data or params.');
        $this->assertEquals('Test Object', $extractedData['name'], 'Data should be preserved.');
    }

    /**
     * Test extractUuidAndSelfData with @self data.
     *
     * @return void
     */
    public function testExtractUuidAndSelfDataWithSelfMetadata(): void
    {
        $uuid = Uuid::v4()->toRfc4122();
        $data = [
            '@self' => [
                'id' => $uuid,
                'type' => 'test'
            ],
            'name' => 'Test Object'
        ];

        [$extractedUuid, $selfData, $extractedData] = $this->invokePrivateMethod(
            'extractUuidAndSelfData',
            [$data, null, null]
        );

        $this->assertEquals($uuid, $extractedUuid, 'UUID should be extracted from @self.id.');
        $this->assertArrayNotHasKey('@self', $extractedData, '@self should be removed from data.');
        $this->assertIsArray($selfData, 'selfData should be an array.');
        $this->assertEquals('test', $selfData['type'], 'selfData should contain @self metadata.');
    }

    // ==================== resolveSchemaAndRegister() Tests ====================

    /**
     * Test resolveSchemaAndRegister with Register and Schema objects.
     *
     * @return void
     */
    public function testResolveSchemaAndRegisterWithObjects(): void
    {
        // Method signature: resolveSchemaAndRegister(Schema|int|string $schema, Register|int|string|null $register)
        // Returns: [$schema, $schemaId, $register, $registerId]
        $result = $this->invokePrivateMethod(
            'resolveSchemaAndRegister',
            [$this->mockSchema, $this->mockRegister]
        );

        $this->assertSame($this->mockSchema, $result[0], 'Schema should be returned as-is.');
        $this->assertEquals(1, $result[1], 'Schema ID should be 1.');
        $this->assertSame($this->mockRegister, $result[2], 'Register should be returned as-is.');
        $this->assertEquals(1, $result[3], 'Register ID should be 1.');
    }

    /**
     * Test resolveSchemaAndRegister with integer IDs.
     *
     * @return void
     */
    public function testResolveSchemaAndRegisterWithIntegerIds(): void
    {
        $this->schemaMapper
            ->expects($this->once())
            ->method('find')
            ->with(10)
            ->willReturn($this->mockSchema);

        $this->registerMapper
            ->expects($this->once())
            ->method('find')
            ->with(42)
            ->willReturn($this->mockRegister);

        $result = $this->invokePrivateMethod(
            'resolveSchemaAndRegister',
            [10, 42]
        );

        $this->assertSame($this->mockSchema, $result[0], 'Schema should be resolved by ID.');
        $this->assertEquals(10, $result[1], 'Schema ID should be 10.');
        $this->assertSame($this->mockRegister, $result[2], 'Register should be resolved by ID.');
        $this->assertEquals(42, $result[3], 'Register ID should be 42.');
    }

    /**
     * Test resolveSchemaAndRegister with null register.
     *
     * @return void
     */
    public function testResolveSchemaAndRegisterWithNullRegister(): void
    {
        $result = $this->invokePrivateMethod(
            'resolveSchemaAndRegister',
            [$this->mockSchema, null]
        );

        $this->assertSame($this->mockSchema, $result[0], 'Schema should be returned as-is.');
        $this->assertNull($result[2], 'Register should be null.');
        $this->assertNull($result[3], 'Register ID should be null.');
    }

    // ==================== findAndValidateExistingObject() Tests ====================

    /**
     * Test findAndValidateExistingObject returns existing object.
     *
     * @return void
     */
    public function testFindAndValidateExistingObjectReturnsExisting(): void
    {
        $uuid = Uuid::v4()->toRfc4122();
        $existingObject = new ObjectEntity();
        $existingObject->setUuid($uuid);
        $existingObject->setId(123);

        $this->objectEntityMapper
            ->expects($this->once())
            ->method('find')
            ->willReturn($existingObject);

        // Method signature: findAndValidateExistingObject(string $uuid, ?Register $register, ?Schema $schema, bool $_rbac, bool $_multitenancy)
        $result = $this->invokePrivateMethod(
            'findAndValidateExistingObject',
            [$uuid, null, null, false, false]
        );

        $this->assertSame($existingObject, $result, 'Should return existing object.');
    }

    /**
     * Test findAndValidateExistingObject returns null when not found.
     *
     * @return void
     */
    public function testFindAndValidateExistingObjectReturnsNullWhenNotFound(): void
    {
        $uuid = Uuid::v4()->toRfc4122();

        $this->objectEntityMapper
            ->expects($this->once())
            ->method('find')
            ->willThrowException(new DoesNotExistException('Not found.'));

        $result = $this->invokePrivateMethod(
            'findAndValidateExistingObject',
            [$uuid, null, null, false, false]
        );

        $this->assertNull($result, 'Should return null when object does not exist.');
    }

    // ==================== clearImageMetadataIfFileProperty() Tests ====================

    /**
     * Test clearImageMetadataIfFileProperty clears image on file property.
     *
     * @return void
     */
    public function testClearImageMetadataIfFilePropertyClearsImage(): void
    {
        // Set up schema with file property configured as objectImageField.
        $schema = new RefactoredTestableSchema();
        $schema->setId(1);
        $schema->testConfiguration = [
            'objectImageField' => 'avatar'
        ];
        $schema->testProperties = [
            'avatar' => [
                'type' => 'file'
            ]
        ];

        // Create an entity with image set.
        $savedEntity = new ObjectEntity();
        $savedEntity->setId(1);
        $savedEntity->setImage('http://example.com/avatar.jpg');

        // Method signature: clearImageMetadataIfFileProperty(ObjectEntity $savedEntity, Schema $schema)
        $this->invokePrivateMethod(
            'clearImageMetadataIfFileProperty',
            [$savedEntity, $schema]
        );

        $this->assertNull($savedEntity->getImage(), 'Image should be cleared when image field is a file property.');
    }

    /**
     * Test clearImageMetadataIfFileProperty preserves image for non-file properties.
     *
     * @return void
     */
    public function testClearImageMetadataIfFilePropertyPreservesNonFileImage(): void
    {
        // Set up schema WITHOUT file property for the image field.
        $schema = new RefactoredTestableSchema();
        $schema->setId(1);
        $schema->testConfiguration = [
            'objectImageField' => 'avatar'
        ];
        $schema->testProperties = [
            'avatar' => [
                'type' => 'string'
            ]
        ];

        $savedEntity = new ObjectEntity();
        $savedEntity->setId(1);
        $savedEntity->setImage('http://example.com/avatar.jpg');

        $this->invokePrivateMethod(
            'clearImageMetadataIfFileProperty',
            [$savedEntity, $schema]
        );

        $this->assertEquals(
            'http://example.com/avatar.jpg',
            $savedEntity->getImage(),
            'Image should be preserved for non-file properties.'
        );
    }

    /**
     * Test clearImageMetadataIfFileProperty does nothing without objectImageField config.
     *
     * @return void
     */
    public function testClearImageMetadataIfFilePropertyNoConfig(): void
    {
        $schema = new RefactoredTestableSchema();
        $schema->setId(1);
        $schema->testConfiguration = [];
        $schema->testProperties = [];

        $savedEntity = new ObjectEntity();
        $savedEntity->setId(1);
        $savedEntity->setImage('http://example.com/avatar.jpg');

        $this->invokePrivateMethod(
            'clearImageMetadataIfFileProperty',
            [$savedEntity, $schema]
        );

        $this->assertEquals(
            'http://example.com/avatar.jpg',
            $savedEntity->getImage(),
            'Image should be preserved when no objectImageField is configured.'
        );
    }

    // ==================== Integration Test ====================

    /**
     * Test that refactored saveObject still works end-to-end.
     *
     * This test verifies that all extracted methods work together correctly.
     *
     * @return void
     */
    public function testRefactoredSaveObjectIntegration(): void
    {
        $uuid = Uuid::v4()->toRfc4122();
        $data = [
            'name' => 'Integration Test Object',
            'description' => 'Testing refactored methods.'
        ];

        // Mock that object doesn't exist (create scenario).
        $this->objectEntityMapper
            ->method('find')
            ->willThrowException(new DoesNotExistException('Object not found.'));

        // Mock successful creation via MagicMapper (used by handleObjectCreation).
        $this->unifiedObjectMapper
            ->method('insert')
            ->willReturnCallback(function ($entity) {
                if ($entity->getId() === null) {
                    $entity->setId(1);
                }
                return $entity;
            });

        $this->urlGenerator
            ->method('getAbsoluteURL')
            ->willReturn('http://test.com/object/' . $uuid);

        $this->urlGenerator
            ->method('linkToRoute')
            ->willReturn('/object/' . $uuid);

        // Execute full saveObject method with positional params.
        $result = $this->saveObject->saveObject(
            $this->mockRegister,
            $this->mockSchema,
            $data,
            $uuid,
            null,
            false,
            false,
            true,
            true,
            false
        );

        // Assertions.
        $this->assertInstanceOf(ObjectEntity::class, $result, 'Should return ObjectEntity.');
        $this->assertEquals($uuid, $result->getUuid(), 'UUID should match.');
    }
}
