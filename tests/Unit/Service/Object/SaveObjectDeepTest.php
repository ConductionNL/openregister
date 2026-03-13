<?php

declare(strict_types=1);

/**
 * SaveObject Deep Coverage Tests
 *
 * Tests targeting uncovered lines in SaveObject.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\UnifiedObjectMapper;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use Twig\Loader\ArrayLoader;

/**
 * Deep coverage tests for SaveObject
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class SaveObjectDeepTest extends TestCase
{
    /** @var SaveObject */
    private SaveObject $handler;

    /** @var ObjectEntityMapper&MockObject */
    private ObjectEntityMapper $objectEntityMapper;

    /** @var UnifiedObjectMapper&MockObject */
    private UnifiedObjectMapper $unifiedObjectMapper;

    /** @var MetadataHydrationHandler&MockObject */
    private MetadataHydrationHandler $metaHydrationHandler;

    /** @var FilePropertyHandler&MockObject */
    private FilePropertyHandler $filePropertyHandler;

    /** @var IUserSession&MockObject */
    private IUserSession $userSession;

    /** @var AuditTrailMapper&MockObject */
    private AuditTrailMapper $auditTrailMapper;

    /** @var SchemaMapper&MockObject */
    private SchemaMapper $schemaMapper;

    /** @var RegisterMapper&MockObject */
    private RegisterMapper $registerMapper;

    /** @var IURLGenerator&MockObject */
    private IURLGenerator $urlGenerator;

    /** @var OrganisationService&MockObject */
    private OrganisationService $organisationService;

    /** @var CacheHandler&MockObject */
    private CacheHandler $cacheHandler;

    /** @var SettingsService&MockObject */
    private SettingsService $settingsService;

    /** @var PropertyRbacHandler&MockObject */
    private PropertyRbacHandler $propertyRbacHandler;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
        $this->unifiedObjectMapper = $this->createMock(UnifiedObjectMapper::class);
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

        $arrayLoader = new ArrayLoader();

        $this->handler = new SaveObject(
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
            $arrayLoader
        );
    }

    private function invokePrivateMethod(string $methodName, array $args = [])
    {
        $reflection = new ReflectionMethod(SaveObject::class, $methodName);
        $reflection->setAccessible(true);
        return $reflection->invoke($this->handler, ...$args);
    }

    private function setPrivateProperty(string $property, $value): void
    {
        $ref = new ReflectionClass(SaveObject::class);
        $prop = $ref->getProperty($property);
        $prop->setAccessible(true);
        $prop->setValue($this->handler, $value);
    }

    private function getPrivateProperty(string $property)
    {
        $ref = new ReflectionClass(SaveObject::class);
        $prop = $ref->getProperty($property);
        $prop->setAccessible(true);
        return $prop->getValue($this->handler);
    }

    private function createObjectEntity(
        int $id,
        string $uuid,
        array $objectData = [],
        string $register = '1',
        string $schema = '1'
    ): ObjectEntity {
        $entity = new ObjectEntity();
        $ref = new ReflectionClass($entity);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($entity, $id);
        $entity->setUuid($uuid);
        $entity->setObject($objectData);
        $entity->setRegister($register);
        $entity->setSchema($schema);
        return $entity;
    }

    /**
     * Creates a mock Schema using getMockBuilder for __call magic methods.
     */
    private function createMockSchema(
        int $id = 1,
        string $slug = 'test-schema',
        array $configuration = [],
        array $properties = []
    ): Schema {
        $schema = $this->getMockBuilder(Schema::class)
            ->onlyMethods(['getConfiguration', 'getProperties', 'getSchemaObject', 'hasPropertyAuthorization'])
            ->getMock();
        $schema->setId($id);
        $schema->setSlug($slug);
        $schema->method('getConfiguration')->willReturn($configuration);
        $schema->method('getProperties')->willReturn($properties);
        $schema->method('hasPropertyAuthorization')->willReturn(false);
        return $schema;
    }

    /**
     * Creates a mock Register using getMockBuilder for __call magic methods.
     */
    private function createMockRegister(int $id = 1, string $slug = 'test-register'): Register
    {
        $register = $this->getMockBuilder(Register::class)
            ->onlyMethods([])
            ->getMock();
        $register->setId($id);
        $register->setSlug($slug);
        return $register;
    }

    // ── resolveSchemaReference tests ────────────────────────────────────

    public function testResolveSchemaReferenceEmpty(): void
    {
        $result = $this->invokePrivateMethod('resolveSchemaReference', ['']);
        $this->assertNull($result);
    }

    public function testResolveSchemaReferenceCacheHit(): void
    {
        $this->setPrivateProperty('schemaReferenceCache', ['test-ref' => '42']);
        $result = $this->invokePrivateMethod('resolveSchemaReference', ['test-ref']);
        $this->assertSame('42', $result);
    }

    public function testResolveSchemaReferenceCleanedCacheHit(): void
    {
        $this->setPrivateProperty('schemaReferenceCache', ['ref' => '99']);
        $result = $this->invokePrivateMethod('resolveSchemaReference', ['ref?param=value']);
        $this->assertSame('99', $result);
    }

    public function testResolveSchemaReferenceNumericId(): void
    {
        $schema = $this->createMockSchema(42, 'num');

        $this->schemaMapper->method('find')
            ->willReturn($schema);

        $result = $this->invokePrivateMethod('resolveSchemaReference', ['42']);
        $this->assertSame('42', $result);
    }

    public function testResolveSchemaReferenceUuid(): void
    {
        $uuid = 'dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4';
        $schema = $this->createMockSchema(99, 'uuid-schema');

        $this->schemaMapper->method('find')
            ->willReturn($schema);

        $result = $this->invokePrivateMethod('resolveSchemaReference', [$uuid]);
        $this->assertSame('99', $result);
    }

    public function testResolveSchemaReferenceUuidNotFound(): void
    {
        $uuid = 'dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4';

        $this->schemaMapper->method('find')
            ->willThrowException(new DoesNotExistException('not found'));
        $this->schemaMapper->method('findAll')
            ->willReturn([]);

        $result = $this->invokePrivateMethod('resolveSchemaReference', [$uuid]);
        $this->assertNull($result);
    }

    public function testResolveSchemaReferencePathBySlug(): void
    {
        $ref = '#/components/schemas/Organisatie';
        $schema = $this->createMockSchema(5, 'organisatie');

        $this->schemaMapper->method('findAll')
            ->willReturn([$schema]);

        $result = $this->invokePrivateMethod('resolveSchemaReference', [$ref]);
        $this->assertSame('5', $result);
    }

    public function testResolveSchemaReferenceFindAllException(): void
    {
        $ref = '#/components/schemas/Broken';

        $this->schemaMapper->method('findAll')
            ->willThrowException(new Exception('DB error'));
        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('not found'));

        $result = $this->invokePrivateMethod('resolveSchemaReference', [$ref]);
        $this->assertNull($result);
    }

    public function testResolveSchemaReferenceDirectSlugMatch(): void
    {
        $slug = 'my-custom-schema';
        $schema = $this->createMockSchema(77, 'my-custom-schema');

        $this->schemaMapper->method('findAll')
            ->willReturn([]);
        $this->schemaMapper->method('find')
            ->willReturn($schema);

        $result = $this->invokePrivateMethod('resolveSchemaReference', [$slug]);
        $this->assertSame('77', $result);
    }

    public function testResolveSchemaReferenceCachesNull(): void
    {
        $this->schemaMapper->method('findAll')
            ->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('not found'));

        $result = $this->invokePrivateMethod('resolveSchemaReference', ['nonexistent']);
        $this->assertNull($result);

        $cache = $this->getPrivateProperty('schemaReferenceCache');
        $this->assertArrayHasKey('nonexistent', $cache);
        $this->assertNull($cache['nonexistent']);
    }

    // ── resolveRegisterReference tests ──────────────────────────────────

    public function testResolveRegisterReferenceEmpty(): void
    {
        $result = $this->invokePrivateMethod('resolveRegisterReference', ['']);
        $this->assertNull($result);
    }

    public function testResolveRegisterReferenceNumericId(): void
    {
        $register = $this->createMockRegister(5, 'num');

        $this->registerMapper->method('find')
            ->willReturn($register);

        $result = $this->invokePrivateMethod('resolveRegisterReference', ['5']);
        $this->assertSame('5', $result);
    }

    public function testResolveRegisterReferenceUuidNotFound(): void
    {
        $uuid = 'dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4';

        $this->registerMapper->method('find')
            ->willThrowException(new DoesNotExistException('not found'));
        $this->registerMapper->method('findAll')
            ->willReturn([]);

        $result = $this->invokePrivateMethod('resolveRegisterReference', [$uuid]);
        $this->assertNull($result);
    }

    public function testResolveRegisterReferenceSlug(): void
    {
        $register = $this->createMockRegister(3, 'publication');

        $this->registerMapper->method('findAll')
            ->willReturn([$register]);

        $result = $this->invokePrivateMethod('resolveRegisterReference', ['publication']);
        $this->assertSame('3', $result);
    }

    public function testResolveRegisterReferenceUrlPath(): void
    {
        $register = $this->createMockRegister(8, 'voorzieningen');

        $this->registerMapper->method('findAll')
            ->willReturn([$register]);

        $result = $this->invokePrivateMethod('resolveRegisterReference', [
            'http://example.com/api/registers/voorzieningen',
        ]);
        $this->assertSame('8', $result);
    }

    public function testResolveRegisterReferenceFindAllException(): void
    {
        $this->registerMapper->method('findAll')
            ->willThrowException(new Exception('DB error'));
        $this->registerMapper->method('find')
            ->willThrowException(new Exception('not found'));

        $result = $this->invokePrivateMethod('resolveRegisterReference', ['broken-slug']);
        $this->assertNull($result);
    }

    public function testResolveRegisterReferenceDirectSlug(): void
    {
        $register = $this->createMockRegister(11, 'custom');

        $this->registerMapper->method('findAll')
            ->willReturn([]);
        $this->registerMapper->method('find')
            ->willReturn($register);

        $result = $this->invokePrivateMethod('resolveRegisterReference', ['custom-register']);
        $this->assertSame('11', $result);
    }

    // ── removeQueryParameters tests ─────────────────────────────────────

    public function testRemoveQueryParametersNoParams(): void
    {
        $result = $this->invokePrivateMethod('removeQueryParameters', ['simple-ref']);
        $this->assertSame('simple-ref', $result);
    }

    public function testRemoveQueryParametersStrips(): void
    {
        $result = $this->invokePrivateMethod('removeQueryParameters', ['schema?key=val']);
        $this->assertSame('schema', $result);
    }

    // ── isReference tests ───────────────────────────────────────────────

    public function testIsReferenceEmpty(): void
    {
        $this->assertFalse($this->invokePrivateMethod('isReference', ['']));
    }

    public function testIsReferenceStandardUuid(): void
    {
        $this->assertTrue($this->invokePrivateMethod('isReference', ['dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4']));
    }

    public function testIsReferenceUuidNoDashes(): void
    {
        $this->assertTrue($this->invokePrivateMethod('isReference', ['dec9ac6ea4fd40fcbe5fe7ef6e5defb4']));
    }

    public function testIsReferencePrefixedUuid(): void
    {
        $this->assertTrue($this->invokePrivateMethod('isReference', ['id-dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4']));
    }

    public function testIsReferencePrefixedUuidNoDashes(): void
    {
        $this->assertTrue($this->invokePrivateMethod('isReference', ['ref-dec9ac6ea4fd40fcbe5fe7ef6e5defb4']));
    }

    public function testIsReferenceNumericId(): void
    {
        $this->assertTrue($this->invokePrivateMethod('isReference', ['12345']));
    }

    public function testIsReferenceUrl(): void
    {
        $this->assertTrue($this->invokePrivateMethod('isReference', ['https://example.com/api/object/123']));
    }

    public function testIsReferenceIdentifierPattern(): void
    {
        $this->assertTrue($this->invokePrivateMethod('isReference', ['some-long-identifier-value']));
    }

    public function testIsReferenceCommonWord(): void
    {
        $this->assertFalse($this->invokePrivateMethod('isReference', ['applicatie']));
    }

    public function testIsReferenceShortText(): void
    {
        $this->assertFalse($this->invokePrivateMethod('isReference', ['hello']));
    }

    public function testIsReferenceWithWhitespace(): void
    {
        $this->assertTrue($this->invokePrivateMethod('isReference', ['  dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4  ']));
    }

    // ── scanForRelations tests ──────────────────────────────────────────

    public function testScanForRelationsObjectPropertyType(): void
    {
        $schema = $this->getMockBuilder(Schema::class)
            ->onlyMethods(['getConfiguration', 'getProperties', 'getSchemaObject', 'hasPropertyAuthorization'])
            ->getMock();
        $schema->method('getSchemaObject')->willReturn((object) [
            'properties' => (object) [
                'org' => (object) ['type' => 'object'],
            ],
        ]);

        $data = ['org' => 'dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4'];
        $result = $this->handler->scanForRelations($data, '', $schema);
        $this->assertArrayHasKey('org', $result);
    }

    public function testScanForRelationsTextUuidFormat(): void
    {
        $schema = $this->getMockBuilder(Schema::class)
            ->onlyMethods(['getConfiguration', 'getProperties', 'getSchemaObject', 'hasPropertyAuthorization'])
            ->getMock();
        $schema->method('getSchemaObject')->willReturn((object) [
            'properties' => (object) [
                'ref' => (object) ['type' => 'text', 'format' => 'uuid'],
            ],
        ]);

        $data = ['ref' => 'dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4'];
        $result = $this->handler->scanForRelations($data, '', $schema);
        $this->assertArrayHasKey('ref', $result);
    }

    public function testScanForRelationsArrayOfObjectsWithStrings(): void
    {
        $schema = $this->getMockBuilder(Schema::class)
            ->onlyMethods(['getConfiguration', 'getProperties', 'getSchemaObject', 'hasPropertyAuthorization'])
            ->getMock();
        $schema->method('getSchemaObject')->willReturn((object) [
            'properties' => (object) [
                'items' => (object) [
                    'type' => 'array',
                    'items' => (object) ['type' => 'object'],
                ],
            ],
        ]);

        $data = [
            'items' => [
                'dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4',
                ['nested' => 'value'],
            ],
        ];

        $result = $this->handler->scanForRelations($data, '', $schema);
        $this->assertNotEmpty($result);
    }

    public function testScanForRelationsNonObjectArrayItems(): void
    {
        $data = [
            'tags' => ['dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4', 'not-a-ref'],
        ];
        $result = $this->handler->scanForRelations($data);
        $this->assertArrayHasKey('tags.0', $result);
    }

    public function testScanForRelationsWithPrefix(): void
    {
        $data = ['id' => 'dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4'];
        $result = $this->handler->scanForRelations($data, 'nested');
        $this->assertArrayHasKey('nested.id', $result);
    }

    public function testScanForRelationsSkipsNumericKeys(): void
    {
        $data = [0 => 'dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4'];
        $result = $this->handler->scanForRelations($data);
        $this->assertEmpty($result);
    }

    public function testScanForRelationsSchemaCatchesException(): void
    {
        $schema = $this->getMockBuilder(Schema::class)
            ->onlyMethods(['getConfiguration', 'getProperties', 'getSchemaObject', 'hasPropertyAuthorization'])
            ->getMock();
        $schema->method('getSchemaObject')
            ->willThrowException(new Exception('Schema error'));

        $data = ['field' => 'dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4'];
        $result = $this->handler->scanForRelations($data, '', $schema);
        $this->assertArrayHasKey('field', $result);
    }

    // ── shouldApplyDefault tests ────────────────────────────────────────

    public function testShouldApplyDefaultAlways(): void
    {
        $this->assertTrue($this->invokePrivateMethod('shouldApplyDefault', ['always', ['key' => 'value'], 'key']));
    }

    public function testShouldApplyDefaultFalsyEmptyString(): void
    {
        $this->assertTrue($this->invokePrivateMethod('shouldApplyDefault', ['falsy', ['key' => ''], 'key']));
    }

    public function testShouldApplyDefaultFalsyEmptyArray(): void
    {
        $this->assertTrue($this->invokePrivateMethod('shouldApplyDefault', ['falsy', ['key' => []], 'key']));
    }

    public function testShouldApplyDefaultFalsyNonEmpty(): void
    {
        $this->assertFalse($this->invokePrivateMethod('shouldApplyDefault', ['falsy', ['key' => 'value'], 'key']));
    }

    public function testShouldApplyDefaultMissingKey(): void
    {
        $this->assertTrue($this->invokePrivateMethod('shouldApplyDefault', ['false', ['other' => 'val'], 'key']));
    }

    public function testShouldApplyDefaultNullValue(): void
    {
        $this->assertTrue($this->invokePrivateMethod('shouldApplyDefault', ['false', ['key' => null], 'key']));
    }

    public function testShouldApplyDefaultExistingValue(): void
    {
        $this->assertFalse($this->invokePrivateMethod('shouldApplyDefault', ['false', ['key' => 'val'], 'key']));
    }

    // ── resolveDefaultTemplateValue tests ───────────────────────────────

    public function testResolveDefaultTemplateValueSimpleRefFound(): void
    {
        $result = $this->invokePrivateMethod('resolveDefaultTemplateValue', ['{{ name }}', ['name' => 'John'], []]);
        $this->assertSame('John', $result);
    }

    public function testResolveDefaultTemplateValueSimpleRefNotFound(): void
    {
        $result = $this->invokePrivateMethod('resolveDefaultTemplateValue', ['{{ missing }}', ['other' => 'val'], []]);
        $this->assertNull($result);
    }

    public function testResolveDefaultTemplateValuePreservesArray(): void
    {
        $result = $this->invokePrivateMethod('resolveDefaultTemplateValue', ['{{ items }}', ['items' => ['a', 'b']], []]);
        $this->assertSame(['a', 'b'], $result);
    }

    public function testResolveDefaultTemplateValueComplexTemplate(): void
    {
        $this->metaHydrationHandler->method('processTwigLikeTemplate')
            ->willReturn('John Doe');

        $result = $this->invokePrivateMethod('resolveDefaultTemplateValue', ['{{ first }} {{ last }}', ['first' => 'John', 'last' => 'Doe'], []]);
        $this->assertSame('John Doe', $result);
    }

    public function testResolveDefaultTemplateValueNonTemplate(): void
    {
        $result = $this->invokePrivateMethod('resolveDefaultTemplateValue', ['static-value', [], []]);
        $this->assertSame('static-value', $result);
    }

    public function testResolveDefaultTemplateValueNumeric(): void
    {
        $result = $this->invokePrivateMethod('resolveDefaultTemplateValue', [42, [], []]);
        $this->assertSame(42, $result);
    }

    public function testResolveDefaultTemplateValueException(): void
    {
        $this->metaHydrationHandler->method('processTwigLikeTemplate')
            ->willThrowException(new Exception('Template error'));

        $result = $this->invokePrivateMethod('resolveDefaultTemplateValue', ['{{ a }} {{ b }}', ['a' => 'x'], []]);
        $this->assertNull($result);
    }

    // ── createSlug tests ────────────────────────────────────────────────

    public function testCreateSlugSimple(): void
    {
        $this->assertSame('hello-world', $this->invokePrivateMethod('createSlug', ['Hello World']));
    }

    public function testCreateSlugSpecialChars(): void
    {
        $this->assertSame('hello-world-2024', $this->invokePrivateMethod('createSlug', ['Hello! @World# $2024']));
    }

    public function testCreateSlugLongTextTruncation(): void
    {
        $longText = str_repeat('a', 60);
        $result = $this->invokePrivateMethod('createSlug', [$longText]);
        $this->assertLessThanOrEqual(50, strlen($result));
    }

    public function testCreateSlugTrimsTrailingHyphens(): void
    {
        $text = str_repeat('a', 49) . ' b';
        $result = $this->invokePrivateMethod('createSlug', [$text]);
        $this->assertStringEndsNotWith('-', $result);
    }

    // ── generateSlug tests ──────────────────────────────────────────────

    public function testGenerateSlugNoConfig(): void
    {
        $schema = $this->createMockSchema(1, 'test', []);
        $this->assertNull($this->invokePrivateMethod('generateSlug', [['name' => 'test'], $schema]));
    }

    public function testGenerateSlugWithConfig(): void
    {
        $schema = $this->createMockSchema(1, 'test', ['objectSlugField' => 'name']);
        $result = $this->invokePrivateMethod('generateSlug', [['name' => 'Test Object'], $schema]);
        $this->assertNotNull($result);
        $this->assertStringStartsWith('test-object-', $result);
    }

    public function testGenerateSlugMissingFieldValue(): void
    {
        $schema = $this->createMockSchema(1, 'test', ['objectSlugField' => 'title']);
        $this->assertNull($this->invokePrivateMethod('generateSlug', [['name' => 'test'], $schema]));
    }

    public function testGenerateSlugException(): void
    {
        $schema = $this->getMockBuilder(Schema::class)
            ->onlyMethods(['getConfiguration', 'getProperties', 'getSchemaObject', 'hasPropertyAuthorization'])
            ->getMock();
        $schema->method('getConfiguration')->willThrowException(new Exception('Config error'));

        $this->assertNull($this->invokePrivateMethod('generateSlug', [['name' => 'test'], $schema]));
    }

    // ── getValueFromPath tests ──────────────────────────────────────────

    public function testGetValueFromPathNested(): void
    {
        $data = ['contact' => ['email' => 'test@test.nl']];
        $this->assertSame('test@test.nl', $this->invokePrivateMethod('getValueFromPath', [$data, 'contact.email']));
    }

    public function testGetValueFromPathMissing(): void
    {
        $this->assertNull($this->invokePrivateMethod('getValueFromPath', [['name' => 'test'], 'missing.path']));
    }

    public function testGetValueFromPathConvertsToString(): void
    {
        $this->assertSame('42', $this->invokePrivateMethod('getValueFromPath', [['count' => 42], 'count']));
    }

    // ── isEffectivelyEmptyObject tests ──────────────────────────────────

    public function testIsEffectivelyEmptyObjectEmpty(): void
    {
        $this->assertTrue($this->invokePrivateMethod('isEffectivelyEmptyObject', [[]]));
    }

    public function testIsEffectivelyEmptyObjectOnlyMetadata(): void
    {
        $this->assertTrue($this->invokePrivateMethod('isEffectivelyEmptyObject', [
            ['@self' => [], 'id' => '123', '_id' => 'abc'],
        ]));
    }

    public function testIsEffectivelyEmptyObjectWithData(): void
    {
        $this->assertFalse($this->invokePrivateMethod('isEffectivelyEmptyObject', [['name' => 'John']]));
    }

    public function testIsEffectivelyEmptyObjectEmptyValues(): void
    {
        $this->assertTrue($this->invokePrivateMethod('isEffectivelyEmptyObject', [
            ['name' => '', 'items' => [], 'val' => null],
        ]));
    }

    // ── hydrateObjectMetadata tests ─────────────────────────────────────

    public function testHydrateObjectMetadataImageArrayDownloadUrl(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1', [
            'photos' => [['downloadUrl' => 'https://example.com/photo.jpg']],
        ]);
        $schema = $this->createMockSchema(1, 'test', ['objectImageField' => 'photos']);

        $this->metaHydrationHandler->method('getValueFromPath')
            ->willReturn([['downloadUrl' => 'https://example.com/photo.jpg']]);

        $this->handler->hydrateObjectMetadata($entity, $schema);
        $this->assertSame('https://example.com/photo.jpg', $entity->getImage());
    }

    public function testHydrateObjectMetadataImageArrayAccessUrl(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1', [
            'photos' => [['accessUrl' => 'https://example.com/access.jpg']],
        ]);
        $schema = $this->createMockSchema(1, 'test', ['objectImageField' => 'photos']);

        $this->metaHydrationHandler->method('getValueFromPath')
            ->willReturn([['accessUrl' => 'https://example.com/access.jpg']]);

        $this->handler->hydrateObjectMetadata($entity, $schema);
        $this->assertSame('https://example.com/access.jpg', $entity->getImage());
    }

    public function testHydrateObjectMetadataNumericImageValue(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1', ['imageId' => 42]);
        $schema = $this->createMockSchema(1, 'test', ['objectImageField' => 'imageId']);

        $this->metaHydrationHandler->method('getValueFromPath')
            ->willReturn(42);

        $this->logger->expects($this->atLeastOnce())->method('debug');

        $this->handler->hydrateObjectMetadata($entity, $schema);
    }

    public function testHydrateObjectMetadataStringImageUrl(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1', ['photo' => 'https://example.com/image.png']);
        $schema = $this->createMockSchema(1, 'test', ['objectImageField' => 'photo']);

        $this->metaHydrationHandler->method('getValueFromPath')
            ->willReturn('https://example.com/image.png');

        $this->handler->hydrateObjectMetadata($entity, $schema);
        $this->assertSame('https://example.com/image.png', $entity->getImage());
    }

    public function testHydrateObjectMetadataPublishedDate(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1', ['publicatieDatum' => '2025-01-01']);
        $schema = $this->createMockSchema(1, 'test', ['objectPublishedField' => 'publicatieDatum']);

        $this->metaHydrationHandler->method('extractMetadataValue')
            ->willReturn('2025-01-01');

        $this->handler->hydrateObjectMetadata($entity, $schema);
        $this->assertInstanceOf(DateTime::class, $entity->getPublished());
    }

    public function testHydrateObjectMetadataInvalidPublishedDate(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1', ['date' => 'not-a-date']);
        $schema = $this->createMockSchema(1, 'test', ['objectPublishedField' => 'date']);

        $this->metaHydrationHandler->method('extractMetadataValue')
            ->willReturn('not-a-date');

        $this->logger->expects($this->atLeastOnce())->method('warning');

        $this->handler->hydrateObjectMetadata($entity, $schema);
    }

    public function testHydrateObjectMetadataDepublishedDate(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1', ['eindDatum' => '2025-12-31']);
        $schema = $this->createMockSchema(1, 'test', ['objectDepublishedField' => 'eindDatum']);

        $this->metaHydrationHandler->method('extractMetadataValue')
            ->willReturn('2025-12-31');

        $this->handler->hydrateObjectMetadata($entity, $schema);
        $this->assertInstanceOf(DateTime::class, $entity->getDepublished());
    }

    public function testHydrateObjectMetadataInvalidDepublishedDate(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1', ['end' => 'invalid']);
        $schema = $this->createMockSchema(1, 'test', ['objectDepublishedField' => 'end']);

        $this->metaHydrationHandler->method('extractMetadataValue')
            ->willReturn('invalid');

        $this->logger->expects($this->atLeastOnce())->method('warning');

        $this->handler->hydrateObjectMetadata($entity, $schema);
    }

    public function testHydrateObjectMetadataImageArrayNumericFileIds(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1', ['images' => [123, 456]]);
        $schema = $this->createMockSchema(1, 'test', ['objectImageField' => 'images']);

        $this->metaHydrationHandler->method('getValueFromPath')
            ->willReturn([123, 456]);

        $this->handler->hydrateObjectMetadata($entity, $schema);
        $this->assertTrue(true);
    }

    // ── clearAllCaches / cache management tests ─────────────────────────

    public function testClearAllCaches(): void
    {
        $this->setPrivateProperty('createdSubObjects', ['uuid' => ['data']]);
        $this->setPrivateProperty('schemaCache', ['1' => $this->createMockSchema()]);
        $this->setPrivateProperty('registerCache', ['1' => $this->createMockRegister()]);
        $this->setPrivateProperty('schemaReferenceCache', ['ref' => '1']);

        $this->handler->clearAllCaches();

        $this->assertEmpty($this->getPrivateProperty('createdSubObjects'));
        $this->assertEmpty($this->getPrivateProperty('schemaCache'));
        $this->assertEmpty($this->getPrivateProperty('registerCache'));
        $this->assertEmpty($this->getPrivateProperty('schemaReferenceCache'));
    }

    public function testTrackCreatedSubObject(): void
    {
        $this->handler->trackCreatedSubObject('sub-uuid', ['name' => 'child']);
        $subObjects = $this->handler->getCreatedSubObjects();
        $this->assertArrayHasKey('sub-uuid', $subObjects);
        $this->assertSame(['name' => 'child'], $subObjects['sub-uuid']);
    }

    public function testClearCreatedSubObjects(): void
    {
        $this->handler->trackCreatedSubObject('uuid', ['data']);
        $this->handler->clearCreatedSubObjects();
        $this->assertEmpty($this->handler->getCreatedSubObjects());
    }

    // ── getCachedSchema / getCachedRegister tests ───────────────────────

    public function testGetCachedSchemaFetchesAndCaches(): void
    {
        $schema = $this->createMockSchema(5, 'cached');

        $this->schemaMapper->expects($this->once())
            ->method('find')
            ->willReturn($schema);

        $result1 = $this->invokePrivateMethod('getCachedSchema', [5]);
        $result2 = $this->invokePrivateMethod('getCachedSchema', [5]);
        $this->assertSame($result1, $result2);
    }

    public function testGetCachedRegisterFetchesAndCaches(): void
    {
        $register = $this->createMockRegister(3, 'cached');

        $this->registerMapper->expects($this->once())
            ->method('find')
            ->willReturn($register);

        $result1 = $this->invokePrivateMethod('getCachedRegister', [3]);
        $result2 = $this->invokePrivateMethod('getCachedRegister', [3]);
        $this->assertSame($result1, $result2);
    }

    // ── applyAlwaysDefaults tests ───────────────────────────────────────

    public function testApplyAlwaysDefaultsNoAlwaysDefaults(): void
    {
        $schema = $this->getMockBuilder(Schema::class)
            ->onlyMethods(['getConfiguration', 'getProperties', 'getSchemaObject', 'hasPropertyAuthorization'])
            ->getMock();
        $schema->method('getSchemaObject')->willReturn((object) [
            'properties' => (object) [
                'name' => (object) ['default' => 'test', 'defaultBehavior' => 'false'],
            ],
        ]);

        $data = ['name' => 'existing'];
        $this->assertSame($data, $this->handler->applyAlwaysDefaults($schema, $data));
    }

    public function testApplyAlwaysDefaultsAppliesAlwaysDefaults(): void
    {
        $schema = $this->getMockBuilder(Schema::class)
            ->onlyMethods(['getConfiguration', 'getProperties', 'getSchemaObject', 'hasPropertyAuthorization'])
            ->getMock();
        $schema->method('getSchemaObject')->willReturn((object) [
            'properties' => (object) [
                'computed' => (object) ['default' => 'auto-value', 'defaultBehavior' => 'always'],
            ],
        ]);

        $data = ['other' => 'val'];
        $result = $this->handler->applyAlwaysDefaults($schema, $data);
        $this->assertSame('auto-value', $result['computed']);
    }

    public function testApplyAlwaysDefaultsSchemaException(): void
    {
        $schema = $this->getMockBuilder(Schema::class)
            ->onlyMethods(['getConfiguration', 'getProperties', 'getSchemaObject', 'hasPropertyAuthorization'])
            ->getMock();
        $schema->method('getSchemaObject')->willThrowException(new Exception('Schema error'));

        $data = ['name' => 'test'];
        $this->assertSame($data, $this->handler->applyAlwaysDefaults($schema, $data));
    }

    public function testApplyAlwaysDefaultsNoProperties(): void
    {
        $schema = $this->getMockBuilder(Schema::class)
            ->onlyMethods(['getConfiguration', 'getProperties', 'getSchemaObject', 'hasPropertyAuthorization'])
            ->getMock();
        $schema->method('getSchemaObject')->willReturn((object) []);

        $data = ['name' => 'test'];
        $this->assertSame($data, $this->handler->applyAlwaysDefaults($schema, $data));
    }

    // ── applyPropertyDefaults tests ─────────────────────────────────────

    public function testApplyPropertyDefaultsAppliesForMissing(): void
    {
        $schema = $this->getMockBuilder(Schema::class)
            ->onlyMethods(['getConfiguration', 'getProperties', 'getSchemaObject', 'hasPropertyAuthorization'])
            ->getMock();
        $schema->method('getSchemaObject')->willReturn((object) [
            'properties' => (object) [
                'status' => (object) ['default' => 'active', 'defaultBehavior' => 'false'],
            ],
        ]);

        $data = ['name' => 'test'];
        $result = $this->handler->applyPropertyDefaults($schema, $data);
        $this->assertSame('active', $result['status']);
    }

    public function testApplyPropertyDefaultsSkipsExisting(): void
    {
        $schema = $this->getMockBuilder(Schema::class)
            ->onlyMethods(['getConfiguration', 'getProperties', 'getSchemaObject', 'hasPropertyAuthorization'])
            ->getMock();
        $schema->method('getSchemaObject')->willReturn((object) [
            'properties' => (object) [
                'status' => (object) ['default' => 'active', 'defaultBehavior' => 'false'],
            ],
        ]);

        $data = ['status' => 'inactive'];
        $result = $this->handler->applyPropertyDefaults($schema, $data);
        $this->assertSame('inactive', $result['status']);
    }

    public function testApplyPropertyDefaultsSchemaException(): void
    {
        $schema = $this->getMockBuilder(Schema::class)
            ->onlyMethods(['getConfiguration', 'getProperties', 'getSchemaObject', 'hasPropertyAuthorization'])
            ->getMock();
        $schema->method('getSchemaObject')->willThrowException(new Exception('error'));

        $data = ['name' => 'test'];
        $this->assertSame($data, $this->handler->applyPropertyDefaults($schema, $data));
    }

    // ── updateObjectRelations tests ─────────────────────────────────────

    public function testUpdateObjectRelations(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1', [
            'ref' => 'dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4',
        ]);

        $result = $this->invokePrivateMethod('updateObjectRelations', [
            $entity,
            ['ref' => 'dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4'],
            null,
        ]);

        $relations = $result->getRelations();
        $this->assertNotEmpty($relations);
        $this->assertArrayHasKey('ref', $relations);
    }

    // ── setSelfMetadata tests ───────────────────────────────────────────

    public function testSetSelfMetadataSlug(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1');
        $this->invokePrivateMethod('setSelfMetadata', [$entity, ['slug' => 'my-slug'], []]);
        $this->assertSame('my-slug', $entity->getSlug());
    }

    public function testSetSelfMetadataPublishedDate(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1');
        $this->invokePrivateMethod('setSelfMetadata', [$entity, ['published' => '2025-06-15T10:00:00+00:00'], []]);
        $this->assertInstanceOf(DateTime::class, $entity->getPublished());
    }

    public function testSetSelfMetadataPublishedEmpty(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1');
        $entity->setPublished(new DateTime());
        $this->invokePrivateMethod('setSelfMetadata', [$entity, ['published' => ''], []]);
        $this->assertNull($entity->getPublished());
    }

    public function testSetSelfMetadataPublishedNotPresent(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1');
        $originalDate = new DateTime('2025-01-01');
        $entity->setPublished($originalDate);
        $this->invokePrivateMethod('setSelfMetadata', [$entity, [], []]);
        $this->assertEquals($originalDate, $entity->getPublished());
    }

    public function testSetSelfMetadataInvalidPublishedDate(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1');
        $this->invokePrivateMethod('setSelfMetadata', [$entity, ['published' => 'not-a-date-at-all!!!'], []]);
        $this->assertTrue(true);
    }

    public function testSetSelfMetadataDepublishedDate(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1');
        $this->invokePrivateMethod('setSelfMetadata', [$entity, ['depublished' => '2025-12-31T23:59:59+00:00'], []]);
        $this->assertInstanceOf(DateTime::class, $entity->getDepublished());
    }

    public function testSetSelfMetadataDepublishedEmpty(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1');
        $entity->setDepublished(new DateTime());
        $this->invokePrivateMethod('setSelfMetadata', [$entity, ['depublished' => ''], []]);
        $this->assertNull($entity->getDepublished());
    }

    public function testSetSelfMetadataOwner(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1');
        $this->invokePrivateMethod('setSelfMetadata', [$entity, ['owner' => 'admin'], []]);
        $this->assertSame('admin', $entity->getOwner());
    }

    public function testSetSelfMetadataOrganisation(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1');
        $this->invokePrivateMethod('setSelfMetadata', [$entity, ['organisation' => 'org-uuid'], []]);
        $this->assertSame('org-uuid', $entity->getOrganisation());
    }

    public function testSetSelfMetadataSlugFromData(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1');
        $this->invokePrivateMethod('setSelfMetadata', [$entity, [], ['slug' => 'data-slug']]);
        $this->assertSame('data-slug', $entity->getSlug());
    }

    public function testSetSelfMetadataDepublishedNotPresent(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1');
        $this->invokePrivateMethod('setSelfMetadata', [$entity, [], []]);
        $this->assertNull($entity->getDepublished());
    }
}
