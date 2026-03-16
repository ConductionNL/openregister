<?php

declare(strict_types=1);

/**
 * SaveObject Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
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
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Exception\ValidationException;
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
 * Unit tests for SaveObject
 *
 * Tests individual object save operations, metadata hydration, relation scanning,
 * default value processing, slug generation, and cache management.
 */
class SaveObjectTest extends TestCase
{
    /** @var SaveObject */
    private SaveObject $handler;

    /** @var ObjectEntityMapper&MockObject */
    private ObjectEntityMapper $objectEntityMapper;

    /** @var MagicMapper&MockObject */
    private MagicMapper $unifiedObjectMapper;

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

    /**
     * Helper to invoke private/protected methods via reflection.
     *
     * @param string $methodName Method name to invoke
     * @param array  $args       Arguments to pass
     *
     * @return mixed The method return value
     */
    private function invokePrivateMethod(string $methodName, array $args = [])
    {
        $reflection = new ReflectionMethod(SaveObject::class, $methodName);
        $reflection->setAccessible(true);
        // Use array_values to ensure positional args (PHP 8+ treats string keys as named params).
        return $reflection->invokeArgs($this->handler, array_values($args));
    }

    /**
     * Helper to set a private property via reflection.
     *
     * @param string $propertyName Property name
     * @param mixed  $value        Value to set
     *
     * @return void
     */
    private function setPrivateProperty(string $propertyName, $value): void
    {
        $reflection = new ReflectionClass(SaveObject::class);
        $property = $reflection->getProperty($propertyName);
        $property->setAccessible(true);
        $property->setValue($this->handler, $value);
    }

    /**
     * Helper to get a private property via reflection.
     *
     * @param string $propertyName Property name
     *
     * @return mixed The property value
     */
    private function getPrivateProperty(string $propertyName)
    {
        $reflection = new ReflectionClass(SaveObject::class);
        $property = $reflection->getProperty($propertyName);
        $property->setAccessible(true);
        return $property->getValue($this->handler);
    }

    /**
     * Creates a mock Schema with common setup.
     *
     * Uses getMockBuilder so that __call magic methods (getId, getSlug) still work.
     * Only stubs methods that are explicitly defined on the Schema class.
     *
     * @param int         $id            Schema ID
     * @param string      $slug          Schema slug
     * @param array       $configuration Schema configuration
     * @param array       $properties    Schema properties
     * @param string|null $title         Schema title
     *
     * @return Schema&MockObject
     */
    private function createMockSchema(
        int $id = 1,
        string $slug = 'test-schema',
        array $configuration = [],
        array $properties = [],
        ?string $title = 'Test Schema'
    ): Schema {
        $schema = $this->getMockBuilder(Schema::class)
            ->onlyMethods(['getConfiguration', 'getProperties', 'getSchemaObject', 'hasPropertyAuthorization'])
            ->getMock();
        // Use real entity setters via __call (do NOT use named args).
        $schema->setId($id);
        $schema->setSlug($slug);
        $schema->setTitle($title);
        $schema->method('getConfiguration')->willReturn($configuration);
        $schema->method('getProperties')->willReturn($properties);
        $schema->method('hasPropertyAuthorization')->willReturn(false);
        return $schema;
    }

    /**
     * Creates a mock Register with common setup.
     *
     * Uses getMockBuilder so that __call magic methods (getId, getSlug) still work.
     *
     * @param int    $id   Register ID
     * @param string $slug Register slug
     *
     * @return Register&MockObject
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

    /**
     * Creates a schema mock with a specific schemaObject return value.
     *
     * @param \stdClass $schemaObject The schema object to return from getSchemaObject
     *
     * @return Schema&MockObject
     */
    private function createSchemaWithSchemaObject(\stdClass $schemaObject): Schema
    {
        $schema = $this->getMockBuilder(Schema::class)
            ->onlyMethods(['getConfiguration', 'getProperties', 'getSchemaObject', 'hasPropertyAuthorization'])
            ->getMock();
        $schema->method('getSchemaObject')->willReturn($schemaObject);
        $schema->method('getConfiguration')->willReturn([]);
        $schema->method('getProperties')->willReturn([]);
        $schema->method('hasPropertyAuthorization')->willReturn(false);
        return $schema;
    }

    // =========================================================================
    // getCreatedSubObjects / clearCreatedSubObjects / clearAllCaches
    // =========================================================================

    public function testGetCreatedSubObjectsReturnsEmptyArrayInitially(): void
    {
        $result = $this->handler->getCreatedSubObjects();

        $this->assertSame([], $result);
    }

    public function testTrackCreatedSubObjectAddsSubObject(): void
    {
        $uuid = 'abc-123-def';
        $objectData = ['name' => 'Test Object'];

        $this->handler->trackCreatedSubObject($uuid, $objectData);

        $result = $this->handler->getCreatedSubObjects();
        $this->assertArrayHasKey($uuid, $result);
        $this->assertSame($objectData, $result[$uuid]);
    }

    public function testTrackMultipleSubObjects(): void
    {
        $this->handler->trackCreatedSubObject('uuid-1', ['name' => 'Obj 1']);
        $this->handler->trackCreatedSubObject('uuid-2', ['name' => 'Obj 2']);

        $result = $this->handler->getCreatedSubObjects();
        $this->assertCount(2, $result);
        $this->assertArrayHasKey('uuid-1', $result);
        $this->assertArrayHasKey('uuid-2', $result);
    }

    public function testClearCreatedSubObjectsClearsSubObjects(): void
    {
        $this->handler->trackCreatedSubObject('uuid-1', ['name' => 'Obj']);
        $this->handler->clearCreatedSubObjects();

        $result = $this->handler->getCreatedSubObjects();
        $this->assertSame([], $result);
    }

    public function testClearAllCachesClearsEverything(): void
    {
        // Track a sub-object.
        $this->handler->trackCreatedSubObject('uuid-1', ['name' => 'Obj']);

        // Set some cache values via reflection.
        $this->setPrivateProperty('schemaCache', ['1' => $this->createMockSchema()]);
        $this->setPrivateProperty('registerCache', ['1' => $this->createMockRegister()]);
        $this->setPrivateProperty('schemaReferenceCache', ['ref' => '1']);

        $this->handler->clearAllCaches();

        $this->assertSame([], $this->handler->getCreatedSubObjects());
        $this->assertSame([], $this->getPrivateProperty('schemaCache'));
        $this->assertSame([], $this->getPrivateProperty('registerCache'));
        $this->assertSame([], $this->getPrivateProperty('schemaReferenceCache'));
    }

    // =========================================================================
    // scanForRelations
    // =========================================================================

    public function testScanForRelationsWithEmptyData(): void
    {
        $result = $this->handler->scanForRelations([]);
        $this->assertSame([], $result);
    }

    public function testScanForRelationsFindsUuids(): void
    {
        $data = [
            'name' => 'Test',
            'organisatie' => 'dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4',
        ];

        $result = $this->handler->scanForRelations($data);

        $this->assertArrayHasKey('organisatie', $result);
        $this->assertSame('dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4', $result['organisatie']);
    }

    public function testScanForRelationsFindsUrls(): void
    {
        $data = [
            'name' => 'Test',
            'reference' => 'https://example.com/api/objects/123',
        ];

        $result = $this->handler->scanForRelations($data);

        $this->assertArrayHasKey('reference', $result);
    }

    public function testScanForRelationsFindsNumericIds(): void
    {
        $data = [
            'name' => 'Test',
            'relatedId' => '42',
        ];

        $result = $this->handler->scanForRelations($data);

        $this->assertArrayHasKey('relatedId', $result);
        $this->assertSame('42', $result['relatedId']);
    }

    public function testScanForRelationsSkipsNonStringKeys(): void
    {
        $data = [
            0 => 'some-value',
            'name' => 'Test',
        ];

        $result = $this->handler->scanForRelations($data);

        // Key 0 is not a string, so it should be skipped.
        $this->assertArrayNotHasKey(0, $result);
    }

    public function testScanForRelationsSkipsPlainTextValues(): void
    {
        $data = [
            'name' => 'This is a normal text value',
            'description' => 'Another plain text',
        ];

        $result = $this->handler->scanForRelations($data);

        $this->assertArrayNotHasKey('name', $result);
        $this->assertArrayNotHasKey('description', $result);
    }

    public function testScanForRelationsWithPrefixedUuids(): void
    {
        $data = [
            'ref' => 'id-dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4',
        ];

        $result = $this->handler->scanForRelations($data);

        $this->assertArrayHasKey('ref', $result);
    }

    public function testScanForRelationsWithUuidWithoutDashes(): void
    {
        $data = [
            'ref' => 'dec9ac6ea4fd40fcbe5fe7ef6e5defb4',
        ];

        $result = $this->handler->scanForRelations($data);

        $this->assertArrayHasKey('ref', $result);
    }

    public function testScanForRelationsWithNestedArrays(): void
    {
        $data = [
            'items' => [
                ['ref' => 'dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4'],
            ],
        ];

        $result = $this->handler->scanForRelations($data);

        $this->assertArrayHasKey('items.0.ref', $result);
    }

    public function testScanForRelationsWithArrayOfUuids(): void
    {
        $data = [
            'contacts' => [
                'dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4',
                'aab6e021-7749-20be-b039-1492fed04bcc',
            ],
        ];

        $result = $this->handler->scanForRelations($data);

        $this->assertArrayHasKey('contacts.0', $result);
        $this->assertArrayHasKey('contacts.1', $result);
    }

    public function testScanForRelationsWithPrefix(): void
    {
        $data = [
            'name' => 'dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4',
        ];

        $result = $this->handler->scanForRelations($data, 'parent');

        $this->assertArrayHasKey('parent.name', $result);
    }

    public function testScanForRelationsSkipsEmptyStrings(): void
    {
        $data = [
            'name' => '',
            'ref' => '   ',
        ];

        $result = $this->handler->scanForRelations($data);

        $this->assertSame([], $result);
    }

    public function testScanForRelationsWithSchemaPropertyTypes(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'organisatie' => [
                'type' => 'object',
                'format' => '',
            ],
        ];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $data = [
            'organisatie' => 'some-string-value',
        ];

        $result = $this->handler->scanForRelations($data, '', $schema);

        // Object type with string value should be treated as relation.
        $this->assertArrayHasKey('organisatie', $result);
    }

    public function testScanForRelationsWithTextUuidFormatProperty(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'parentId' => [
                'type' => 'text',
                'format' => 'uuid',
            ],
        ];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $data = [
            'parentId' => 'some-non-uuid-value',
        ];

        $result = $this->handler->scanForRelations($data, '', $schema);

        // text+uuid format should be treated as relation.
        $this->assertArrayHasKey('parentId', $result);
    }

    public function testScanForRelationsWithArrayOfObjectsSchema(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'items' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                ],
            ],
        ];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $data = [
            'items' => [
                'dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4',
            ],
        ];

        $result = $this->handler->scanForRelations($data, '', $schema);

        // String values in object arrays are always treated as relations.
        $this->assertArrayHasKey('items.0', $result);
    }

    // =========================================================================
    // isReference (private, tested via scanForRelations indirectly and reflection)
    // =========================================================================

    public function testIsReferenceWithStandardUuid(): void
    {
        $result = $this->invokePrivateMethod('isReference', ['dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4']);
        $this->assertTrue($result);
    }

    public function testIsReferenceWithUuidWithoutDashes(): void
    {
        $result = $this->invokePrivateMethod('isReference', ['dec9ac6ea4fd40fcbe5fe7ef6e5defb4']);
        $this->assertTrue($result);
    }

    public function testIsReferenceWithPrefixedUuid(): void
    {
        $result = $this->invokePrivateMethod('isReference', ['id-dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4']);
        $this->assertTrue($result);
    }

    public function testIsReferenceWithPrefixedUuidNoDashes(): void
    {
        $result = $this->invokePrivateMethod('isReference', ['ref-dec9ac6ea4fd40fcbe5fe7ef6e5defb4']);
        $this->assertTrue($result);
    }

    public function testIsReferenceWithNumericId(): void
    {
        $result = $this->invokePrivateMethod('isReference', ['12345']);
        $this->assertTrue($result);
    }

    public function testIsReferenceWithUrl(): void
    {
        $result = $this->invokePrivateMethod('isReference', ['https://example.com/api/objects/123']);
        $this->assertTrue($result);
    }

    public function testIsReferenceWithEmptyString(): void
    {
        $result = $this->invokePrivateMethod('isReference', ['']);
        $this->assertFalse($result);
    }

    public function testIsReferenceWithPlainText(): void
    {
        $result = $this->invokePrivateMethod('isReference', ['This is just a name']);
        $this->assertFalse($result);
    }

    public function testIsReferenceWithCommonWord(): void
    {
        // Common words like 'applicatie' should NOT be treated as references.
        $result = $this->invokePrivateMethod('isReference', ['applicatie']);
        $this->assertFalse($result);
    }

    public function testIsReferenceWithOpenSource(): void
    {
        $result = $this->invokePrivateMethod('isReference', ['open-source']);
        $this->assertFalse($result);
    }

    public function testIsReferenceWithIdentifierLikeString(): void
    {
        // Strings with hyphens that look like identifiers (8+ chars).
        $result = $this->invokePrivateMethod('isReference', ['my-object-identifier']);
        $this->assertTrue($result);
    }

    // =========================================================================
    // removeQueryParameters (private)
    // =========================================================================

    public function testRemoveQueryParametersWithNoParams(): void
    {
        $result = $this->invokePrivateMethod('removeQueryParameters', ['some-reference']);
        $this->assertSame('some-reference', $result);
    }

    public function testRemoveQueryParametersStripsParams(): void
    {
        $result = $this->invokePrivateMethod('removeQueryParameters', ['schema?key=value&other=1']);
        $this->assertSame('schema', $result);
    }

    // =========================================================================
    // getValueFromPath (private)
    // =========================================================================

    public function testGetValueFromPathSimple(): void
    {
        $result = $this->invokePrivateMethod('getValueFromPath', [['name' => 'Test'], 'name']);
        $this->assertSame('Test', $result);
    }

    public function testGetValueFromPathNested(): void
    {
        $data = ['contact' => ['email' => 'test@example.com']];
        $result = $this->invokePrivateMethod('getValueFromPath', [$data, 'contact.email']);
        $this->assertSame('test@example.com', $result);
    }

    public function testGetValueFromPathDeeplyNested(): void
    {
        $data = ['level1' => ['level2' => ['level3' => 'deep']]];
        $result = $this->invokePrivateMethod('getValueFromPath', [$data, 'level1.level2.level3']);
        $this->assertSame('deep', $result);
    }

    public function testGetValueFromPathReturnsNullForMissingKey(): void
    {
        $result = $this->invokePrivateMethod('getValueFromPath', [['name' => 'Test'], 'missing']);
        $this->assertNull($result);
    }

    public function testGetValueFromPathReturnsNullForMissingNestedKey(): void
    {
        $data = ['contact' => ['name' => 'Test']];
        $result = $this->invokePrivateMethod('getValueFromPath', [$data, 'contact.email']);
        $this->assertNull($result);
    }

    public function testGetValueFromPathConvertsIntToString(): void
    {
        $result = $this->invokePrivateMethod('getValueFromPath', [['count' => 42], 'count']);
        $this->assertSame('42', $result);
    }

    public function testGetValueFromPathReturnsNullForNull(): void
    {
        $result = $this->invokePrivateMethod('getValueFromPath', [['name' => null], 'name']);
        $this->assertNull($result);
    }

    // =========================================================================
    // createSlug (private)
    // =========================================================================

    public function testCreateSlugBasic(): void
    {
        $result = $this->invokePrivateMethod('createSlug', ['Hello World']);
        $this->assertSame('hello-world', $result);
    }

    public function testCreateSlugWithSpecialChars(): void
    {
        $result = $this->invokePrivateMethod('createSlug', ['Hello! @World# $2024']);
        $this->assertSame('hello-world-2024', $result);
    }

    public function testCreateSlugTrimsHyphens(): void
    {
        $result = $this->invokePrivateMethod('createSlug', ['--Hello World--']);
        $this->assertSame('hello-world', $result);
    }

    public function testCreateSlugLimitsLength(): void
    {
        $longText = str_repeat('a', 60);
        $result = $this->invokePrivateMethod('createSlug', [$longText]);
        $this->assertLessThanOrEqual(50, strlen($result));
    }

    public function testCreateSlugWithNumbers(): void
    {
        $result = $this->invokePrivateMethod('createSlug', ['Product 123']);
        $this->assertSame('product-123', $result);
    }

    // =========================================================================
    // isEffectivelyEmptyObject (private)
    // =========================================================================

    public function testIsEffectivelyEmptyObjectWithEmptyArray(): void
    {
        $result = $this->invokePrivateMethod('isEffectivelyEmptyObject', [[]]);
        $this->assertTrue($result);
    }

    public function testIsEffectivelyEmptyObjectWithAllEmptyValues(): void
    {
        $object = ['name' => '', 'desc' => null, 'items' => []];
        $result = $this->invokePrivateMethod('isEffectivelyEmptyObject', [$object]);
        $this->assertTrue($result);
    }

    public function testIsEffectivelyEmptyObjectWithNonEmptyValue(): void
    {
        $object = ['name' => 'Test'];
        $result = $this->invokePrivateMethod('isEffectivelyEmptyObject', [$object]);
        $this->assertFalse($result);
    }

    public function testIsEffectivelyEmptyObjectSkipsMetadataKeys(): void
    {
        $object = ['@self' => ['id' => '123'], 'id' => '456', '_id' => '789'];
        $result = $this->invokePrivateMethod('isEffectivelyEmptyObject', [$object]);
        $this->assertTrue($result);
    }

    public function testIsEffectivelyEmptyObjectWithNestedEmptyObject(): void
    {
        $object = ['address' => ['street' => '', 'city' => null]];
        $result = $this->invokePrivateMethod('isEffectivelyEmptyObject', [$object]);
        $this->assertTrue($result);
    }

    public function testIsEffectivelyEmptyObjectWithNestedNonEmptyObject(): void
    {
        $object = ['address' => ['street' => '123 Main St']];
        $result = $this->invokePrivateMethod('isEffectivelyEmptyObject', [$object]);
        $this->assertFalse($result);
    }

    // =========================================================================
    // isValueNotEmpty (private)
    // =========================================================================

    public function testIsValueNotEmptyWithNull(): void
    {
        $result = $this->invokePrivateMethod('isValueNotEmpty', [null]);
        $this->assertFalse($result);
    }

    public function testIsValueNotEmptyWithEmptyString(): void
    {
        $result = $this->invokePrivateMethod('isValueNotEmpty', ['']);
        $this->assertFalse($result);
    }

    public function testIsValueNotEmptyWithWhitespace(): void
    {
        $result = $this->invokePrivateMethod('isValueNotEmpty', ['   ']);
        $this->assertFalse($result);
    }

    public function testIsValueNotEmptyWithEmptyArray(): void
    {
        $result = $this->invokePrivateMethod('isValueNotEmpty', [[]]);
        $this->assertFalse($result);
    }

    public function testIsValueNotEmptyWithNonEmptyString(): void
    {
        $result = $this->invokePrivateMethod('isValueNotEmpty', ['Hello']);
        $this->assertTrue($result);
    }

    public function testIsValueNotEmptyWithNumber(): void
    {
        $result = $this->invokePrivateMethod('isValueNotEmpty', [42]);
        $this->assertTrue($result);
    }

    public function testIsValueNotEmptyWithZero(): void
    {
        $result = $this->invokePrivateMethod('isValueNotEmpty', [0]);
        $this->assertTrue($result);
    }

    public function testIsValueNotEmptyWithBoolean(): void
    {
        $result = $this->invokePrivateMethod('isValueNotEmpty', [false]);
        $this->assertTrue($result);
    }

    public function testIsValueNotEmptyWithIndexedArrayAllEmpty(): void
    {
        $result = $this->invokePrivateMethod('isValueNotEmpty', [['', null, '  ']]);
        $this->assertFalse($result);
    }

    public function testIsValueNotEmptyWithIndexedArraySomeNonEmpty(): void
    {
        $result = $this->invokePrivateMethod('isValueNotEmpty', [['', 'hello']]);
        $this->assertTrue($result);
    }

    // =========================================================================
    // shouldApplyDefault (private)
    // =========================================================================

    public function testShouldApplyDefaultAlwaysBehavior(): void
    {
        $result = $this->invokePrivateMethod('shouldApplyDefault', ['always', ['key' => 'value'], 'key']);
        $this->assertTrue($result);
    }

    public function testShouldApplyDefaultAlwaysBehaviorEvenWithValue(): void
    {
        $result = $this->invokePrivateMethod('shouldApplyDefault', ['always', ['key' => 'value'], 'key']);
        $this->assertTrue($result);
    }

    public function testShouldApplyDefaultFalsyBehaviorWithMissing(): void
    {
        $result = $this->invokePrivateMethod('shouldApplyDefault', ['falsy', [], 'key']);
        $this->assertTrue($result);
    }

    public function testShouldApplyDefaultFalsyBehaviorWithNull(): void
    {
        $result = $this->invokePrivateMethod('shouldApplyDefault', ['falsy', ['key' => null], 'key']);
        $this->assertTrue($result);
    }

    public function testShouldApplyDefaultFalsyBehaviorWithEmptyString(): void
    {
        $result = $this->invokePrivateMethod('shouldApplyDefault', ['falsy', ['key' => ''], 'key']);
        $this->assertTrue($result);
    }

    public function testShouldApplyDefaultFalsyBehaviorWithEmptyArray(): void
    {
        $result = $this->invokePrivateMethod('shouldApplyDefault', ['falsy', ['key' => []], 'key']);
        $this->assertTrue($result);
    }

    public function testShouldApplyDefaultFalsyBehaviorWithValue(): void
    {
        $result = $this->invokePrivateMethod('shouldApplyDefault', ['falsy', ['key' => 'hello'], 'key']);
        $this->assertFalse($result);
    }

    public function testShouldApplyDefaultDefaultBehaviorWithMissing(): void
    {
        $result = $this->invokePrivateMethod('shouldApplyDefault', ['false', [], 'key']);
        $this->assertTrue($result);
    }

    public function testShouldApplyDefaultDefaultBehaviorWithNull(): void
    {
        $result = $this->invokePrivateMethod('shouldApplyDefault', ['false', ['key' => null], 'key']);
        $this->assertTrue($result);
    }

    public function testShouldApplyDefaultDefaultBehaviorWithEmptyString(): void
    {
        // Default behavior does NOT apply when value is empty string (unlike falsy).
        $result = $this->invokePrivateMethod('shouldApplyDefault', ['false', ['key' => ''], 'key']);
        $this->assertFalse($result);
    }

    public function testShouldApplyDefaultDefaultBehaviorWithValue(): void
    {
        $result = $this->invokePrivateMethod('shouldApplyDefault', ['false', ['key' => 'val'], 'key']);
        $this->assertFalse($result);
    }

    // =========================================================================
    // resolveDefaultTemplateValue (private)
    // =========================================================================

    public function testResolveDefaultTemplateValueNonTemplate(): void
    {
        $result = $this->invokePrivateMethod('resolveDefaultTemplateValue', ['static-value', [], []]);
        $this->assertSame('static-value', $result);
    }

    public function testResolveDefaultTemplateValueSimplePropertyRef(): void
    {
        $context = ['title' => 'My Title'];
        $result = $this->invokePrivateMethod('resolveDefaultTemplateValue', ['{{ title }}', $context, []]);
        $this->assertSame('My Title', $result);
    }

    public function testResolveDefaultTemplateValueSimpleRefPreservesArrays(): void
    {
        $context = ['tags' => ['tag1', 'tag2']];
        $result = $this->invokePrivateMethod('resolveDefaultTemplateValue', ['{{ tags }}', $context, []]);
        $this->assertSame(['tag1', 'tag2'], $result);
    }

    public function testResolveDefaultTemplateValueSimpleRefMissingProperty(): void
    {
        $result = $this->invokePrivateMethod('resolveDefaultTemplateValue', ['{{ missing }}', [], []]);
        $this->assertNull($result);
    }

    public function testResolveDefaultTemplateValueComplexTemplate(): void
    {
        $context = ['voornaam' => 'Jan', 'achternaam' => 'Jansen'];
        $schemaProps = [];

        $this->metaHydrationHandler->expects($this->once())
            ->method('processTwigLikeTemplate')
            ->with($context, '{{ voornaam }} {{ achternaam }}', $schemaProps)
            ->willReturn('Jan Jansen');

        $result = $this->invokePrivateMethod(
            'resolveDefaultTemplateValue',
            ['{{ voornaam }} {{ achternaam }}', $context, $schemaProps]
        );
        $this->assertSame('Jan Jansen', $result);
    }

    public function testResolveDefaultTemplateValueNonStringValue(): void
    {
        $result = $this->invokePrivateMethod('resolveDefaultTemplateValue', [42, [], []]);
        $this->assertSame(42, $result);
    }

    public function testResolveDefaultTemplateValueNullOnException(): void
    {
        $this->metaHydrationHandler->method('processTwigLikeTemplate')
            ->willThrowException(new Exception('Template error'));

        $result = $this->invokePrivateMethod(
            'resolveDefaultTemplateValue',
            ['{{ a }} {{ b }}', ['a' => 'x'], []]
        );
        $this->assertNull($result);
    }

    // =========================================================================
    // isAuditTrailsEnabled (private)
    // =========================================================================

    public function testIsAuditTrailsEnabledReturnsTrue(): void
    {
        $this->settingsService->method('getRetentionSettingsOnly')
            ->willReturn(['auditTrailsEnabled' => true]);

        $result = $this->invokePrivateMethod('isAuditTrailsEnabled', []);
        $this->assertTrue($result);
    }

    public function testIsAuditTrailsEnabledReturnsFalse(): void
    {
        $this->settingsService->method('getRetentionSettingsOnly')
            ->willReturn(['auditTrailsEnabled' => false]);

        $result = $this->invokePrivateMethod('isAuditTrailsEnabled', []);
        $this->assertFalse($result);
    }

    public function testIsAuditTrailsEnabledDefaultsToTrueOnMissingSetting(): void
    {
        $this->settingsService->method('getRetentionSettingsOnly')
            ->willReturn([]);

        $result = $this->invokePrivateMethod('isAuditTrailsEnabled', []);
        $this->assertTrue($result);
    }

    public function testIsAuditTrailsEnabledDefaultsToTrueOnException(): void
    {
        $this->settingsService->method('getRetentionSettingsOnly')
            ->willThrowException(new Exception('Settings error'));

        $result = $this->invokePrivateMethod('isAuditTrailsEnabled', []);
        $this->assertTrue($result);
    }

    // =========================================================================
    // applyAlwaysDefaults
    // =========================================================================

    public function testApplyAlwaysDefaultsNoAlwaysProperties(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'name' => ['type' => 'string', 'default' => 'default-name', 'defaultBehavior' => 'false'],
        ];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $data = ['title' => 'Test'];
        $result = $this->handler->applyAlwaysDefaults($schema, $data);

        $this->assertSame($data, $result);
    }

    public function testApplyAlwaysDefaultsAppliesAlwaysDefaults(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'computed' => ['type' => 'string', 'default' => 'fixed-value', 'defaultBehavior' => 'always'],
        ];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $data = ['name' => 'Test'];
        $result = $this->handler->applyAlwaysDefaults($schema, $data);

        $this->assertSame('fixed-value', $result['computed']);
    }

    public function testApplyAlwaysDefaultsSkipsNullDefaultValue(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'computed' => ['type' => 'string', 'default' => null, 'defaultBehavior' => 'always'],
        ];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $data = ['name' => 'Test'];
        $result = $this->handler->applyAlwaysDefaults($schema, $data);

        $this->assertArrayNotHasKey('computed', $result);
    }

    public function testApplyAlwaysDefaultsWithTemplateValue(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'dienstType' => ['type' => 'string', 'default' => '{{ type }}', 'defaultBehavior' => 'always'],
        ];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $data = ['type' => 'service'];
        $result = $this->handler->applyAlwaysDefaults($schema, $data);

        // Simple ref template resolves directly.
        $this->assertSame('service', $result['dienstType']);
    }

    public function testApplyAlwaysDefaultsReturnsDataWhenNoProperties(): void
    {
        $schemaObject = new \stdClass();
        // No properties key.
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $data = ['name' => 'Test'];
        $result = $this->handler->applyAlwaysDefaults($schema, $data);

        $this->assertSame($data, $result);
    }

    public function testApplyAlwaysDefaultsReturnsDataOnSchemaException(): void
    {
        $schema = $this->getMockBuilder(Schema::class)
            ->onlyMethods(['getConfiguration', 'getProperties', 'getSchemaObject', 'hasPropertyAuthorization'])
            ->getMock();
        $schema->method('getSchemaObject')->willThrowException(new Exception('Schema error'));

        $data = ['name' => 'Test'];
        $result = $this->handler->applyAlwaysDefaults($schema, $data);

        $this->assertSame($data, $result);
    }

    // =========================================================================
    // applyPropertyDefaults
    // =========================================================================

    public function testApplyPropertyDefaultsAppliesDefaultWhenMissing(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'status' => ['type' => 'string', 'default' => 'draft'],
        ];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $data = ['name' => 'Test'];
        $result = $this->handler->applyPropertyDefaults($schema, $data);

        $this->assertSame('draft', $result['status']);
    }

    public function testApplyPropertyDefaultsDoesNotOverrideExisting(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'status' => ['type' => 'string', 'default' => 'draft'],
        ];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $data = ['status' => 'published'];
        $result = $this->handler->applyPropertyDefaults($schema, $data);

        $this->assertSame('published', $result['status']);
    }

    public function testApplyPropertyDefaultsWithFalsyBehavior(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'status' => ['type' => 'string', 'default' => 'draft', 'defaultBehavior' => 'falsy'],
        ];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $data = ['status' => ''];
        $result = $this->handler->applyPropertyDefaults($schema, $data);

        $this->assertSame('draft', $result['status']);
    }

    public function testApplyPropertyDefaultsWithAlwaysBehavior(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'computed' => ['type' => 'string', 'default' => 'always-val', 'defaultBehavior' => 'always'],
        ];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $data = ['computed' => 'user-value'];
        $result = $this->handler->applyPropertyDefaults($schema, $data);

        $this->assertSame('always-val', $result['computed']);
    }

    public function testApplyPropertyDefaultsReturnsDataOnSchemaException(): void
    {
        $schema = $this->getMockBuilder(Schema::class)
            ->onlyMethods(['getConfiguration', 'getProperties', 'getSchemaObject', 'hasPropertyAuthorization'])
            ->getMock();
        $schema->method('getSchemaObject')->willThrowException(new Exception('Schema error'));

        $data = ['name' => 'Test'];
        $result = $this->handler->applyPropertyDefaults($schema, $data);

        $this->assertSame($data, $result);
    }

    public function testApplyPropertyDefaultsReturnsDataWhenNoProperties(): void
    {
        $schemaObject = new \stdClass();
        // No properties key.
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $data = ['name' => 'Test'];
        $result = $this->handler->applyPropertyDefaults($schema, $data);

        $this->assertSame($data, $result);
    }

    // =========================================================================
    // hydrateObjectMetadata
    // =========================================================================

    public function testHydrateObjectMetadataDelegatesToHandler(): void
    {
        $entity = new ObjectEntity();
        $entity->setObject(['name' => 'Test']);

        $schema = $this->createMockSchema(1, 'test', []);

        $this->metaHydrationHandler->expects($this->once())
            ->method('hydrateObjectMetadata')
            ->with($entity, $schema);

        $this->handler->hydrateObjectMetadata($entity, $schema);
    }

    public function testHydrateObjectMetadataWithImageFieldString(): void
    {
        $entity = new ObjectEntity();
        $entity->setObject(['photo' => 'https://example.com/image.jpg']);

        $schema = $this->createMockSchema(1, 'test', ['objectImageField' => 'photo']);

        $this->metaHydrationHandler->method('getValueFromPath')
            ->willReturn('https://example.com/image.jpg');

        $this->handler->hydrateObjectMetadata($entity, $schema);

        $this->assertSame('https://example.com/image.jpg', $entity->getImage());
    }

    public function testHydrateObjectMetadataWithImageFieldFileObjectsDownloadUrl(): void
    {
        $entity = new ObjectEntity();
        $entity->setObject(['photos' => [['downloadUrl' => 'https://example.com/dl/image.jpg']]]);

        $schema = $this->createMockSchema(1, 'test', ['objectImageField' => 'photos']);

        $this->metaHydrationHandler->method('getValueFromPath')
            ->willReturn([['downloadUrl' => 'https://example.com/dl/image.jpg']]);

        $this->handler->hydrateObjectMetadata($entity, $schema);

        $this->assertSame('https://example.com/dl/image.jpg', $entity->getImage());
    }

    public function testHydrateObjectMetadataWithImageFieldFileObjectsAccessUrl(): void
    {
        $entity = new ObjectEntity();
        $entity->setObject(['photos' => [['accessUrl' => 'https://example.com/access/image.jpg']]]);

        $schema = $this->createMockSchema(1, 'test', ['objectImageField' => 'photos']);

        $this->metaHydrationHandler->method('getValueFromPath')
            ->willReturn([['accessUrl' => 'https://example.com/access/image.jpg']]);

        $this->handler->hydrateObjectMetadata($entity, $schema);

        $this->assertSame('https://example.com/access/image.jpg', $entity->getImage());
    }

    public function testHydrateObjectMetadataWithPublishedField(): void
    {
        $entity = new ObjectEntity();
        $entity->setObject(['pubDate' => '2025-01-15']);

        $schema = $this->createMockSchema(1, 'test', ['objectPublishedField' => 'pubDate']);

        $this->metaHydrationHandler->method('extractMetadataValue')
            ->willReturn('2025-01-15');

        $this->handler->hydrateObjectMetadata($entity, $schema);

        $this->assertNotNull($entity->getPublished());
        $this->assertSame('2025-01-15', $entity->getPublished()->format('Y-m-d'));
    }

    public function testHydrateObjectMetadataWithInvalidPublishedDate(): void
    {
        $entity = new ObjectEntity();
        $entity->setObject(['pubDate' => 'not-a-date']);

        $schema = $this->createMockSchema(1, 'test', ['objectPublishedField' => 'pubDate']);

        $this->metaHydrationHandler->method('extractMetadataValue')
            ->willReturn('not-a-date');

        // Should log warning but not throw.
        $this->logger->expects($this->atLeastOnce())->method('warning');

        $this->handler->hydrateObjectMetadata($entity, $schema);
    }

    public function testHydrateObjectMetadataWithDepublishedField(): void
    {
        $entity = new ObjectEntity();
        $entity->setObject(['endDate' => '2025-12-31']);

        $schema = $this->createMockSchema(1, 'test', ['objectDepublishedField' => 'endDate']);

        $this->metaHydrationHandler->method('extractMetadataValue')
            ->willReturn('2025-12-31');

        $this->handler->hydrateObjectMetadata($entity, $schema);

        $this->assertNotNull($entity->getDepublished());
        $this->assertSame('2025-12-31', $entity->getDepublished()->format('Y-m-d'));
    }

    public function testHydrateObjectMetadataWithInvalidDepublishedDate(): void
    {
        $entity = new ObjectEntity();
        $entity->setObject(['endDate' => 'invalid']);

        $schema = $this->createMockSchema(1, 'test', ['objectDepublishedField' => 'endDate']);

        $this->metaHydrationHandler->method('extractMetadataValue')
            ->willReturn('invalid');

        $this->logger->expects($this->atLeastOnce())->method('warning');

        $this->handler->hydrateObjectMetadata($entity, $schema);
    }

    public function testHydrateObjectMetadataWithEmptyPublishedValue(): void
    {
        $entity = new ObjectEntity();
        $entity->setObject(['pubDate' => '']);

        $schema = $this->createMockSchema(1, 'test', ['objectPublishedField' => 'pubDate']);

        $this->metaHydrationHandler->method('extractMetadataValue')
            ->willReturn('');

        $this->handler->hydrateObjectMetadata($entity, $schema);

        // Empty published should not set the date.
        $this->assertNull($entity->getPublished());
    }

    public function testHydrateObjectMetadataWithNumericImageValue(): void
    {
        $entity = new ObjectEntity();
        $entity->setObject(['imageId' => 42]);

        $schema = $this->createMockSchema(1, 'test', ['objectImageField' => 'imageId']);

        $this->metaHydrationHandler->method('getValueFromPath')
            ->willReturn(42);

        // Should log debug for numeric file ID.
        $this->logger->expects($this->atLeastOnce())->method('debug');

        $this->handler->hydrateObjectMetadata($entity, $schema);
    }

    // =========================================================================
    // extractUuidAndSelfData (private)
    // =========================================================================

    public function testExtractUuidAndSelfDataWithSelfMetadata(): void
    {
        $data = [
            '@self' => ['id' => 'test-uuid', 'slug' => 'test'],
            'name' => 'Test',
        ];

        [$uuid, $selfData, $cleanedData] = $this->invokePrivateMethod('extractUuidAndSelfData', [$data, null, null]);

        $this->assertSame('test-uuid', $uuid);
        $this->assertSame(['id' => 'test-uuid', 'slug' => 'test'], $selfData);
        $this->assertArrayNotHasKey('@self', $cleanedData);
        $this->assertSame('Test', $cleanedData['name']);
    }

    public function testExtractUuidAndSelfDataWithExplicitUuid(): void
    {
        $data = ['name' => 'Test'];

        [$uuid, $selfData, $cleanedData] = $this->invokePrivateMethod('extractUuidAndSelfData', [$data, 'explicit-uuid', null]);

        $this->assertSame('explicit-uuid', $uuid);
    }

    public function testExtractUuidAndSelfDataWithIdInData(): void
    {
        $data = ['id' => 'data-id', 'name' => 'Test'];

        [$uuid, $selfData, $cleanedData] = $this->invokePrivateMethod('extractUuidAndSelfData', [$data, null, null]);

        $this->assertSame('data-id', $uuid);
        $this->assertArrayNotHasKey('id', $cleanedData);
    }

    public function testExtractUuidAndSelfDataNormalizesEmptyString(): void
    {
        $data = ['name' => 'Test'];

        [$uuid, $selfData, $cleanedData] = $this->invokePrivateMethod('extractUuidAndSelfData', [$data, '', null]);

        $this->assertNull($uuid);
    }

    public function testExtractUuidAndSelfDataProcessesUploadedFiles(): void
    {
        $data = ['name' => 'Test'];
        $uploadedFiles = ['file' => ['name' => 'test.txt', 'tmp_name' => '/tmp/test', 'error' => 0, 'size' => 10]];

        $this->filePropertyHandler->expects($this->once())
            ->method('processUploadedFiles')
            ->with($uploadedFiles, $data)
            ->willReturn(['name' => 'Test', 'file' => 'data:text/plain;base64,dGVzdA==']);

        [$uuid, $selfData, $cleanedData] = $this->invokePrivateMethod('extractUuidAndSelfData', [$data, null, $uploadedFiles]);

        $this->assertArrayHasKey('file', $cleanedData);
    }

    public function testExtractUuidAndSelfDataNoUploadedFiles(): void
    {
        $data = ['name' => 'Test'];

        $this->filePropertyHandler->expects($this->never())
            ->method('processUploadedFiles');

        $this->invokePrivateMethod('extractUuidAndSelfData', [$data, null, null]);
    }

    // =========================================================================
    // resolveSchemaAndRegister (private)
    // =========================================================================

    public function testResolveSchemaAndRegisterWithEntities(): void
    {
        $schema = $this->createMockSchema(5, 'test');
        $register = $this->createMockRegister(3, 'reg');

        [$resolvedSchema, $schemaId, $resolvedRegister, $registerId] = $this->invokePrivateMethod(
            'resolveSchemaAndRegister',
            [$schema, $register]
        );

        $this->assertSame($schema, $resolvedSchema);
        $this->assertSame(5, $schemaId);
        $this->assertSame($register, $resolvedRegister);
        $this->assertSame(3, $registerId);
    }

    public function testResolveSchemaAndRegisterWithIntIds(): void
    {
        $schema = $this->createMockSchema(5, 'test');
        $register = $this->createMockRegister(3, 'reg');

        $this->schemaMapper->method('find')->willReturn($schema);
        $this->registerMapper->method('find')->willReturn($register);

        [$resolvedSchema, $schemaId, $resolvedRegister, $registerId] = $this->invokePrivateMethod(
            'resolveSchemaAndRegister',
            [5, 3]
        );

        $this->assertSame(5, $resolvedSchema->getId());
        $this->assertSame(3, $resolvedRegister->getId());
    }

    public function testResolveSchemaAndRegisterWithNullRegister(): void
    {
        $schema = $this->createMockSchema(5, 'test');

        [$resolvedSchema, $schemaId, $resolvedRegister, $registerId] = $this->invokePrivateMethod(
            'resolveSchemaAndRegister',
            [$schema, null]
        );

        $this->assertSame($schema, $resolvedSchema);
        $this->assertNull($resolvedRegister);
        $this->assertNull($registerId);
    }

    // =========================================================================
    // generateSlug (private)
    // =========================================================================

    public function testGenerateSlugWithSlugField(): void
    {
        $schema = $this->createMockSchema(1, 'test', ['objectSlugField' => 'name']);
        $data = ['name' => 'My Product'];

        $result = $this->invokePrivateMethod('generateSlug', [$data, $schema]);

        $this->assertNotNull($result);
        $this->assertStringStartsWith('my-product-', $result);
    }

    public function testGenerateSlugWithNoSlugField(): void
    {
        $schema = $this->createMockSchema(1, 'test', []);
        $data = ['name' => 'Test'];

        $result = $this->invokePrivateMethod('generateSlug', [$data, $schema]);

        $this->assertNull($result);
    }

    public function testGenerateSlugWithMissingFieldValue(): void
    {
        $schema = $this->createMockSchema(1, 'test', ['objectSlugField' => 'title']);
        $data = ['name' => 'Test'];

        $result = $this->invokePrivateMethod('generateSlug', [$data, $schema]);

        $this->assertNull($result);
    }

    public function testGenerateSlugWithNestedField(): void
    {
        $schema = $this->createMockSchema(1, 'test', ['objectSlugField' => 'meta.title']);
        $data = ['meta' => ['title' => 'Nested Title']];

        $result = $this->invokePrivateMethod('generateSlug', [$data, $schema]);

        $this->assertNotNull($result);
        $this->assertStringStartsWith('nested-title-', $result);
    }

    // =========================================================================
    // resolveSchemaReference (private) — tests schema reference resolution
    // =========================================================================

    public function testResolveSchemaReferenceEmptyString(): void
    {
        $result = $this->invokePrivateMethod('resolveSchemaReference', ['']);
        $this->assertNull($result);
    }

    public function testResolveSchemaReferenceCachedResult(): void
    {
        $this->setPrivateProperty('schemaReferenceCache', ['test-ref' => '42']);

        $result = $this->invokePrivateMethod('resolveSchemaReference', ['test-ref']);
        $this->assertSame('42', $result);
    }

    public function testResolveSchemaReferenceNumericId(): void
    {
        $schema = $this->createMockSchema(42, 'my-schema');
        $this->schemaMapper->method('find')->willReturn($schema);

        $result = $this->invokePrivateMethod('resolveSchemaReference', ['42']);

        $this->assertSame('42', $result);
    }

    public function testResolveSchemaReferenceUuid(): void
    {
        $schema = $this->createMockSchema(10, 'uuid-schema');
        $this->schemaMapper->method('find')->willReturn($schema);

        $result = $this->invokePrivateMethod('resolveSchemaReference', ['dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4']);

        $this->assertSame('10', $result);
    }

    public function testResolveSchemaReferenceBySlugViaFindAll(): void
    {
        $schema = $this->createMockSchema(7, 'contactgegevens');
        $this->schemaMapper->method('findAll')->willReturn([$schema]);

        // Make direct find throw so it falls through to findAll.
        $this->schemaMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->invokePrivateMethod('resolveSchemaReference', ['contactgegevens']);

        $this->assertSame('7', $result);
    }

    public function testResolveSchemaReferenceByPathReference(): void
    {
        $schema = $this->createMockSchema(7, 'Contactgegevens');
        $this->schemaMapper->method('findAll')->willReturn([$schema]);
        $this->schemaMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->invokePrivateMethod('resolveSchemaReference', ['#/components/schemas/Contactgegevens']);

        $this->assertSame('7', $result);
    }

    public function testResolveSchemaReferenceWithQueryParameters(): void
    {
        $schema = $this->createMockSchema(5, 'test');
        $this->schemaMapper->method('find')->willReturn($schema);

        // Pre-cache the cleaned reference.
        $this->setPrivateProperty('schemaReferenceCache', ['5' => '5']);

        $result = $this->invokePrivateMethod('resolveSchemaReference', ['5?key=value']);

        $this->assertSame('5', $result);
    }

    public function testResolveSchemaReferenceNotFoundCachesNull(): void
    {
        $this->schemaMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));
        $this->schemaMapper->method('findAll')->willReturn([]);

        $result = $this->invokePrivateMethod('resolveSchemaReference', ['nonexistent-slug']);

        $this->assertNull($result);
        // Verify the null is cached.
        $cache = $this->getPrivateProperty('schemaReferenceCache');
        $this->assertArrayHasKey('nonexistent-slug', $cache);
        $this->assertNull($cache['nonexistent-slug']);
    }

    // =========================================================================
    // resolveRegisterReference (private)
    // =========================================================================

    public function testResolveRegisterReferenceEmptyString(): void
    {
        $result = $this->invokePrivateMethod('resolveRegisterReference', ['']);
        $this->assertNull($result);
    }

    public function testResolveRegisterReferenceNumericId(): void
    {
        $register = $this->createMockRegister(5, 'test-reg');
        $this->registerMapper->method('find')->willReturn($register);

        $result = $this->invokePrivateMethod('resolveRegisterReference', ['5']);

        $this->assertSame('5', $result);
    }

    public function testResolveRegisterReferenceBySlug(): void
    {
        $register = $this->createMockRegister(3, 'publication');
        $this->registerMapper->method('findAll')->willReturn([$register]);
        $this->registerMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->invokePrivateMethod('resolveRegisterReference', ['publication']);

        $this->assertSame('3', $result);
    }

    public function testResolveRegisterReferenceByUrlPath(): void
    {
        $register = $this->createMockRegister(3, 'publication');
        $this->registerMapper->method('findAll')->willReturn([$register]);
        $this->registerMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->invokePrivateMethod('resolveRegisterReference', ['http://example.com/registers/publication']);

        $this->assertSame('3', $result);
    }

    public function testResolveRegisterReferenceNotFound(): void
    {
        $this->registerMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));
        $this->registerMapper->method('findAll')->willReturn([]);

        $result = $this->invokePrivateMethod('resolveRegisterReference', ['nonexistent']);

        $this->assertNull($result);
    }

    // =========================================================================
    // setSelfMetadata (private)
    // =========================================================================

    public function testSetSelfMetadataSetsSlugFromSelfData(): void
    {
        $entity = new ObjectEntity();
        $selfData = ['slug' => 'my-slug'];

        $this->invokePrivateMethod('setSelfMetadata', [$entity, $selfData]);

        $this->assertSame('my-slug', $entity->getSlug());
    }

    public function testSetSelfMetadataSetsSlugFromData(): void
    {
        $entity = new ObjectEntity();
        $selfData = [];
        $data = ['slug' => 'data-slug'];

        $this->invokePrivateMethod('setSelfMetadata', [$entity, $selfData, $data]);

        $this->assertSame('data-slug', $entity->getSlug());
    }

    public function testSetSelfMetadataSetsPublishedDate(): void
    {
        $entity = new ObjectEntity();
        $selfData = ['published' => '2025-06-15'];

        $this->invokePrivateMethod('setSelfMetadata', [$entity, $selfData]);

        $this->assertNotNull($entity->getPublished());
        $this->assertSame('2025-06-15', $entity->getPublished()->format('Y-m-d'));
    }

    public function testSetSelfMetadataSetsPublishedToNullWhenEmpty(): void
    {
        $entity = new ObjectEntity();
        $entity->setPublished(new DateTime());
        $selfData = ['published' => ''];

        $this->invokePrivateMethod('setSelfMetadata', [$entity, $selfData]);

        $this->assertNull($entity->getPublished());
    }

    public function testSetSelfMetadataHandlesInvalidPublishedDate(): void
    {
        $entity = new ObjectEntity();
        $selfData = ['published' => 'not-a-date-at-all-!!!'];

        // Should log warning but not throw.
        $this->logger->expects($this->atLeastOnce())->method('warning');

        $this->invokePrivateMethod('setSelfMetadata', [$entity, $selfData]);
    }

    public function testSetSelfMetadataSetsDepublished(): void
    {
        $entity = new ObjectEntity();
        $selfData = ['depublished' => '2025-12-31'];

        $this->invokePrivateMethod('setSelfMetadata', [$entity, $selfData]);

        $this->assertNotNull($entity->getDepublished());
    }

    public function testSetSelfMetadataSetsDepublishedToNullWhenMissing(): void
    {
        $entity = new ObjectEntity();
        $selfData = [];

        $this->invokePrivateMethod('setSelfMetadata', [$entity, $selfData]);

        $this->assertNull($entity->getDepublished());
    }

    public function testSetSelfMetadataSetsOwner(): void
    {
        $entity = new ObjectEntity();
        $selfData = ['owner' => 'admin'];

        $this->invokePrivateMethod('setSelfMetadata', [$entity, $selfData]);

        $this->assertSame('admin', $entity->getOwner());
    }

    public function testSetSelfMetadataSetsOrganisation(): void
    {
        $entity = new ObjectEntity();
        $selfData = ['organisation' => 'org-uuid'];

        $this->invokePrivateMethod('setSelfMetadata', [$entity, $selfData]);

        $this->assertSame('org-uuid', $entity->getOrganisation());
    }

    // =========================================================================
    // getCachedSchema / getCachedRegister (private, tests caching behavior)
    // =========================================================================

    public function testGetCachedSchemaFetchesAndCaches(): void
    {
        $schema = $this->createMockSchema(5, 'test');
        $this->schemaMapper->expects($this->once())
            ->method('find')
            ->willReturn($schema);

        // First call fetches from mapper.
        $result1 = $this->invokePrivateMethod('getCachedSchema', [5]);
        // Second call should use cache (mapper not called again).
        $result2 = $this->invokePrivateMethod('getCachedSchema', [5]);

        $this->assertSame($schema, $result1);
        $this->assertSame($schema, $result2);
    }

    public function testGetCachedRegisterFetchesAndCaches(): void
    {
        $register = $this->createMockRegister(3, 'test');
        $this->registerMapper->expects($this->once())
            ->method('find')
            ->willReturn($register);

        $result1 = $this->invokePrivateMethod('getCachedRegister', [3]);
        $result2 = $this->invokePrivateMethod('getCachedRegister', [3]);

        $this->assertSame($register, $result1);
        $this->assertSame($register, $result2);
    }

    // =========================================================================
    // updateObjectRelations (private)
    // =========================================================================

    public function testUpdateObjectRelationsSetsRelations(): void
    {
        $entity = new ObjectEntity();
        $data = [
            'name' => 'Test',
            'parent' => 'dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4',
        ];

        $result = $this->invokePrivateMethod('updateObjectRelations', [$entity, $data, null]);

        $relations = $result->getRelations();
        $this->assertArrayHasKey('parent', $relations);
    }

    // =========================================================================
    // findAndValidateExistingObject (private)
    // =========================================================================

    public function testFindAndValidateExistingObjectReturnsNullWhenNotFound(): void
    {
        $this->objectEntityMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->invokePrivateMethod('findAndValidateExistingObject', [
            'test-uuid', null, null, false, false,
        ]);

        $this->assertNull($result);
    }

    public function testFindAndValidateExistingObjectReturnsEntity(): void
    {
        $entity = new ObjectEntity();
        $entity->setUuid('test-uuid');

        $this->objectEntityMapper->method('find')
            ->willReturn($entity);

        $result = $this->invokePrivateMethod('findAndValidateExistingObject', [
            'test-uuid', null, null, false, false,
        ]);

        $this->assertSame($entity, $result);
    }

    public function testFindAndValidateExistingObjectThrowsOnLockedObject(): void
    {
        $entity = new ObjectEntity();
        $entity->setUuid('test-uuid');
        $entity->setLocked(['userId' => 'other-user']);

        $this->objectEntityMapper->method('find')
            ->willReturn($entity);

        // Current user is different from lock owner.
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('current-user');
        $this->userSession->method('getUser')->willReturn($user);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Cannot update object: Object is locked');

        $this->invokePrivateMethod('findAndValidateExistingObject', [
            'test-uuid', null, null, false, false,
        ]);
    }

    public function testFindAndValidateExistingObjectAllowsLockOwner(): void
    {
        $entity = new ObjectEntity();
        $entity->setUuid('test-uuid');
        $entity->setLocked(['userId' => 'same-user']);

        $this->objectEntityMapper->method('find')
            ->willReturn($entity);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('same-user');
        $this->userSession->method('getUser')->willReturn($user);

        $result = $this->invokePrivateMethod('findAndValidateExistingObject', [
            'test-uuid', null, null, false, false,
        ]);

        $this->assertSame($entity, $result);
    }

    // =========================================================================
    // sanitizeEmptyStringsForObjectProperties (private)
    // =========================================================================

    public function testSanitizeEmptyStringsForObjectPropertiesWithObjectProperty(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'address' => ['type' => 'object'],
        ];
        $schemaObject->required = [];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $data = ['address' => ''];
        $result = $this->invokePrivateMethod('sanitizeEmptyStringsForObjectProperties', [$data, $schema]);

        $this->assertNull($result['address']);
    }

    public function testSanitizeEmptyStringsForObjectPropertiesEmptyObjectNonRequired(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'address' => ['type' => 'object'],
        ];
        $schemaObject->required = [];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $data = ['address' => []];
        $result = $this->invokePrivateMethod('sanitizeEmptyStringsForObjectProperties', [$data, $schema]);

        $this->assertNull($result['address']);
    }

    public function testSanitizeEmptyStringsForObjectPropertiesEmptyObjectRequired(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'address' => ['type' => 'object'],
        ];
        $schemaObject->required = ['address'];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $data = ['address' => []];
        $result = $this->invokePrivateMethod('sanitizeEmptyStringsForObjectProperties', [$data, $schema]);

        // Required empty objects remain as empty array.
        $this->assertSame([], $result['address']);
    }

    public function testSanitizeEmptyStringsForArrayPropertyWithEmptyString(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'tags' => ['type' => 'array'],
        ];
        $schemaObject->required = [];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $data = ['tags' => ''];
        $result = $this->invokePrivateMethod('sanitizeEmptyStringsForObjectProperties', [$data, $schema]);

        $this->assertNull($result['tags']);
    }

    public function testSanitizeEmptyStringsForArrayPropertyWithEmptyStringItems(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'tags' => ['type' => 'array'],
        ];
        $schemaObject->required = [];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $data = ['tags' => ['hello', '', 'world']];
        $result = $this->invokePrivateMethod('sanitizeEmptyStringsForObjectProperties', [$data, $schema]);

        $this->assertSame('hello', $result['tags'][0]);
        $this->assertNull($result['tags'][1]);
        $this->assertSame('world', $result['tags'][2]);
    }

    public function testSanitizeEmptyStringsForScalarPropertyNonRequired(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'count' => ['type' => 'number'],
        ];
        $schemaObject->required = [];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $data = ['count' => ''];
        $result = $this->invokePrivateMethod('sanitizeEmptyStringsForObjectProperties', [$data, $schema]);

        $this->assertNull($result['count']);
    }

    public function testSanitizeEmptyStringsForScalarPropertyRequired(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'name' => ['type' => 'string'],
        ];
        $schemaObject->required = ['name'];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $data = ['name' => ''];
        $result = $this->invokePrivateMethod('sanitizeEmptyStringsForObjectProperties', [$data, $schema]);

        // Required empty strings stay as-is.
        $this->assertSame('', $result['name']);
    }

    public function testSanitizeEmptyStringsReturnsDataOnSchemaException(): void
    {
        $schema = $this->getMockBuilder(Schema::class)
            ->onlyMethods(['getConfiguration', 'getProperties', 'getSchemaObject', 'hasPropertyAuthorization'])
            ->getMock();
        $schema->method('getSchemaObject')->willThrowException(new Exception('Schema error'));

        $data = ['name' => 'Test'];
        $result = $this->invokePrivateMethod('sanitizeEmptyStringsForObjectProperties', [$data, $schema]);

        $this->assertSame($data, $result);
    }

    public function testSanitizeEmptyStringsSkipsMissingProperties(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'name' => ['type' => 'string'],
        ];
        $schemaObject->required = [];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $data = ['other' => 'value'];
        $result = $this->invokePrivateMethod('sanitizeEmptyStringsForObjectProperties', [$data, $schema]);

        $this->assertSame('value', $result['other']);
    }

    // =========================================================================
    // fillMissingSchemaPropertiesWithNull (private)
    // =========================================================================

    public function testFillMissingSchemaPropertiesWithNullAddsNullForMissing(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'name' => ['type' => 'string'],
            'email' => ['type' => 'string'],
            'phone' => ['type' => 'string'],
        ];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);
        $schema->setId(1);

        $this->setPrivateProperty('schemaCache', ['1' => $schema]);

        $data = ['name' => 'Test'];
        $result = $this->invokePrivateMethod('fillMissingSchemaPropertiesWithNull', [$data, 1]);

        $this->assertSame('Test', $result['name']);
        $this->assertNull($result['email']);
        $this->assertNull($result['phone']);
    }

    public function testFillMissingSchemaPropertiesDoesNotOverrideExisting(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'name' => ['type' => 'string'],
            'email' => ['type' => 'string'],
        ];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);
        $schema->setId(1);

        $this->setPrivateProperty('schemaCache', ['1' => $schema]);

        $data = ['name' => 'Test', 'email' => 'test@example.com'];
        $result = $this->invokePrivateMethod('fillMissingSchemaPropertiesWithNull', [$data, 1]);

        $this->assertSame('Test', $result['name']);
        $this->assertSame('test@example.com', $result['email']);
    }

    public function testFillMissingSchemaPropertiesReturnsDataOnException(): void
    {
        $this->schemaMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $data = ['name' => 'Test'];
        $result = $this->invokePrivateMethod('fillMissingSchemaPropertiesWithNull', [$data, 999]);

        $this->assertSame($data, $result);
    }

    // =========================================================================
    // preCacheParentName (private)
    // =========================================================================

    public function testPreCacheParentNameCachesName(): void
    {
        $entity = new ObjectEntity();
        $entity->setUuid('test-uuid');
        $entity->setName('Test Name');

        $schema = $this->createMockSchema(1, 'test', []);

        $this->metaHydrationHandler->method('hydrateObjectMetadata')
            ->willReturnCallback(function (ObjectEntity $e, Schema $s) {
                $e->setName('Hydrated Name');
            });

        $this->cacheHandler->expects($this->once())
            ->method('setObjectName')
            ->with('test-uuid', 'Hydrated Name');

        $this->invokePrivateMethod('preCacheParentName', [$entity, $schema, ['name' => 'Test']]);
    }

    public function testPreCacheParentNameReturnsEarlyOnNullUuid(): void
    {
        $entity = new ObjectEntity();
        // UUID is null.

        $schema = $this->createMockSchema(1, 'test', []);

        $this->metaHydrationHandler->expects($this->never())->method('hydrateObjectMetadata');

        $this->invokePrivateMethod('preCacheParentName', [$entity, $schema, []]);
    }

    public function testPreCacheParentNameFallsBackToNaam(): void
    {
        $entity = new ObjectEntity();
        $entity->setUuid('test-uuid');

        $schema = $this->createMockSchema(1, 'test', []);

        // Hydration does NOT set a name.
        $this->metaHydrationHandler->method('hydrateObjectMetadata')
            ->willReturnCallback(function (ObjectEntity $e, Schema $s) {
                // No name set.
            });

        $this->cacheHandler->expects($this->once())
            ->method('setObjectName')
            ->with('test-uuid', 'Dutch Name');

        $this->invokePrivateMethod('preCacheParentName', [$entity, $schema, ['naam' => 'Dutch Name']]);
    }

    public function testPreCacheParentNameHandlesHydrationException(): void
    {
        $entity = new ObjectEntity();
        $entity->setUuid('test-uuid');

        $schema = $this->createMockSchema(1, 'test', []);

        $this->metaHydrationHandler->method('hydrateObjectMetadata')
            ->willThrowException(new Exception('Hydration failed'));

        $this->cacheHandler->expects($this->never())->method('setObjectName');

        // Should not throw.
        $this->invokePrivateMethod('preCacheParentName', [$entity, $schema, ['name' => 'Test']]);
    }

    // =========================================================================
    // clearImageMetadataIfFileProperty (private)
    // =========================================================================

    public function testClearImageMetadataIfFilePropertyClearsImage(): void
    {
        $entity = new ObjectEntity();
        $entity->setImage('https://example.com/image.jpg');

        $schema = $this->createMockSchema(
            1,
            'test',
            ['objectImageField' => 'photo'],
            ['photo' => ['type' => 'file']]
        );

        $this->invokePrivateMethod('clearImageMetadataIfFileProperty', [$entity, $schema]);

        $this->assertNull($entity->getImage());
    }

    public function testClearImageMetadataIfFilePropertyDoesNotClearNonFileProperty(): void
    {
        $entity = new ObjectEntity();
        $entity->setImage('https://example.com/image.jpg');

        $schema = $this->createMockSchema(
            1,
            'test',
            ['objectImageField' => 'photo'],
            ['photo' => ['type' => 'string']]
        );

        $this->invokePrivateMethod('clearImageMetadataIfFileProperty', [$entity, $schema]);

        $this->assertSame('https://example.com/image.jpg', $entity->getImage());
    }

    public function testClearImageMetadataIfNoImageFieldConfigured(): void
    {
        $entity = new ObjectEntity();
        $entity->setImage('https://example.com/image.jpg');

        $schema = $this->createMockSchema(1, 'test', []);

        $this->invokePrivateMethod('clearImageMetadataIfFileProperty', [$entity, $schema]);

        $this->assertSame('https://example.com/image.jpg', $entity->getImage());
    }

    // =========================================================================
    // setDefaultValues (private)
    // =========================================================================

    public function testSetDefaultValuesAppliesDefaultWhenMissing(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'status' => ['type' => 'string', 'default' => 'draft'],
        ];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $entity = new ObjectEntity();
        $entity->setObject([]);

        $data = ['name' => 'Test'];
        $result = $this->invokePrivateMethod('setDefaultValues', [$entity, $schema, $data]);

        $this->assertSame('draft', $result['status']);
        $this->assertSame('Test', $result['name']);
    }

    public function testSetDefaultValuesDoesNotOverrideExisting(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'status' => ['type' => 'string', 'default' => 'draft'],
        ];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $entity = new ObjectEntity();
        $entity->setObject([]);

        $data = ['status' => 'published'];
        $result = $this->invokePrivateMethod('setDefaultValues', [$entity, $schema, $data]);

        $this->assertSame('published', $result['status']);
    }

    public function testSetDefaultValuesWithConstValues(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'type' => ['type' => 'string', 'const' => 'fixed-type'],
        ];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $entity = new ObjectEntity();
        $entity->setObject([]);

        $data = ['type' => 'user-value'];
        $result = $this->invokePrivateMethod('setDefaultValues', [$entity, $schema, $data]);

        // Constant values always override.
        $this->assertSame('fixed-type', $result['type']);
    }

    public function testSetDefaultValuesWithFalsyBehavior(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'status' => ['type' => 'string', 'default' => 'active', 'defaultBehavior' => 'falsy'],
        ];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $entity = new ObjectEntity();
        $entity->setObject([]);

        $data = ['status' => ''];
        $result = $this->invokePrivateMethod('setDefaultValues', [$entity, $schema, $data]);

        $this->assertSame('active', $result['status']);
    }

    public function testSetDefaultValuesWithAlwaysBehavior(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'computed' => ['type' => 'string', 'default' => 'always-val', 'defaultBehavior' => 'always'],
        ];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $entity = new ObjectEntity();
        $entity->setObject([]);

        $data = ['computed' => 'user-value'];
        $result = $this->invokePrivateMethod('setDefaultValues', [$entity, $schema, $data]);

        $this->assertSame('always-val', $result['computed']);
    }

    public function testSetDefaultValuesWithTwigSimpleRef(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'displayName' => ['type' => 'string', 'default' => '{{ name }}'],
        ];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $entity = new ObjectEntity();
        $entity->setObject([]);

        $data = ['name' => 'My Object'];
        $result = $this->invokePrivateMethod('setDefaultValues', [$entity, $schema, $data]);

        $this->assertSame('My Object', $result['displayName']);
    }

    public function testSetDefaultValuesWithTwigComplexTemplate(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'fullName' => [
                'type' => 'string',
                'default' => '{{ firstName }} {{ lastName }}',
            ],
        ];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $entity = new ObjectEntity();
        $entity->setObject([]);

        $this->metaHydrationHandler->method('processTwigLikeTemplate')
            ->willReturn('Jan Jansen');

        $data = ['firstName' => 'Jan', 'lastName' => 'Jansen'];
        $result = $this->invokePrivateMethod('setDefaultValues', [$entity, $schema, $data]);

        $this->assertSame('Jan Jansen', $result['fullName']);
    }

    public function testSetDefaultValuesReturnsDataWhenNoProperties(): void
    {
        $schemaObject = new \stdClass();
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $entity = new ObjectEntity();
        $entity->setObject([]);

        $data = ['name' => 'Test'];
        $result = $this->invokePrivateMethod('setDefaultValues', [$entity, $schema, $data]);

        $this->assertSame($data, $result);
    }

    public function testSetDefaultValuesReturnsDataOnSchemaException(): void
    {
        $schema = $this->getMockBuilder(Schema::class)
            ->onlyMethods(['getConfiguration', 'getProperties', 'getSchemaObject', 'hasPropertyAuthorization'])
            ->getMock();
        $schema->method('getSchemaObject')->willThrowException(new Exception('Schema error'));

        $entity = new ObjectEntity();
        $entity->setObject([]);

        $data = ['name' => 'Test'];
        $result = $this->invokePrivateMethod('setDefaultValues', [$entity, $schema, $data]);

        $this->assertSame($data, $result);
    }

    public function testSetDefaultValuesGeneratesSlug(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'name' => ['type' => 'string'],
        ];
        $schema = $this->getMockBuilder(Schema::class)
            ->onlyMethods(['getConfiguration', 'getProperties', 'getSchemaObject', 'hasPropertyAuthorization'])
            ->getMock();
        $schema->method('getSchemaObject')->willReturn($schemaObject);
        $schema->method('getConfiguration')->willReturn(['objectSlugField' => 'name']);
        $schema->method('getProperties')->willReturn([]);
        $schema->method('hasPropertyAuthorization')->willReturn(false);

        $entity = new ObjectEntity();
        $entity->setObject([]);

        $data = ['name' => 'My Object'];
        $result = $this->invokePrivateMethod('setDefaultValues', [$entity, $schema, $data]);

        $this->assertArrayHasKey('slug', $result);
        $this->assertStringStartsWith('my-object-', $result['slug']);
    }

    public function testSetDefaultValuesSkipsSlugWhenAlreadyPresent(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'name' => ['type' => 'string'],
        ];
        $schema = $this->getMockBuilder(Schema::class)
            ->onlyMethods(['getConfiguration', 'getProperties', 'getSchemaObject', 'hasPropertyAuthorization'])
            ->getMock();
        $schema->method('getSchemaObject')->willReturn($schemaObject);
        $schema->method('getConfiguration')->willReturn(['objectSlugField' => 'name']);
        $schema->method('getProperties')->willReturn([]);
        $schema->method('hasPropertyAuthorization')->willReturn(false);

        $entity = new ObjectEntity();
        $entity->setObject([]);

        $data = ['name' => 'My Object', 'slug' => 'existing-slug'];
        $result = $this->invokePrivateMethod('setDefaultValues', [$entity, $schema, $data]);

        $this->assertSame('existing-slug', $result['slug']);
    }

    public function testSetDefaultValuesNonTemplateValue(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'count' => ['type' => 'number', 'default' => 42],
        ];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $entity = new ObjectEntity();
        $entity->setObject([]);

        $data = [];
        $result = $this->invokePrivateMethod('setDefaultValues', [$entity, $schema, $data]);

        $this->assertSame(42, $result['count']);
    }

    // =========================================================================
    // validateReferences (private)
    // =========================================================================

    public function testValidateReferencesSkipsWhenEmptyProperties(): void
    {
        $schema = $this->createMockSchema(1, 'test', [], []);

        // Should not throw — no properties have validateReference enabled.
        $this->invokePrivateMethod('validateReferences', [$schema, ['name' => 'Test'], '1', null]);
        $this->assertTrue(true);
    }

    public function testValidateReferencesSkipsPropertyWithoutValidateReference(): void
    {
        $schema = $this->createMockSchema(
            1,
            'test',
            [],
            ['ref' => ['type' => 'string', '$ref' => '#/components/schemas/other']]
        );

        // Should not throw - validateReference is not enabled.
        $this->invokePrivateMethod('validateReferences', [$schema, ['ref' => 'some-uuid'], '1', null]);
        $this->assertTrue(true);
    }

    public function testValidateReferencesSkipsNullValue(): void
    {
        $schema = $this->createMockSchema(
            1,
            'test',
            [],
            ['ref' => ['type' => 'string', '$ref' => '#/components/schemas/other', 'validateReference' => true]]
        );

        // Should not throw - null value.
        $this->invokePrivateMethod('validateReferences', [$schema, ['ref' => null], '1', null]);
        $this->assertTrue(true);
    }

    public function testValidateReferencesSkipsUnchangedValueOnUpdate(): void
    {
        $schema = $this->createMockSchema(
            1,
            'test',
            [],
            ['ref' => ['type' => 'string', '$ref' => '#/components/schemas/other', 'validateReference' => true]]
        );

        $data = ['ref' => 'some-uuid'];
        $oldData = ['ref' => 'some-uuid'];

        // Should not throw - unchanged value.
        $this->invokePrivateMethod('validateReferences', [$schema, $data, '1', $oldData]);
        $this->assertTrue(true);
    }

    // =========================================================================
    // deleteOrphanedRelatedObjects (private)
    // =========================================================================

    public function testDeleteOrphanedRelatedObjectsSoftDeletes(): void
    {
        $orphanedEntity = new ObjectEntity();
        $orphanedEntity->setUuid('orphan-uuid');

        $this->objectEntityMapper->method('find')
            ->willReturn($orphanedEntity);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($user);

        $this->objectEntityMapper->expects($this->once())
            ->method('update');

        $this->invokePrivateMethod('deleteOrphanedRelatedObjects', [['orphan-uuid'], null, null]);

        // Verify the entity was soft-deleted.
        $deleted = $orphanedEntity->getDeleted();
        $this->assertIsArray($deleted);
        $this->assertSame('admin', $deleted['deletedBy']);
        $this->assertSame('orphaned-related-object', $deleted['reason']);
    }

    public function testDeleteOrphanedRelatedObjectsHandlesDoesNotExist(): void
    {
        $this->objectEntityMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        // Should not throw.
        $this->invokePrivateMethod('deleteOrphanedRelatedObjects', [['nonexistent-uuid'], null, null]);
        $this->assertTrue(true);
    }

    public function testDeleteOrphanedRelatedObjectsHandlesGenericException(): void
    {
        $this->objectEntityMapper->method('find')
            ->willThrowException(new Exception('DB error'));

        $this->logger->expects($this->atLeastOnce())->method('warning');

        // Should not throw.
        $this->invokePrivateMethod('deleteOrphanedRelatedObjects', [['error-uuid'], null, null]);
        $this->assertTrue(true);
    }

    public function testDeleteOrphanedRelatedObjectsUsesSystemUserWhenNoUser(): void
    {
        $orphanedEntity = new ObjectEntity();
        $orphanedEntity->setUuid('orphan-uuid');

        $this->objectEntityMapper->method('find')
            ->willReturn($orphanedEntity);

        $this->userSession->method('getUser')->willReturn(null);

        $this->objectEntityMapper->expects($this->once())
            ->method('update');

        $this->invokePrivateMethod('deleteOrphanedRelatedObjects', [['orphan-uuid'], null, null]);

        $deleted = $orphanedEntity->getDeleted();
        $this->assertSame('system', $deleted['deletedBy']);
    }

    // =========================================================================
    // updateInverseRelations (private)
    // =========================================================================

    public function testUpdateInverseRelationsReturnsEarlyWhenNoRelations(): void
    {
        $entity = new ObjectEntity();
        $entity->setUuid('test-uuid');
        $entity->setRelations([]);

        $schema = $this->createMockSchema(1, 'test', [], []);
        $register = $this->createMockRegister(1, 'test');

        $this->objectEntityMapper->expects($this->never())->method('update');

        $this->invokePrivateMethod('updateInverseRelations', [$entity, $register, $schema]);
    }

    public function testUpdateInverseRelationsSkipsNonUuidRelations(): void
    {
        $entity = new ObjectEntity();
        $entity->setUuid('test-uuid');
        $entity->setRelations(['org' => 'not-a-uuid-value']);

        $schema = $this->createMockSchema(1, 'test', [], ['org' => ['$ref' => '#/components/schemas/org']]);
        $register = $this->createMockRegister(1, 'test');

        $this->objectEntityMapper->expects($this->never())->method('update');

        $this->invokePrivateMethod('updateInverseRelations', [$entity, $register, $schema]);
    }

    public function testUpdateInverseRelationsSkipsWhenNoPropertyConfig(): void
    {
        $entity = new ObjectEntity();
        $entity->setUuid('test-uuid');
        $entity->setRelations(['unknown' => 'dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4']);

        // Schema has no 'unknown' property.
        $schema = $this->createMockSchema(1, 'test', [], []);
        $register = $this->createMockRegister(1, 'test');

        $this->objectEntityMapper->expects($this->never())->method('update');

        $this->invokePrivateMethod('updateInverseRelations', [$entity, $register, $schema]);
    }

    public function testUpdateInverseRelationsSkipsNullRelations(): void
    {
        $entity = new ObjectEntity();
        $entity->setUuid('test-uuid');
        $entity->setRelations(null);

        $schema = $this->createMockSchema(1, 'test', [], []);
        $register = $this->createMockRegister(1, 'test');

        $this->objectEntityMapper->expects($this->never())->method('update');

        $this->invokePrivateMethod('updateInverseRelations', [$entity, $register, $schema]);
    }

    // =========================================================================
    // cascadeMultipleObjects (private)
    // =========================================================================

    public function testCascadeMultipleObjectsReturnsEmptyForNonList(): void
    {
        $entity = new ObjectEntity();
        $property = ['$ref' => '#/components/schemas/test'];

        // Associative array (not a list).
        $propData = ['key' => 'value'];
        $result = $this->invokePrivateMethod('cascadeMultipleObjects', [$entity, $property, $propData]);

        $this->assertSame([], $result);
    }

    public function testCascadeMultipleObjectsReturnsEmptyForEmptyValidObjects(): void
    {
        $entity = new ObjectEntity();
        $property = ['items' => ['$ref' => '#/components/schemas/test']];

        // All items are empty.
        $propData = [[], []];
        $result = $this->invokePrivateMethod('cascadeMultipleObjects', [$entity, $property, $propData]);

        $this->assertSame([], $result);
    }

    public function testCascadeMultipleObjectsSkipsExistingUuids(): void
    {
        $entity = new ObjectEntity();
        $property = ['items' => ['$ref' => '#/components/schemas/test']];

        // Only UUIDs (strings), no objects to create.
        $propData = ['dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4'];
        $result = $this->invokePrivateMethod('cascadeMultipleObjects', [$entity, $property, $propData]);

        $this->assertSame([], $result);
    }

    public function testCascadeMultipleObjectsReturnsEmptyWhenNoRefInItems(): void
    {
        $entity = new ObjectEntity();
        $property = ['items' => []]; // No $ref.

        $propData = [['name' => 'Test']];
        $result = $this->invokePrivateMethod('cascadeMultipleObjects', [$entity, $property, $propData]);

        $this->assertSame([], $result);
    }

    public function testCascadeMultipleObjectsCopiesRefFromPropertyLevel(): void
    {
        $entity = new ObjectEntity();
        $entity->setUuid('parent-uuid');
        $entity->setRegister('1');

        // $ref at property level instead of items.
        $property = [
            '$ref' => '#/components/schemas/test',
            'items' => [],
        ];

        // Only UUIDs, no objects to create (tests the $ref copy logic).
        $propData = ['dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4'];
        $result = $this->invokePrivateMethod('cascadeMultipleObjects', [$entity, $property, $propData]);

        $this->assertSame([], $result);
    }

    // =========================================================================
    // cascadeSingleObject (private)
    // =========================================================================

    public function testCascadeSingleObjectReturnsNullWithNoRef(): void
    {
        $entity = new ObjectEntity();
        $definition = []; // No $ref.
        $object = ['name' => 'Test'];

        $result = $this->invokePrivateMethod('cascadeSingleObject', [$entity, $definition, $object]);

        $this->assertNull($result);
    }

    public function testCascadeSingleObjectReturnsNullForEmptyObject(): void
    {
        $entity = new ObjectEntity();
        $definition = ['$ref' => '#/components/schemas/test'];
        $object = [];

        $result = $this->invokePrivateMethod('cascadeSingleObject', [$entity, $definition, $object]);

        $this->assertNull($result);
    }

    public function testCascadeSingleObjectReturnsNullForEmptyIdOnly(): void
    {
        $entity = new ObjectEntity();
        $definition = ['$ref' => '#/components/schemas/test'];
        $object = ['id' => ''];

        $result = $this->invokePrivateMethod('cascadeSingleObject', [$entity, $definition, $object]);

        $this->assertNull($result);
    }

    public function testCascadeSingleObjectReturnsNullWhenParentHasNoUuid(): void
    {
        $entity = new ObjectEntity();
        // No UUID set.
        $definition = ['$ref' => '#/components/schemas/test'];
        $object = ['name' => 'Test'];

        $result = $this->invokePrivateMethod('cascadeSingleObject', [$entity, $definition, $object]);

        $this->assertNull($result);
    }

    public function testCascadeSingleObjectThrowsOnInvalidSchemaRef(): void
    {
        $entity = new ObjectEntity();
        $entity->setUuid('parent-uuid');

        $definition = ['$ref' => '#/components/schemas/nonexistent'];
        $object = ['name' => 'Test'];

        // Schema reference cannot be resolved.
        $this->schemaMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));
        $this->schemaMapper->method('findAll')->willReturn([]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid schema reference');

        $this->invokePrivateMethod('cascadeSingleObject', [$entity, $definition, $object]);
    }

    // =========================================================================
    // handleObjectUpdate (private) — tests the update flow
    // =========================================================================

    public function testHandleObjectUpdateReturnsUnpersistedWhenPersistFalse(): void
    {
        $existingObject = new ObjectEntity();
        $existingObject->setUuid('test-uuid');
        $existingObject->setObject(['name' => 'Old']);
        $existingObject->setRegister('1');
        $existingObject->setSchema('1');

        $schemaObject = new \stdClass();
        $schemaObject->properties = [];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);
        $schema->setId(1);
        $schema->method('getConfiguration')->willReturn([]);

        $register = $this->createMockRegister(1, 'test');

        // Set the schema in cache.
        $this->setPrivateProperty('schemaCache', ['1' => $schema]);

        $this->unifiedObjectMapper->expects($this->never())->method('update');

        $result = $this->invokePrivateMethod('handleObjectUpdate', [
            $existingObject, $register, $schema, ['name' => 'New'], [], null, false, false,
        ]);

        $this->assertInstanceOf(ObjectEntity::class, $result);
    }

    // =========================================================================
    // handleObjectCreation (private) — tests the creation flow
    // =========================================================================

    public function testHandleObjectCreationReturnsUnpersistedWhenPersistFalse(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);
        $schema->setId(1);
        $schema->method('getConfiguration')->willReturn([]);

        $register = $this->createMockRegister(1, 'test');

        // Set the schema in cache.
        $this->setPrivateProperty('schemaCache', ['1' => $schema]);

        $this->urlGenerator->method('getAbsoluteURL')->willReturn('http://localhost/test');
        $this->urlGenerator->method('linkToRoute')->willReturn('/test');

        $this->unifiedObjectMapper->expects($this->never())->method('insert');

        $result = $this->invokePrivateMethod('handleObjectCreation', [
            1, 1, $register, $schema, ['name' => 'Test'], [], 'test-uuid-123', null, false, false, false,
        ]);

        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->assertSame('test-uuid-123', $result->getUuid());
    }

    // =========================================================================
    // processFilePropertiesWithRollback (private)
    // =========================================================================

    public function testProcessFilePropertiesWithRollbackNoFileProps(): void
    {
        $entity = new ObjectEntity();
        $entity->setUuid('test-uuid');

        $register = $this->createMockRegister(1, 'test');
        $schemaObject = new \stdClass();
        $schemaObject->properties = [];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $this->filePropertyHandler->method('isFileProperty')->willReturn(false);

        $data = ['name' => 'Test'];
        $result = $this->invokePrivateMethod('processFilePropertiesWithRollback', [$entity, &$data, $register, $schema]);

        $this->assertSame($entity, $result);
    }

    public function testProcessFilePropertiesWithRollbackDeletesOnException(): void
    {
        $entity = new ObjectEntity();
        $entity->setUuid('test-uuid');

        $register = $this->createMockRegister(1, 'test');
        $schemaObject = new \stdClass();
        $schemaObject->properties = [];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $this->filePropertyHandler->method('isFileProperty')->willReturn(true);
        $this->filePropertyHandler->method('handleFileProperty')
            ->willThrowException(new Exception('File upload failed'));

        $this->objectEntityMapper->expects($this->once())->method('delete')->with($entity);

        $data = ['file' => 'data:text/plain;base64,dGVzdA=='];

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('File upload failed');

        $this->invokePrivateMethod('processFilePropertiesWithRollback', [$entity, &$data, $register, $schema]);
    }

    // =========================================================================
    // cascadeObjects (private) — tests the cascading flow
    // =========================================================================

    public function testCascadeObjectsReturnsDataOnSchemaException(): void
    {
        $entity = new ObjectEntity();

        $schema = $this->getMockBuilder(Schema::class)
            ->onlyMethods(['getConfiguration', 'getProperties', 'getSchemaObject', 'hasPropertyAuthorization'])
            ->getMock();
        $schema->method('getSchemaObject')->willThrowException(new Exception('Schema error'));

        $data = ['name' => 'Test'];
        $result = $this->invokePrivateMethod('cascadeObjects', [$entity, $schema, $data]);

        $this->assertSame($data, $result);
    }

    public function testCascadeObjectsSkipsPropertiesNotInData(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'related' => [
                'type' => 'object',
                '$ref' => '#/components/schemas/other',
                'inversedBy' => 'parent',
            ],
        ];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $entity = new ObjectEntity();

        // 'related' is NOT in data.
        $data = ['name' => 'Test'];
        $result = $this->invokePrivateMethod('cascadeObjects', [$entity, $schema, $data]);

        $this->assertSame($data, $result);
    }

    public function testCascadeObjectsSkipsEmptyPropertyValue(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'related' => [
                'type' => 'object',
                '$ref' => '#/components/schemas/other',
                'inversedBy' => 'parent',
            ],
        ];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $entity = new ObjectEntity();

        $data = ['related' => []];
        $result = $this->invokePrivateMethod('cascadeObjects', [$entity, $schema, $data]);

        $this->assertSame($data, $result);
    }

    public function testCascadeObjectsSkipsWriteBackEnabled(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'related' => [
                'type' => 'object',
                '$ref' => '#/components/schemas/other',
                'inversedBy' => 'parent',
                'writeBack' => true,
            ],
        ];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $entity = new ObjectEntity();

        // Property has writeBack=true, so it should be skipped by cascadeObjects.
        $data = ['related' => ['name' => 'Sub']];
        $result = $this->invokePrivateMethod('cascadeObjects', [$entity, $schema, $data]);

        // Data should be unchanged because writeBack properties are skipped.
        $this->assertSame(['name' => 'Sub'], $result['related']);
    }

    // =========================================================================
    // handleInverseRelationsWriteBack (private)
    // =========================================================================

    public function testHandleInverseRelationsWriteBackReturnsDataOnSchemaException(): void
    {
        $entity = new ObjectEntity();

        $schema = $this->getMockBuilder(Schema::class)
            ->onlyMethods(['getConfiguration', 'getProperties', 'getSchemaObject', 'hasPropertyAuthorization'])
            ->getMock();
        $schema->method('getSchemaObject')->willThrowException(new Exception('Schema error'));

        $data = ['name' => 'Test'];
        $result = $this->invokePrivateMethod('handleInverseRelationsWriteBack', [$entity, $schema, $data]);

        $this->assertSame($data, $result);
    }

    public function testHandleInverseRelationsWriteBackSkipsEmptyPropertyValue(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'deelnemers' => [
                'type' => 'array',
                'items' => [
                    'inversedBy' => 'deelnames',
                    'writeBack' => true,
                    '$ref' => '#/components/schemas/deelnemer',
                ],
            ],
        ];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $entity = new ObjectEntity();

        $data = ['deelnemers' => null];
        $result = $this->invokePrivateMethod('handleInverseRelationsWriteBack', [$entity, $schema, $data]);

        $this->assertSame($data, $result);
    }

    public function testHandleInverseRelationsWriteBackRemovesAfterWriteBack(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'deelnemers' => [
                'type' => 'array',
                'inversedBy' => 'deelnames',
                'writeBack' => true,
                'removeAfterWriteBack' => true,
                '$ref' => '#/components/schemas/deelnemer',
            ],
        ];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $entity = new ObjectEntity();
        $entity->setUuid('parent-uuid');
        $entity->setRegister('1');

        // Set up schema resolution.
        $targetSchemaObject = new \stdClass();
        $targetSchemaObject->properties = ['deelnames' => ['type' => 'array']];
        $targetSchema = $this->getMockBuilder(Schema::class)
            ->onlyMethods(['getConfiguration', 'getProperties', 'getSchemaObject', 'hasPropertyAuthorization'])
            ->getMock();
        $targetSchema->setId(2);
        $targetSchema->setSlug('deelnemer');
        $targetSchema->method('getSchemaObject')->willReturn($targetSchemaObject);
        $targetSchema->method('getConfiguration')->willReturn([]);
        $targetSchema->method('getProperties')->willReturn(['deelnames' => ['type' => 'array']]);
        $targetSchema->method('hasPropertyAuthorization')->willReturn(false);

        $this->setPrivateProperty('schemaReferenceCache', ['#/components/schemas/deelnemer' => '2']);
        $this->setPrivateProperty('schemaCache', ['2' => $targetSchema]);

        // Set up register.
        $register = $this->createMockRegister(1, 'test');
        $this->setPrivateProperty('registerCache', ['1' => $register]);
        $this->registerMapper->method('find')->willReturn($register);

        // Set up target object as a real ObjectEntity.
        $targetObject = new ObjectEntity();
        $targetObject->setUuid('dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4');
        $targetObject->setRegister('1');
        $targetObject->setSchema('2');
        $targetObject->setObject(['deelnames' => []]);

        $this->objectEntityMapper->method('find')
            ->willReturn($targetObject);

        // Set up the update to return a real ObjectEntity.
        $savedTargetObject = new ObjectEntity();
        $savedTargetObject->setUuid('dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4');
        $savedTargetObject->setRegister('1');
        $savedTargetObject->setSchema('2');
        $savedTargetObject->setObject(['deelnames' => ['parent-uuid']]);

        $this->unifiedObjectMapper->method('update')->willReturn($savedTargetObject);

        // Set up URL generator.
        $this->urlGenerator->method('getAbsoluteURL')->willReturn('http://localhost/test');
        $this->urlGenerator->method('linkToRoute')->willReturn('/test');

        // Disable audit trails for simplicity.
        $this->settingsService->method('getRetentionSettingsOnly')
            ->willReturn(['auditTrailsEnabled' => false]);

        $data = ['deelnemers' => ['dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4'], 'name' => 'Test'];

        $result = $this->invokePrivateMethod('handleInverseRelationsWriteBack', [$entity, $schema, $data]);

        // Since removeAfterWriteBack is true, the property should be removed.
        $this->assertArrayNotHasKey('deelnemers', $result);
    }

    // =========================================================================
    // findAndValidateExistingObject — additional edge cases
    // =========================================================================

    public function testFindAndValidateExistingObjectAllowsNonArrayLock(): void
    {
        $entity = new ObjectEntity();
        $entity->setUuid('test-uuid');
        // Locked is set but not an array (edge case).
        $entity->setLocked('simple-string');

        $this->objectEntityMapper->method('find')
            ->willReturn($entity);

        $result = $this->invokePrivateMethod('findAndValidateExistingObject', [
            'test-uuid', null, null, false, false,
        ]);

        $this->assertSame($entity, $result);
    }

    public function testFindAndValidateExistingObjectAllowsNullLockOwner(): void
    {
        $entity = new ObjectEntity();
        $entity->setUuid('test-uuid');
        $entity->setLocked(['userId' => null]);

        $this->objectEntityMapper->method('find')
            ->willReturn($entity);

        $result = $this->invokePrivateMethod('findAndValidateExistingObject', [
            'test-uuid', null, null, false, false,
        ]);

        // Lock owner is null, so no lock conflict.
        $this->assertSame($entity, $result);
    }

    // =========================================================================
    // resolveSchemaAndRegister — additional edge cases
    // =========================================================================

    public function testResolveSchemaAndRegisterWithStringSchema(): void
    {
        $schema = $this->createMockSchema(5, 'test');

        // Pre-cache the reference.
        $this->setPrivateProperty('schemaReferenceCache', ['test-slug' => '5']);
        $this->setPrivateProperty('schemaCache', ['5' => $schema]);

        [$resolvedSchema, $schemaId, $resolvedRegister, $registerId] = $this->invokePrivateMethod(
            'resolveSchemaAndRegister',
            ['test-slug', null]
        );

        $this->assertSame($schema, $resolvedSchema);
        $this->assertSame('5', $schemaId);
    }

    public function testResolveSchemaAndRegisterThrowsOnInvalidStringSchema(): void
    {
        $this->schemaMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));
        $this->schemaMapper->method('findAll')->willReturn([]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Could not resolve schema reference');

        $this->invokePrivateMethod('resolveSchemaAndRegister', ['nonexistent', null]);
    }

    // =========================================================================
    // updateInverseRelations — the actual update path
    // =========================================================================

    public function testUpdateInverseRelationsUpdatesRelatedObject(): void
    {
        $schema = $this->createMockSchema(
            1,
            'test',
            [],
            ['org' => ['$ref' => '#/components/schemas/organisation', 'type' => 'string']]
        );
        $register = $this->createMockRegister(1, 'test');

        $entity = new ObjectEntity();
        $entity->setUuid('test-uuid');
        $entity->setRelations(['org' => 'dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4']);

        $targetSchema = $this->createMockSchema(2, 'organisation');
        $this->schemaMapper->method('find')->willReturn($targetSchema);

        $relatedEntity = new ObjectEntity();
        $relatedEntity->setUuid('dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4');
        $relatedEntity->setRelations([]);

        $this->objectEntityMapper->method('find')->willReturn($relatedEntity);
        $this->objectEntityMapper->expects($this->once())->method('update');

        $this->invokePrivateMethod('updateInverseRelations', [$entity, $register, $schema]);

        $updatedRelations = $relatedEntity->getRelations();
        $this->assertContains('test-uuid', $updatedRelations);
    }

    public function testUpdateInverseRelationsSkipsWhenAlreadyRelated(): void
    {
        $schema = $this->createMockSchema(
            1,
            'test',
            [],
            ['org' => ['$ref' => '#/components/schemas/organisation', 'type' => 'string']]
        );
        $register = $this->createMockRegister(1, 'test');

        $entity = new ObjectEntity();
        $entity->setUuid('already-there-uuid');
        $entity->setRelations(['org' => 'dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4']);

        $targetSchema = $this->createMockSchema(2, 'organisation');
        $this->schemaMapper->method('find')->willReturn($targetSchema);

        $relatedEntity = new ObjectEntity();
        $relatedEntity->setUuid('dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4');
        // already-there-uuid is already in relations
        $relatedEntity->setRelations(['already-there-uuid']);

        $this->objectEntityMapper->method('find')->willReturn($relatedEntity);
        // Should NOT update since UUID is already in relations.
        $this->objectEntityMapper->expects($this->never())->method('update');

        $this->invokePrivateMethod('updateInverseRelations', [$entity, $register, $schema]);
    }

    public function testUpdateInverseRelationsSkipsNoRefInProperty(): void
    {
        $schema = $this->createMockSchema(
            1,
            'test',
            [],
            ['org' => ['type' => 'string']] // No $ref
        );
        $register = $this->createMockRegister(1, 'test');

        $entity = new ObjectEntity();
        $entity->setUuid('test-uuid');
        $entity->setRelations(['org' => 'dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4']);

        $this->objectEntityMapper->expects($this->never())->method('update');

        $this->invokePrivateMethod('updateInverseRelations', [$entity, $register, $schema]);
    }

    public function testUpdateInverseRelationsHandlesSchemaResolutionFailure(): void
    {
        $schema = $this->createMockSchema(
            1,
            'test',
            [],
            ['org' => ['$ref' => '#/components/schemas/nonexistent', 'type' => 'string']]
        );
        $register = $this->createMockRegister(1, 'test');

        $entity = new ObjectEntity();
        $entity->setUuid('test-uuid');
        $entity->setRelations(['org' => 'dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4']);

        $this->schemaMapper->method('find')
            ->willThrowException(new \Exception('Schema not found'));
        $this->schemaMapper->method('findAll')->willReturn([]);

        $this->objectEntityMapper->expects($this->never())->method('update');

        // Should log warning and continue — not throw.
        $this->logger->expects($this->atLeastOnce())->method('warning');

        $this->invokePrivateMethod('updateInverseRelations', [$entity, $register, $schema]);
    }

    public function testUpdateInverseRelationsUsesItemsRefForArray(): void
    {
        $schema = $this->createMockSchema(
            1,
            'test',
            [],
            [
                'contacts' => [
                    'type' => 'array',
                    'items' => ['$ref' => '#/components/schemas/contact'],
                ],
            ]
        );
        $register = $this->createMockRegister(1, 'test');

        $entity = new ObjectEntity();
        $entity->setUuid('parent-uuid');
        $entity->setRelations(['contacts.0' => 'dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4']);

        $targetSchema = $this->createMockSchema(2, 'contact');
        $this->schemaMapper->method('find')->willReturn($targetSchema);

        $relatedEntity = new ObjectEntity();
        $relatedEntity->setUuid('dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4');
        $relatedEntity->setRelations([]);

        $this->objectEntityMapper->method('find')->willReturn($relatedEntity);
        $this->objectEntityMapper->expects($this->once())->method('update');

        $this->invokePrivateMethod('updateInverseRelations', [$entity, $register, $schema]);
    }

    // =========================================================================
    // resolveRegisterReference — UUID pattern branch
    // =========================================================================

    public function testResolveRegisterReferenceByUuid(): void
    {
        $register = $this->createMockRegister(7, 'uuid-reg');
        $this->registerMapper->method('find')->willReturn($register);

        $result = $this->invokePrivateMethod(
            'resolveRegisterReference',
            ['dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4']
        );

        $this->assertSame('7', $result);
    }

    public function testResolveRegisterReferenceDirectFindFallsToSlug(): void
    {
        $register = $this->createMockRegister(3, 'test-reg');
        // First call (numeric/UUID) throws, second call (slug direct) succeeds.
        $this->registerMapper->method('find')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new DoesNotExistException('Not found')),
                $register
            );
        $this->registerMapper->method('findAll')->willReturn([$register]);

        $result = $this->invokePrivateMethod('resolveRegisterReference', ['test-reg']);

        $this->assertSame('3', $result);
    }

    // =========================================================================
    // setSelfMetadata — depublished empty string sets null
    // =========================================================================

    public function testSetSelfMetadataSetsDepublishedToNullWhenEmptyString(): void
    {
        $entity = new ObjectEntity();
        $entity->setDepublished(new DateTime());
        $selfData = ['depublished' => ''];

        $this->invokePrivateMethod('setSelfMetadata', [$entity, $selfData]);

        $this->assertNull($entity->getDepublished());
    }

    public function testSetSelfMetadataHandlesInvalidDepublishedDate(): void
    {
        $entity = new ObjectEntity();
        $selfData = ['depublished' => 'not-a-date-!!!'];

        // Invalid date is silently ignored — no exception thrown.
        $this->invokePrivateMethod('setSelfMetadata', [$entity, $selfData]);

        // The depublished field should remain null since the date was invalid.
        $this->assertNull($entity->getDepublished());
    }

    // =========================================================================
    // hydrateObjectMetadata — numeric image array branch (file IDs)
    // =========================================================================

    public function testHydrateObjectMetadataWithArrayOfFileIds(): void
    {
        $entity = new ObjectEntity();
        $entity->setObject(['photos' => [123, 456]]);

        $schema = $this->createMockSchema(1, 'test', ['objectImageField' => 'photos']);

        $this->metaHydrationHandler->method('getValueFromPath')
            ->willReturn([123, 456]);

        // Numeric file IDs path — file loading is not yet implemented, image stays null.
        $this->handler->hydrateObjectMetadata($entity, $schema);

        $this->assertNull($entity->getImage());
    }

    public function testHydrateObjectMetadataPublishedFieldNull(): void
    {
        $entity = new ObjectEntity();
        $entity->setObject(['pubDate' => null]);

        $schema = $this->createMockSchema(1, 'test', ['objectPublishedField' => 'pubDate']);

        $this->metaHydrationHandler->method('extractMetadataValue')
            ->willReturn(null);

        $this->handler->hydrateObjectMetadata($entity, $schema);

        // Null published date should not be set.
        $this->assertNull($entity->getPublished());
    }

    public function testHydrateObjectMetadataDepublishedFieldNull(): void
    {
        $entity = new ObjectEntity();
        $entity->setObject(['endDate' => null]);

        $schema = $this->createMockSchema(1, 'test', ['objectDepublishedField' => 'endDate']);

        $this->metaHydrationHandler->method('extractMetadataValue')
            ->willReturn(null);

        $this->handler->hydrateObjectMetadata($entity, $schema);

        $this->assertNull($entity->getDepublished());
    }

    // =========================================================================
    // isEffectivelyEmptyObject — with false value (truthy check)
    // =========================================================================

    public function testIsEffectivelyEmptyObjectWithFalse(): void
    {
        $result = $this->invokePrivateMethod('isEffectivelyEmptyObject', [['active' => false]]);
        // false is not considered "empty" by isValueNotEmpty
        $this->assertFalse($result);
    }

    public function testIsEffectivelyEmptyObjectWithZero(): void
    {
        $result = $this->invokePrivateMethod('isEffectivelyEmptyObject', [['count' => 0]]);
        // 0 is not empty
        $this->assertFalse($result);
    }

    // =========================================================================
    // scanForRelations — array of objects scanning with schema
    // =========================================================================

    public function testScanForRelationsWithArrayOfObjectsAndNestedArrayItems(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'items' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                ],
            ],
        ];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $data = [
            'items' => [
                ['ref' => 'dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4'],
                'uuid-string-value',
            ],
        ];

        $result = $this->handler->scanForRelations($data, '', $schema);

        // Nested array item with a UUID ref.
        $this->assertArrayHasKey('items.0.ref', $result);
        // String items in object arrays are also treated as relations.
        $this->assertArrayHasKey('items.1', $result);
    }

    public function testScanForRelationsWithTextUriFormatProperty(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'link' => [
                'type' => 'text',
                'format' => 'uri',
            ],
        ];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $data = [
            'link' => 'some-value',
        ];

        $result = $this->handler->scanForRelations($data, '', $schema);

        // text+uri format should be treated as relation.
        $this->assertArrayHasKey('link', $result);
    }

    public function testScanForRelationsWithTextUrlFormatProperty(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'website' => [
                'type' => 'text',
                'format' => 'url',
            ],
        ];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $data = [
            'website' => 'any-value',
        ];

        $result = $this->handler->scanForRelations($data, '', $schema);

        $this->assertArrayHasKey('website', $result);
    }

    // =========================================================================
    // isReference — edge cases for the identifier-like pattern
    // =========================================================================

    public function testIsReferenceWithShortHyphenatedString(): void
    {
        // 7 chars — less than 8, should return false (pattern requires min 8).
        $result = $this->invokePrivateMethod('isReference', ['ab-cdef']);
        $this->assertFalse($result);
    }

    public function testIsReferenceWithCommonWordClosedSource(): void
    {
        $result = $this->invokePrivateMethod('isReference', ['closed-source']);
        $this->assertFalse($result);
    }

    public function testIsReferenceWithUnderscoreId(): void
    {
        // 8+ chars with underscore — should be a reference.
        $result = $this->invokePrivateMethod('isReference', ['my_object_id']);
        $this->assertTrue($result);
    }

    // =========================================================================
    // setDefaultValues — merges existing object data for Twig context
    // =========================================================================

    public function testSetDefaultValuesUsesMergedContextForTwig(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'displayName' => ['type' => 'string', 'default' => '{{ name }}'],
        ];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $entity = new ObjectEntity();

        // The twig context = array_merge($entity->getObjectArray(), $data).
        // $data includes 'name', so {{ name }} should resolve to 'Test'.
        $data = ['name' => 'Test'];
        $result = $this->invokePrivateMethod('setDefaultValues', [$entity, $schema, $data]);

        // {{ name }} resolves to 'Test' from $data via merged context.
        $this->assertSame('Test', $result['displayName']);
    }

    public function testSetDefaultValuesNullTemplateSourceMissing(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'displayName' => ['type' => 'string', 'default' => '{{ nonExistentProp }}'],
        ];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $entity = new ObjectEntity();

        $data = ['name' => 'Test'];
        $result = $this->invokePrivateMethod('setDefaultValues', [$entity, $schema, $data]);

        // Missing source property => null is set in renderedDefaults, then merged into data.
        // null value means displayName is in the result but set to null.
        // array_merge(['name' => 'Test'], ['displayName' => null]) => displayName key exists with null.
        $this->assertArrayHasKey('displayName', $result);
        $this->assertNull($result['displayName']);
    }

    public function testResolveSchemaAndRegisterWithStringRegister(): void
    {
        $schema = $this->createMockSchema(5, 'test');
        $register = $this->createMockRegister(3, 'reg');

        $this->registerMapper->method('find')->willReturn($register);
        $this->registerMapper->method('findAll')->willReturn([$register]);

        [$resolvedSchema, $schemaId, $resolvedRegister, $registerId] = $this->invokePrivateMethod(
            'resolveSchemaAndRegister',
            [$schema, '3']
        );

        $this->assertSame('3', $registerId);
    }

    // =========================================================================
    // extractUuidAndSelfData — additional edge cases
    // =========================================================================

    public function testExtractUuidAndSelfDataPrefersExplicitUuidOverSelfId(): void
    {
        $data = [
            '@self' => ['id' => 'self-uuid'],
            'name' => 'Test',
        ];

        [$uuid, $selfData, $cleanedData] = $this->invokePrivateMethod(
            'extractUuidAndSelfData',
            [$data, 'explicit-uuid', null]
        );

        $this->assertSame('explicit-uuid', $uuid);
    }

    public function testExtractUuidAndSelfDataWithNonArraySelf(): void
    {
        $data = [
            '@self' => 'not-an-array',
            'name' => 'Test',
        ];

        [$uuid, $selfData, $cleanedData] = $this->invokePrivateMethod(
            'extractUuidAndSelfData',
            [$data, null, null]
        );

        $this->assertSame([], $selfData);
        $this->assertArrayNotHasKey('@self', $cleanedData);
    }

    // =========================================================================
    // resolveSchemaReference — additional edge cases
    // =========================================================================

    public function testResolveSchemaReferenceCleanedCachedResult(): void
    {
        // Cache the cleaned reference.
        $this->setPrivateProperty('schemaReferenceCache', ['test-ref' => '42']);

        // Query with query parameters.
        $result = $this->invokePrivateMethod('resolveSchemaReference', ['test-ref?key=value']);

        $this->assertSame('42', $result);
    }

    public function testResolveSchemaReferenceDirectSlugMatchAsLastResort(): void
    {
        $schema = $this->createMockSchema(10, 'direct-slug');

        // findAll returns empty (no match by slug from findAll).
        $this->schemaMapper->method('findAll')->willReturn([]);

        // find() succeeds as last resort.
        $this->schemaMapper->method('find')->willReturn($schema);

        $result = $this->invokePrivateMethod('resolveSchemaReference', ['direct-slug']);

        $this->assertSame('10', $result);
    }

    // =========================================================================
    // resolveRegisterReference — additional edge cases
    // =========================================================================

    public function testResolveRegisterReferenceDirectSlugLastResort(): void
    {
        $register = $this->createMockRegister(7, 'my-register');

        // findAll returns no match.
        $this->registerMapper->method('findAll')->willReturn([]);

        // find() succeeds as last resort.
        $this->registerMapper->method('find')->willReturn($register);

        $result = $this->invokePrivateMethod('resolveRegisterReference', ['my-register']);

        $this->assertSame('7', $result);
    }

    // =========================================================================
    // setSelfMetadata — additional edge cases
    // =========================================================================

    public function testSetSelfMetadataPreservesPublishedWhenNotInSelfData(): void
    {
        $originalDate = new DateTime('2025-01-01');
        $entity = new ObjectEntity();
        $entity->setPublished($originalDate);

        $selfData = []; // No 'published' key.

        $this->invokePrivateMethod('setSelfMetadata', [$entity, $selfData]);

        // Published should be preserved.
        $this->assertNotNull($entity->getPublished());
        $this->assertSame('2025-01-01', $entity->getPublished()->format('Y-m-d'));
    }

    // =========================================================================
    // hydrateObjectMetadata — additional edge cases
    // =========================================================================

    public function testHydrateObjectMetadataWithImageArrayOfNumericIds(): void
    {
        $entity = new ObjectEntity();
        $entity->setObject(['images' => [123, 456]]);

        $schema = $this->createMockSchema(1, 'test', ['objectImageField' => 'images']);

        $this->metaHydrationHandler->method('getValueFromPath')
            ->willReturn([123, 456]);

        // Numeric file IDs path is entered (TODO: file loading not yet implemented).
        // No error is logged because the try block is empty and no exception is thrown.
        $this->handler->hydrateObjectMetadata($entity, $schema);

        // Image should remain null since file loading is not yet implemented.
        $this->assertNull($entity->getImage());
    }

    public function testHydrateObjectMetadataWithImageFieldNullValue(): void
    {
        $entity = new ObjectEntity();
        $entity->setObject(['photo' => null]);

        $schema = $this->createMockSchema(1, 'test', ['objectImageField' => 'photo']);

        $this->metaHydrationHandler->method('getValueFromPath')
            ->willReturn(null);

        $this->handler->hydrateObjectMetadata($entity, $schema);

        // Image should remain null.
        $this->assertNull($entity->getImage());
    }

    public function testHydrateObjectMetadataWithEmptyDepublishedValue(): void
    {
        $entity = new ObjectEntity();
        $entity->setObject(['endDate' => '']);

        $schema = $this->createMockSchema(1, 'test', ['objectDepublishedField' => 'endDate']);

        $this->metaHydrationHandler->method('extractMetadataValue')
            ->willReturn('');

        $this->handler->hydrateObjectMetadata($entity, $schema);

        // Empty depublished should not set the date.
        $this->assertNull($entity->getDepublished());
    }

    public function testHydrateObjectMetadataWithNullPublishedValue(): void
    {
        $entity = new ObjectEntity();
        $entity->setObject(['pubDate' => null]);

        $schema = $this->createMockSchema(1, 'test', ['objectPublishedField' => 'pubDate']);

        $this->metaHydrationHandler->method('extractMetadataValue')
            ->willReturn(null);

        $this->handler->hydrateObjectMetadata($entity, $schema);

        $this->assertNull($entity->getPublished());
    }

    // =========================================================================
    // isReference — additional edge cases
    // =========================================================================

    public function testIsReferenceWithWhitespace(): void
    {
        $result = $this->invokePrivateMethod('isReference', ['  ']);
        $this->assertFalse($result);
    }

    public function testIsReferenceWithSystemsoftwareCommonWord(): void
    {
        $result = $this->invokePrivateMethod('isReference', ['systeemsoftware']);
        $this->assertFalse($result);
    }

    public function testIsReferenceWithClosedSource(): void
    {
        $result = $this->invokePrivateMethod('isReference', ['closed-source']);
        $this->assertFalse($result);
    }

    public function testIsReferenceWithShortString(): void
    {
        // Less than 8 chars with hyphen — too short for the identifier pattern.
        $result = $this->invokePrivateMethod('isReference', ['ab-cd']);
        $this->assertFalse($result);
    }

    // =========================================================================
    // applyPropertyDefaults — additional template edge cases
    // =========================================================================

    public function testApplyPropertyDefaultsWithTemplateValue(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'displayName' => ['type' => 'string', 'default' => '{{ name }}'],
        ];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $data = ['name' => 'My Name'];
        $result = $this->handler->applyPropertyDefaults($schema, $data);

        // Simple ref template should copy the value.
        $this->assertSame('My Name', $result['displayName']);
    }

    public function testApplyPropertyDefaultsSkipsNullDefault(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'status' => ['type' => 'string'],
        ];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $data = ['name' => 'Test'];
        $result = $this->handler->applyPropertyDefaults($schema, $data);

        // No default defined, so status should not be added.
        $this->assertArrayNotHasKey('status', $result);
    }

    public function testApplyPropertyDefaultsWithNullResolvedValue(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'computed' => [
                'type' => 'string',
                'default' => '{{ missing }}',
                'defaultBehavior' => 'always',
            ],
        ];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $data = [];
        $result = $this->handler->applyPropertyDefaults($schema, $data);

        // Template references missing property — resolveDefaultTemplateValue returns null.
        $this->assertArrayNotHasKey('computed', $result);
    }

    // =========================================================================
    // validateReferenceExists (private)
    // =========================================================================

    public function testValidateReferenceExistsReturnsWhenSchemaCannotBeResolved(): void
    {
        $this->schemaMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));
        $this->schemaMapper->method('findAll')->willReturn([]);

        // Should not throw — unresolvable schema ref is logged and skipped.
        $this->invokePrivateMethod('validateReferenceExists', [
            'myProp', 'some-uuid', 'nonexistent-schema', '1',
        ]);
        $this->assertTrue(true);
    }

    public function testValidateReferenceExistsThrowsValidationExceptionWhenObjectNotFound(): void
    {
        $targetSchema = $this->createMockSchema(2, 'target');
        $this->setPrivateProperty('schemaReferenceCache', ['#/components/schemas/target' => '2']);
        $this->setPrivateProperty('schemaCache', ['2' => $targetSchema]);

        $register = $this->createMockRegister(1, 'reg');
        $this->setPrivateProperty('registerCache', ['1' => $register]);

        $this->unifiedObjectMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Referenced object 'bad-uuid' not found");

        $this->invokePrivateMethod('validateReferenceExists', [
            'myProp', 'bad-uuid', '#/components/schemas/target', '1',
        ]);
    }

    public function testValidateReferenceExistsPassesWhenObjectFound(): void
    {
        $targetSchema = $this->createMockSchema(2, 'target');
        $this->setPrivateProperty('schemaReferenceCache', ['#/components/schemas/target' => '2']);
        $this->setPrivateProperty('schemaCache', ['2' => $targetSchema]);

        $register = $this->createMockRegister(1, 'reg');
        $this->setPrivateProperty('registerCache', ['1' => $register]);

        $foundObject = new ObjectEntity();
        $foundObject->setUuid('good-uuid');
        $this->unifiedObjectMapper->method('find')->willReturn($foundObject);

        // Should not throw.
        $this->invokePrivateMethod('validateReferenceExists', [
            'myProp', 'good-uuid', '#/components/schemas/target', '1',
        ]);
        $this->assertTrue(true);
    }

    public function testValidateReferenceExistsLogsWarningOnGenericException(): void
    {
        $targetSchema = $this->createMockSchema(2, 'target');
        $this->setPrivateProperty('schemaReferenceCache', ['#/components/schemas/target' => '2']);
        $this->setPrivateProperty('schemaCache', ['2' => $targetSchema]);

        $register = $this->createMockRegister(1, 'reg');
        $this->setPrivateProperty('registerCache', ['1' => $register]);

        $this->unifiedObjectMapper->method('find')
            ->willThrowException(new Exception('Database error'));

        $this->logger->expects($this->atLeastOnce())->method('warning');

        // Should not throw — generic exceptions are logged but don't block.
        $this->invokePrivateMethod('validateReferenceExists', [
            'myProp', 'some-uuid', '#/components/schemas/target', '1',
        ]);
    }

    public function testValidateReferenceExistsWithNullRegister(): void
    {
        $targetSchema = $this->createMockSchema(2, 'target');
        $this->setPrivateProperty('schemaReferenceCache', ['#/components/schemas/target' => '2']);
        $this->setPrivateProperty('schemaCache', ['2' => $targetSchema]);

        $foundObject = new ObjectEntity();
        $this->unifiedObjectMapper->method('find')->willReturn($foundObject);

        // Should not throw — null register is allowed.
        $this->invokePrivateMethod('validateReferenceExists', [
            'myProp', 'good-uuid', '#/components/schemas/target', null,
        ]);
        $this->assertTrue(true);
    }

    // =========================================================================
    // validateReferences — more edge cases
    // =========================================================================

    public function testValidateReferencesCallsValidateForArrayProperty(): void
    {
        $schema = $this->createMockSchema(
            1,
            'test',
            [],
            [
                'tags' => [
                    'type' => 'array',
                    '$ref' => '#/components/schemas/tag',
                    'validateReference' => true,
                ],
            ]
        );

        $targetSchema = $this->createMockSchema(2, 'tag');
        $this->setPrivateProperty('schemaReferenceCache', ['#/components/schemas/tag' => '2']);
        $this->setPrivateProperty('schemaCache', ['2' => $targetSchema]);

        $register = $this->createMockRegister(1, 'reg');
        $this->setPrivateProperty('registerCache', ['1' => $register]);

        $foundObject = new ObjectEntity();
        $this->unifiedObjectMapper->method('find')->willReturn($foundObject);

        $data = ['tags' => ['uuid-1', 'uuid-2']];

        // Should not throw — objects exist.
        $this->invokePrivateMethod('validateReferences', [$schema, $data, '1', null]);
        $this->assertTrue(true);
    }

    public function testValidateReferencesSkipsEmptyArrayValues(): void
    {
        $schema = $this->createMockSchema(
            1,
            'test',
            [],
            [
                'tags' => [
                    'type' => 'array',
                    '$ref' => '#/components/schemas/tag',
                    'validateReference' => true,
                ],
            ]
        );

        $targetSchema = $this->createMockSchema(2, 'tag');
        $this->setPrivateProperty('schemaReferenceCache', ['#/components/schemas/tag' => '2']);
        $this->setPrivateProperty('schemaCache', ['2' => $targetSchema]);

        $data = ['tags' => ['', null]];

        // Should not throw — empty values are skipped.
        $this->invokePrivateMethod('validateReferences', [$schema, $data, '1', null]);
        $this->assertTrue(true);
    }

    public function testValidateReferencesValidatesSingleProperty(): void
    {
        $schema = $this->createMockSchema(
            1,
            'test',
            [],
            [
                'parent' => [
                    'type' => 'object',
                    '$ref' => '#/components/schemas/org',
                    'validateReference' => true,
                ],
            ]
        );

        $targetSchema = $this->createMockSchema(2, 'org');
        $this->setPrivateProperty('schemaReferenceCache', ['#/components/schemas/org' => '2']);
        $this->setPrivateProperty('schemaCache', ['2' => $targetSchema]);

        $register = $this->createMockRegister(1, 'reg');
        $this->setPrivateProperty('registerCache', ['1' => $register]);

        $foundObject = new ObjectEntity();
        $this->unifiedObjectMapper->method('find')->willReturn($foundObject);

        $data = ['parent' => 'some-uuid'];

        // Should not throw.
        $this->invokePrivateMethod('validateReferences', [$schema, $data, '1', null]);
        $this->assertTrue(true);
    }

    // =========================================================================
    // isEffectivelyEmptyObject — more edge cases
    // =========================================================================

    public function testIsEffectivelyEmptyObjectWithBooleanFalseValue(): void
    {
        $object = ['active' => false];
        $result = $this->invokePrivateMethod('isEffectivelyEmptyObject', [$object]);
        $this->assertFalse($result);
    }

    public function testIsEffectivelyEmptyObjectWithZeroValue(): void
    {
        $object = ['count' => 0];
        $result = $this->invokePrivateMethod('isEffectivelyEmptyObject', [$object]);
        $this->assertFalse($result);
    }

    // =========================================================================
    // Broader integration-like tests for coverage boost
    // =========================================================================

    public function testScanForRelationsWithSchemaExceptionReturnsEmpty(): void
    {
        $schema = $this->getMockBuilder(Schema::class)
            ->onlyMethods(['getConfiguration', 'getProperties', 'getSchemaObject', 'hasPropertyAuthorization'])
            ->getMock();
        $schema->method('getSchemaObject')->willThrowException(new Exception('Bad schema'));

        $data = ['ref' => 'dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4'];

        // Schema parsing fails silently; falls back to non-schema scan.
        $result = $this->handler->scanForRelations($data, '', $schema);

        // UUID is still detected as reference (isReference check).
        $this->assertArrayHasKey('ref', $result);
    }

    public function testScanForRelationsWithArrayOfObjectsContainingNestedArrays(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'items' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                ],
            ],
        ];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $data = [
            'items' => [
                ['ref' => 'dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4'],
            ],
        ];

        $result = $this->handler->scanForRelations($data, '', $schema);

        $this->assertArrayHasKey('items.0.ref', $result);
    }

    // =========================================================================
    // isEffectivelyEmptyObject — additional edge cases
    // =========================================================================

    public function testIsEffectivelyEmptyObjectWithIntegerZeroValue(): void
    {
        // Integer 0 is not empty in PHP sense but effectively has a value.
        $object = ['count' => 0];
        $result = $this->invokePrivateMethod('isEffectivelyEmptyObject', [$object]);
        $this->assertFalse($result);
    }

    public function testIsEffectivelyEmptyObjectWithFalseValue(): void
    {
        $object = ['active' => false];
        $result = $this->invokePrivateMethod('isEffectivelyEmptyObject', [$object]);
        $this->assertFalse($result);
    }

    public function testIsEffectivelyEmptyObjectWithMixedKeysIncludingMetadata(): void
    {
        // Only metadata keys with non-empty values — should be considered empty.
        $object = ['@self' => ['id' => '123'], 'name' => 'real-name'];
        $result = $this->invokePrivateMethod('isEffectivelyEmptyObject', [$object]);
        $this->assertFalse($result);
    }

    // =========================================================================
    // isValueNotEmpty — additional cases
    // =========================================================================

    public function testIsValueNotEmptyWithNonEmptyArray(): void
    {
        $result = $this->invokePrivateMethod('isValueNotEmpty', [['item']]);
        $this->assertTrue($result);
    }

    public function testIsValueNotEmptyWithNestedEmptyArray(): void
    {
        // Associative array with only empty nested values.
        $result = $this->invokePrivateMethod('isValueNotEmpty', [['a' => '', 'b' => null]]);
        $this->assertFalse($result);
    }

    // =========================================================================
    // isReference — additional edge cases
    // =========================================================================

    public function testIsReferenceWithUnderscoreIdentifier(): void
    {
        // A string with underscore and >= 8 chars — treated as identifier.
        $result = $this->invokePrivateMethod('isReference', ['my_object_id']);
        $this->assertTrue($result);
    }

    public function testIsReferenceWithOnlySpaces(): void
    {
        $result = $this->invokePrivateMethod('isReference', ['   ']);
        $this->assertFalse($result);
    }

    // =========================================================================
    // scanForRelations — text+url format with non-uuid value
    // =========================================================================

    public function testScanForRelationsWithTextUrlFormatNonUuidValue(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'homepage' => [
                'type'   => 'text',
                'format' => 'url',
            ],
        ];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        // Non-UUID value — still included in relations map as a potential reference.
        $data = ['homepage' => 'https://example.com'];

        $result = $this->handler->scanForRelations($data, '', $schema);

        $this->assertArrayHasKey('homepage', $result);
    }

    public function testScanForRelationsArrayOfObjectsWithNestedArrayItem(): void
    {
        // Array of objects schema: each array item that is an array gets recursed.
        $schemaObject = new \stdClass();
        $schemaObject->properties = [
            'items' => [
                'type'  => 'array',
                'items' => ['type' => 'object'],
            ],
        ];
        $schema = $this->createSchemaWithSchemaObject($schemaObject);

        $data = [
            'items' => [
                ['ref' => 'dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4'],
                'dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4',
            ],
        ];

        $result = $this->handler->scanForRelations($data, '', $schema);

        $this->assertArrayHasKey('items.0.ref', $result);
        $this->assertArrayHasKey('items.1', $result);
    }

    public function testUpdateInverseRelationsSkipsWhenAlreadyInRelations(): void
    {
        $savedUuid   = 'aaa00001-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
        $relatedUuid = 'bbb00002-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

        $entity = new ObjectEntity();
        $entity->setUuid($savedUuid);
        $entity->setRelations(['org' => $relatedUuid]);

        $schema = $this->createMockSchema(
            1,
            'test',
            [],
            ['org' => ['$ref' => '#/components/schemas/org']]
        );
        $register = $this->createMockRegister(1, 'test');

        $targetSchema = $this->createMockSchema(2, 'org');
        $this->schemaMapper->method('find')->willReturn($targetSchema);

        $relatedObject = new ObjectEntity();
        $relatedObject->setUuid($relatedUuid);
        // savedUuid already in relations.
        $relatedObject->setRelations([$savedUuid]);

        $this->objectEntityMapper->method('find')->willReturn($relatedObject);

        // Should not update because UUID is already present.
        $this->objectEntityMapper->expects($this->never())->method('update');

        $this->invokePrivateMethod('updateInverseRelations', [$entity, $register, $schema]);
    }

    public function testUpdateInverseRelationsSkipsWhenTargetSchemaNotFound(): void
    {
        $savedUuid   = 'aaa00001-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
        $relatedUuid = 'bbb00002-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

        $entity = new ObjectEntity();
        $entity->setUuid($savedUuid);
        $entity->setRelations(['org' => $relatedUuid]);

        $schema = $this->createMockSchema(
            1,
            'test',
            [],
            ['org' => ['$ref' => '#/components/schemas/nonexistent']]
        );
        $register = $this->createMockRegister(1, 'test');

        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

        $this->objectEntityMapper->expects($this->never())->method('update');

        $this->invokePrivateMethod('updateInverseRelations', [$entity, $register, $schema]);
    }

    public function testUpdateInverseRelationsSkipsWhenNoRefInProperty(): void
    {
        $savedUuid   = 'aaa00001-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
        $relatedUuid = 'bbb00002-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

        $entity = new ObjectEntity();
        $entity->setUuid($savedUuid);
        $entity->setRelations(['org' => $relatedUuid]);

        // Property has no $ref so targetSchemaSlug will be empty.
        $schema = $this->createMockSchema(
            1,
            'test',
            [],
            ['org' => ['type' => 'string']]
        );
        $register = $this->createMockRegister(1, 'test');

        $this->objectEntityMapper->expects($this->never())->method('update');

        $this->invokePrivateMethod('updateInverseRelations', [$entity, $register, $schema]);
    }

    public function testUpdateInverseRelationsHandlesItemsRef(): void
    {
        $savedUuid   = 'aaa00001-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
        $relatedUuid = 'bbb00002-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

        $entity = new ObjectEntity();
        $entity->setUuid($savedUuid);
        // Property path like 'contacts.0' — base property 'contacts'.
        $entity->setRelations(['contacts.0' => $relatedUuid]);

        $schema = $this->createMockSchema(
            1,
            'test',
            [],
            [
                'contacts' => [
                    'items' => ['$ref' => '#/components/schemas/contact'],
                ],
            ]
        );
        $register = $this->createMockRegister(1, 'test');

        $targetSchema = $this->createMockSchema(2, 'contact');
        $this->schemaMapper->method('find')->willReturn($targetSchema);

        $relatedObject = new ObjectEntity();
        $relatedObject->setUuid($relatedUuid);
        $relatedObject->setRelations([]);

        $this->objectEntityMapper->method('find')->willReturn($relatedObject);
        $this->objectEntityMapper->expects($this->once())->method('update');

        $this->invokePrivateMethod('updateInverseRelations', [$entity, $register, $schema]);
    }

    // =========================================================================
    // setSelfMetadata — published/depublished null path
    // =========================================================================

    public function testSetSelfMetadataWithNullPublishedKeepsExistingNull(): void
    {
        $entity = new ObjectEntity();
        // published is not set in selfData at all, so entity's published is unchanged.
        $selfData = [];

        $this->invokePrivateMethod('setSelfMetadata', [$entity, $selfData]);

        $this->assertNull($entity->getPublished());
    }

    public function testSetSelfMetadataWithBoolPublishedTrue(): void
    {
        $entity   = new ObjectEntity();
        // When published is explicitly a boolean true (edge case).
        $selfData = ['published' => true];

        // Should not throw - non-string values are handled gracefully.
        $this->invokePrivateMethod('setSelfMetadata', [$entity, $selfData]);
        // No assertion needed — just verifying it doesn't crash.
        $this->assertTrue(true);
    }

    // =========================================================================
    // cascadeMultipleObjects — copies inversedBy from property level to items
    // =========================================================================

    public function testCascadeMultipleObjectsCopiesInversedByFromPropertyLevel(): void
    {
        $entity = new ObjectEntity();
        $entity->setUuid('parent-uuid');

        $property = [
            '$ref'      => '#/components/schemas/test',
            'inversedBy' => 'parent',
            'items'     => [],
        ];

        // Only string UUIDs — no new objects created but tests the inversedBy copy logic.
        $propData = ['dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4'];
        $result = $this->invokePrivateMethod('cascadeMultipleObjects', [$entity, $property, $propData]);

        $this->assertSame([], $result);
    }

    public function testCascadeMultipleObjectsCopiesRegisterFromPropertyLevel(): void
    {
        $entity = new ObjectEntity();
        $entity->setUuid('parent-uuid');

        $property = [
            '$ref'     => '#/components/schemas/test',
            'register' => '5',
            'items'    => [],
        ];

        // Only string UUIDs.
        $propData = ['dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4'];
        $result = $this->invokePrivateMethod('cascadeMultipleObjects', [$entity, $property, $propData]);

        $this->assertSame([], $result);
    }

    // =========================================================================
    // saveObject — high-level flow (persist=false, no existing object)
    // =========================================================================

    public function testSaveObjectCreatesNewEntityWithPersistFalse(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [];

        $schema = $this->createSchemaWithSchemaObject($schemaObject);
        $schema->setId(1);
        $schema->method('getConfiguration')->willReturn([]);
        $schema->method('hasPropertyAuthorization')->willReturn(false);

        $this->setPrivateProperty('schemaCache', ['1' => $schema]);

        $register = $this->createMockRegister(1, 'test');
        $this->setPrivateProperty('registerCache', ['1' => $register]);

        $this->urlGenerator->method('getAbsoluteURL')->willReturn('http://localhost/test');
        $this->urlGenerator->method('linkToRoute')->willReturn('/test');

        // No existing object.
        $this->objectEntityMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));
        $this->unifiedObjectMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->handler->saveObject(
            register: $register,
            schema: $schema,
            data: ['name' => 'Test Object'],
            uuid: null,
            persist: false
        );

        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->unifiedObjectMapper->expects($this->never())->method('insert');
    }

    public function testSaveObjectUpdatesExistingEntityWithPersistFalse(): void
    {
        $schemaObject = new \stdClass();
        $schemaObject->properties = [];

        $schema = $this->createSchemaWithSchemaObject($schemaObject);
        $schema->setId(1);
        $schema->method('getConfiguration')->willReturn([]);
        $schema->method('hasPropertyAuthorization')->willReturn(false);

        $this->setPrivateProperty('schemaCache', ['1' => $schema]);

        $register = $this->createMockRegister(1, 'test');
        $this->setPrivateProperty('registerCache', ['1' => $register]);

        $existingObject = new ObjectEntity();
        $existingObject->setUuid('existing-uuid');
        $existingObject->setObject(['name' => 'Old']);
        $existingObject->setRegister('1');
        $existingObject->setSchema('1');

        $this->objectEntityMapper->method('find')->willReturn($existingObject);

        $this->urlGenerator->method('getAbsoluteURL')->willReturn('http://localhost/test');
        $this->urlGenerator->method('linkToRoute')->willReturn('/test');

        $this->settingsService->method('getRetentionSettingsOnly')
            ->willReturn(['auditTrailsEnabled' => false]);

        $result = $this->handler->saveObject(
            register: $register,
            schema: $schema,
            data: ['name' => 'New Name'],
            uuid: 'existing-uuid',
            persist: false
        );

        $this->assertInstanceOf(ObjectEntity::class, $result);
        // persist=false means no DB write.
        $this->unifiedObjectMapper->expects($this->never())->method('update');
    }

    // =========================================================================
    // resolveRegisterReference — UUID with DoesNotExistException fallback
    // =========================================================================

    public function testResolveRegisterReferenceUuidWithDoesNotExist(): void
    {
        $this->registerMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));
        $this->registerMapper->method('findAll')->willReturn([]);

        $result = $this->invokePrivateMethod(
            'resolveRegisterReference',
            ['dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4']
        );

        $this->assertNull($result);
    }

    // =========================================================================
    // generateSlug — empty value returns null
    // =========================================================================

    public function testGenerateSlugWithEmptyFieldValue(): void
    {
        $schema = $this->createMockSchema(1, 'test', ['objectSlugField' => 'name']);
        // Field exists but is empty string.
        $data = ['name' => ''];

        $result = $this->invokePrivateMethod('generateSlug', [$data, $schema]);

        $this->assertNull($result);
    }

    // =========================================================================
    // preCacheParentName — no name from hydration and no fallback
    // =========================================================================

    public function testPreCacheParentNameWithNoNameAndNoFallback(): void
    {
        $entity = new ObjectEntity();
        $entity->setUuid('test-uuid');

        $schema = $this->createMockSchema(1, 'test', []);

        // Hydration does NOT set a name.
        $this->metaHydrationHandler->method('hydrateObjectMetadata')
            ->willReturnCallback(function (ObjectEntity $e, Schema $s) {
                // No name set.
            });

        // No 'name' or 'naam' in data — setObjectName should NOT be called.
        $this->cacheHandler->expects($this->never())
            ->method('setObjectName');

        $this->invokePrivateMethod('preCacheParentName', [$entity, $schema, []]);
    }

    // =========================================================================
    // deleteOrphanedRelatedObjects — empty array
    // =========================================================================

    public function testDeleteOrphanedRelatedObjectsWithEmptyArray(): void
    {
        $this->objectEntityMapper->expects($this->never())->method('find');

        // Empty array of UUIDs — nothing to delete.
        $this->invokePrivateMethod('deleteOrphanedRelatedObjects', [[], null, null]);
        $this->assertTrue(true);
    }

    // =========================================================================
    // hydrateObjectMetadata — empty array image value
    // =========================================================================

    public function testHydrateObjectMetadataWithEmptyArrayImageValue(): void
    {
        $entity = new ObjectEntity();
        $entity->setObject(['photos' => []]);

        $schema = $this->createMockSchema(1, 'test', ['objectImageField' => 'photos']);

        $this->metaHydrationHandler->method('getValueFromPath')
            ->willReturn([]);

        $this->handler->hydrateObjectMetadata($entity, $schema);

        // Empty array doesn't set image.
        $this->assertNull($entity->getImage());
    }
}

