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
 * @license  EUPL-1.2
 * @link     https://github.com/OpenRegister/OpenRegister
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use Exception;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\LinkedEntityPropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\TmloService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\SaveObject\ReferenceText;
use OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject\SaveLookupsAccess;

/**
 * Deep coverage tests for SaveObject
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class SaveObjectDeepTest extends TestCase {
	/** @var SaveObject */
	private SaveObject $handler;

	/** @var MagicMapper&MockObject */
	private MagicMapper $objectEntityMapper;

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

	protected function setUp(): void {
		parent::setUp();

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

		$permissionHandler = $this->createMock(PermissionHandler::class);

		$this->handler = new SaveObject(
			services: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectServices(
				persistence: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectPersistence(
					mappers: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectMappers(
						catalog: new \OCA\OpenRegister\Db\SchemaCatalog(registers: $this->registerMapper, schemas: $this->schemaMapper, tables: $this->objectEntityMapper),
						unifiedObjectMapper: $this->unifiedObjectMapper
					),
					records: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectRecords(
						auditTrailMapper: $this->auditTrailMapper,
						settingsService: $this->settingsService,
						tmloService: $this->createMock(TmloService::class)
					),
					runtime: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectRuntime(
						cacheHandler: $this->cacheHandler
					)
				),
				handlers: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectHandlers(
					properties: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectPropertyHandlers(
						metaHydrationHandler: $this->metaHydrationHandler,
						filePropertyHandler: $this->filePropertyHandler,
						linkedEntityHandler: $this->createMock(LinkedEntityPropertyHandler::class),
						computedFieldHandler: $this->createMock(ComputedFieldHandler::class)
					),
					values: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectValueHandlers(
						translationHandler: $this->createMock(TranslationHandler::class),
						translationProjectionService: $this->createMock(\OCA\OpenRegister\Service\TranslationProjectionService::class),
						translationStatusService: $this->createMock(\OCA\OpenRegister\Service\TranslationStatusService::class)
					)
				),
				platform: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectPlatform(
					userSession: $this->userSession,
					urlGenerator: $this->urlGenerator,
					logger: $this->logger
				),
				tenancy: new \OCA\OpenRegister\Service\Object\SaveObject\SaveObjectTenancy(
					organisationService: $this->organisationService
				)
			),
			propertyRbacHandler: $this->propertyRbacHandler,
			permissionHandler: $permissionHandler,
			folderManagementHandler: $this->createMock(\OCA\OpenRegister\Service\File\FolderManagementHandler::class)
		);
	}

	private function invokePrivateMethod(string $methodName, array $args = []) {
		if (in_array($methodName, EmptyValueCalls::METHODS, true) === true) {
			return EmptyValueCalls::{$methodName}($this->handler, $args);
		}

		if (in_array($methodName, RelationCalls::METHODS, true) === true) {
			return RelationCalls::{$methodName}($this->handler, $args);
		}

		if (in_array($methodName, PropertyDefaultsCalls::METHODS, true) === true) {
			return PropertyDefaultsCalls::{$methodName}($this->handler, $args);
		}

		$reflection = new ReflectionMethod(SaveObject::class, $methodName);
		$reflection->setAccessible(true);
		return $reflection->invoke($this->handler, ...$args);
	}

	private function setPrivateProperty(string $property, $value): void {
		if (in_array($property, SaveLookupsAccess::CACHES, true) === true) {
			SaveLookupsAccess::set($this->handler, $property, $value);
			return;
		}

		$ref = new ReflectionClass(SaveObject::class);
		$prop = $ref->getProperty($property);
		$prop->setAccessible(true);
		$prop->setValue($this->handler, $value);
	}

	private function getPrivateProperty(string $property) {
		if (in_array($property, SaveLookupsAccess::CACHES, true) === true) {
			return SaveLookupsAccess::get($this->handler, $property);
		}

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
		string $schema = '1',
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
		array $properties = [],
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
	private function createMockRegister(int $id = 1, string $slug = 'test-register'): Register {
		$register = $this->getMockBuilder(Register::class)
			->onlyMethods([])
			->getMock();
		$register->setId($id);
		$register->setSlug($slug);
		return $register;
	}

	// ── resolveSchemaReference tests ────────────────────────────────────

	public function testResolveSchemaReferenceEmpty(): void {
		$result = $this->invokePrivateMethod('resolveSchemaReference', ['']);
		$this->assertNull($result);
	}

	public function testResolveSchemaReferenceCacheHit(): void {
		$this->setPrivateProperty('schemaReferenceCache', ['test-ref' => '42']);
		$result = $this->invokePrivateMethod('resolveSchemaReference', ['test-ref']);
		$this->assertSame('42', $result);
	}

	public function testResolveSchemaReferenceCleanedCacheHit(): void {
		$this->setPrivateProperty('schemaReferenceCache', ['ref' => '99']);
		$result = $this->invokePrivateMethod('resolveSchemaReference', ['ref?param=value']);
		$this->assertSame('99', $result);
	}

	public function testResolveSchemaReferenceNumericId(): void {
		$schema = $this->createMockSchema(42, 'num');

		$this->schemaMapper->method('find')
			->willReturn($schema);

		$result = $this->invokePrivateMethod('resolveSchemaReference', ['42']);
		$this->assertSame('42', $result);
	}

	public function testResolveSchemaReferenceUuid(): void {
		$uuid = 'dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4';
		$schema = $this->createMockSchema(99, 'uuid-schema');

		$this->schemaMapper->method('find')
			->willReturn($schema);

		$result = $this->invokePrivateMethod('resolveSchemaReference', [$uuid]);
		$this->assertSame('99', $result);
	}

	public function testResolveSchemaReferenceUuidNotFound(): void {
		$uuid = 'dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4';

		$this->schemaMapper->method('find')
			->willThrowException(new DoesNotExistException('not found'));
		$this->schemaMapper->method('findAll')
			->willReturn([]);

		$result = $this->invokePrivateMethod('resolveSchemaReference', [$uuid]);
		$this->assertNull($result);
	}

	public function testResolveSchemaReferencePathBySlug(): void {
		$ref = '#/components/schemas/Organisatie';
		$schema = $this->createMockSchema(5, 'organisatie');

		$this->schemaMapper->method('findAll')
			->willReturn([$schema]);

		$result = $this->invokePrivateMethod('resolveSchemaReference', [$ref]);
		$this->assertSame('5', $result);
	}

	public function testResolveSchemaReferenceFindAllException(): void {
		$ref = '#/components/schemas/Broken';

		$this->schemaMapper->method('findAll')
			->willThrowException(new Exception('DB error'));
		$this->schemaMapper->method('find')
			->willThrowException(new Exception('not found'));

		$result = $this->invokePrivateMethod('resolveSchemaReference', [$ref]);
		$this->assertNull($result);
	}

	public function testResolveSchemaReferenceDirectSlugMatch(): void {
		$slug = 'my-custom-schema';
		$schema = $this->createMockSchema(77, 'my-custom-schema');

		$this->schemaMapper->method('findAll')
			->willReturn([]);
		$this->schemaMapper->method('find')
			->willReturn($schema);

		$result = $this->invokePrivateMethod('resolveSchemaReference', [$slug]);
		$this->assertSame('77', $result);
	}

	public function testResolveSchemaReferenceCachesNull(): void {
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

	public function testResolveRegisterReferenceEmpty(): void {
		$result = SaveLookupsAccess::of($this->handler)->registerId(reference: '');
		$this->assertNull($result);
	}

	public function testResolveRegisterReferenceNumericId(): void {
		$register = $this->createMockRegister(5, 'num');

		$this->registerMapper->method('find')
			->willReturn($register);

		$result = SaveLookupsAccess::of($this->handler)->registerId(reference: '5');
		$this->assertSame('5', $result);
	}

	public function testResolveRegisterReferenceUuidNotFound(): void {
		$uuid = 'dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4';

		$this->registerMapper->method('find')
			->willThrowException(new DoesNotExistException('not found'));
		$this->registerMapper->method('findAll')
			->willReturn([]);

		$result = SaveLookupsAccess::of($this->handler)->registerId(reference: $uuid);
		$this->assertNull($result);
	}

	public function testResolveRegisterReferenceSlug(): void {
		$register = $this->createMockRegister(3, 'publication');

		$this->registerMapper->method('findAll')
			->willReturn([$register]);

		$result = SaveLookupsAccess::of($this->handler)->registerId(reference: 'publication');
		$this->assertSame('3', $result);
	}

	public function testResolveRegisterReferenceUrlPath(): void {
		$register = $this->createMockRegister(8, 'voorzieningen');

		$this->registerMapper->method('findAll')
			->willReturn([$register]);

		$result = SaveLookupsAccess::of($this->handler)->registerId(reference: 'http://example.com/api/registers/voorzieningen');
		$this->assertSame('8', $result);
	}

	public function testResolveRegisterReferenceFindAllException(): void {
		$this->registerMapper->method('findAll')
			->willThrowException(new Exception('DB error'));
		$this->registerMapper->method('find')
			->willThrowException(new Exception('not found'));

		$result = SaveLookupsAccess::of($this->handler)->registerId(reference: 'broken-slug');
		$this->assertNull($result);
	}

	public function testResolveRegisterReferenceDirectSlug(): void {
		$register = $this->createMockRegister(11, 'custom');

		$this->registerMapper->method('findAll')
			->willReturn([]);
		$this->registerMapper->method('find')
			->willReturn($register);

		$result = SaveLookupsAccess::of($this->handler)->registerId(reference: 'custom-register');
		$this->assertSame('11', $result);
	}

	// ── removeQueryParameters tests ─────────────────────────────────────

	public function testRemoveQueryParametersNoParams(): void {
		$result = (new ReferenceText())->withoutQuery(reference: 'simple-ref');
		$this->assertSame('simple-ref', $result);
	}

	public function testRemoveQueryParametersStrips(): void {
		$result = (new ReferenceText())->withoutQuery(reference: 'schema?key=val');
		$this->assertSame('schema', $result);
	}

	// ── isReference tests ───────────────────────────────────────────────

	public function testIsReferenceEmpty(): void {
		$this->assertFalse($this->invokePrivateMethod('isReference', ['']));
	}

	public function testIsReferenceStandardUuid(): void {
		$this->assertTrue($this->invokePrivateMethod('isReference', ['dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4']));
	}

	public function testIsReferenceUuidNoDashes(): void {
		$this->assertTrue($this->invokePrivateMethod('isReference', ['dec9ac6ea4fd40fcbe5fe7ef6e5defb4']));
	}

	public function testIsReferencePrefixedUuid(): void {
		$this->assertTrue($this->invokePrivateMethod('isReference', ['id-dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4']));
	}

	public function testIsReferencePrefixedUuidNoDashes(): void {
		$this->assertTrue($this->invokePrivateMethod('isReference', ['ref-dec9ac6ea4fd40fcbe5fe7ef6e5defb4']));
	}

	public function testIsReferenceNumericId(): void {
		$this->assertTrue($this->invokePrivateMethod('isReference', ['12345']));
	}

	public function testIsReferenceUrl(): void {
		$this->assertTrue($this->invokePrivateMethod('isReference', ['https://example.com/api/object/123']));
	}

	public function testIsReferenceIdentifierPattern(): void {
		$this->assertTrue($this->invokePrivateMethod('isReference', ['some-long-identifier-value']));
	}

	public function testIsReferenceCommonWord(): void {
		$this->assertFalse($this->invokePrivateMethod('isReference', ['applicatie']));
	}

	public function testIsReferenceShortText(): void {
		$this->assertFalse($this->invokePrivateMethod('isReference', ['hello']));
	}

	public function testIsReferenceWithWhitespace(): void {
		$this->assertTrue($this->invokePrivateMethod('isReference', ['  dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4  ']));
	}

	// ── scanForRelations tests ──────────────────────────────────────────

	public function testScanForRelationsObjectPropertyType(): void {
		$schema = $this->getMockBuilder(Schema::class)
			->onlyMethods(['getConfiguration', 'getProperties', 'getSchemaObject', 'hasPropertyAuthorization'])
			->getMock();
		$schema->method('getSchemaObject')->willReturn((object)[
			'properties' => (object)[
				'org' => (object)['type' => 'object'],
			],
		]);

		$data = ['org' => 'dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4'];
		$result = $this->handler->scanForRelations($data, '', $schema);
		$this->assertArrayHasKey('org', $result);
	}

	public function testScanForRelationsTextUuidFormat(): void {
		$schema = $this->getMockBuilder(Schema::class)
			->onlyMethods(['getConfiguration', 'getProperties', 'getSchemaObject', 'hasPropertyAuthorization'])
			->getMock();
		$schema->method('getSchemaObject')->willReturn((object)[
			'properties' => (object)[
				'ref' => (object)['type' => 'text', 'format' => 'uuid'],
			],
		]);

		$data = ['ref' => 'dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4'];
		$result = $this->handler->scanForRelations($data, '', $schema);
		$this->assertArrayHasKey('ref', $result);
	}

	public function testScanForRelationsArrayOfObjectsWithStrings(): void {
		$schema = $this->getMockBuilder(Schema::class)
			->onlyMethods(['getConfiguration', 'getProperties', 'getSchemaObject', 'hasPropertyAuthorization'])
			->getMock();
		$schema->method('getSchemaObject')->willReturn((object)[
			'properties' => (object)[
				'items' => (object)[
					'type' => 'array',
					'items' => (object)['type' => 'object'],
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

	public function testScanForRelationsNonObjectArrayItems(): void {
		$data = [
			'tags' => ['dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4', 'not-a-ref'],
		];
		$result = $this->handler->scanForRelations($data);
		$this->assertArrayHasKey('tags.0', $result);
	}

	public function testScanForRelationsWithPrefix(): void {
		$data = ['id' => 'dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4'];
		$result = $this->handler->scanForRelations($data, 'nested');
		$this->assertArrayHasKey('nested.id', $result);
	}

	public function testScanForRelationsSkipsNumericKeys(): void {
		$data = [0 => 'dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4'];
		$result = $this->handler->scanForRelations($data);
		$this->assertEmpty($result);
	}

	public function testScanForRelationsSchemaCatchesException(): void {
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

	public function testShouldApplyDefaultAlways(): void {
		$this->assertTrue($this->invokePrivateMethod('shouldApplyDefault', ['always', ['key' => 'value'], 'key']));
	}

	public function testShouldApplyDefaultFalsyEmptyString(): void {
		$this->assertTrue($this->invokePrivateMethod('shouldApplyDefault', ['falsy', ['key' => ''], 'key']));
	}

	public function testShouldApplyDefaultFalsyEmptyArray(): void {
		$this->assertTrue($this->invokePrivateMethod('shouldApplyDefault', ['falsy', ['key' => []], 'key']));
	}

	public function testShouldApplyDefaultFalsyNonEmpty(): void {
		$this->assertFalse($this->invokePrivateMethod('shouldApplyDefault', ['falsy', ['key' => 'value'], 'key']));
	}

	public function testShouldApplyDefaultMissingKey(): void {
		$this->assertTrue($this->invokePrivateMethod('shouldApplyDefault', ['false', ['other' => 'val'], 'key']));
	}

	public function testShouldApplyDefaultNullValue(): void {
		$this->assertTrue($this->invokePrivateMethod('shouldApplyDefault', ['false', ['key' => null], 'key']));
	}

	public function testShouldApplyDefaultExistingValue(): void {
		$this->assertFalse($this->invokePrivateMethod('shouldApplyDefault', ['false', ['key' => 'val'], 'key']));
	}

	// ── resolveDefaultTemplateValue tests ───────────────────────────────

	public function testResolveDefaultTemplateValueSimpleRefFound(): void {
		$result = $this->invokePrivateMethod('resolveDefaultTemplateValue', ['{{ name }}', ['name' => 'John'], []]);
		$this->assertSame('John', $result);
	}

	public function testResolveDefaultTemplateValueSimpleRefNotFound(): void {
		$result = $this->invokePrivateMethod('resolveDefaultTemplateValue', ['{{ missing }}', ['other' => 'val'], []]);
		$this->assertNull($result);
	}

	public function testResolveDefaultTemplateValuePreservesArray(): void {
		$result = $this->invokePrivateMethod('resolveDefaultTemplateValue', ['{{ items }}', ['items' => ['a', 'b']], []]);
		$this->assertSame(['a', 'b'], $result);
	}

	public function testResolveDefaultTemplateValueComplexTemplate(): void {
		$this->metaHydrationHandler->method('renderTemplate')
			->willReturn('John Doe');

		$result = $this->invokePrivateMethod('resolveDefaultTemplateValue', ['{{ first }} {{ last }}', ['first' => 'John', 'last' => 'Doe'], []]);
		$this->assertSame('John Doe', $result);
	}

	public function testResolveDefaultTemplateValueNonTemplate(): void {
		$result = $this->invokePrivateMethod('resolveDefaultTemplateValue', ['static-value', [], []]);
		$this->assertSame('static-value', $result);
	}

	public function testResolveDefaultTemplateValueNumeric(): void {
		$result = $this->invokePrivateMethod('resolveDefaultTemplateValue', [42, [], []]);
		$this->assertSame(42, $result);
	}

	public function testResolveDefaultTemplateValueException(): void {
		$this->metaHydrationHandler->method('renderTemplate')
			->willThrowException(new Exception('Template error'));

		$result = $this->invokePrivateMethod('resolveDefaultTemplateValue', ['{{ a }} {{ b }}', ['a' => 'x'], []]);
		$this->assertNull($result);
	}

	// createSlug() / generateSlug() were removed (#4037); see ObjectSlugHandlerTest.

	// ── isEffectivelyEmptyObject tests ──────────────────────────────────

	public function testIsEffectivelyEmptyObjectEmpty(): void {
		$this->assertTrue($this->invokePrivateMethod('isEffectivelyEmptyObject', [[]]));
	}

	public function testIsEffectivelyEmptyObjectOnlyMetadata(): void {
		$this->assertTrue($this->invokePrivateMethod('isEffectivelyEmptyObject', [
			['@self' => [], 'id' => '123', '_id' => 'abc'],
		]));
	}

	public function testIsEffectivelyEmptyObjectWithData(): void {
		$this->assertFalse($this->invokePrivateMethod('isEffectivelyEmptyObject', [['name' => 'John']]));
	}

	public function testIsEffectivelyEmptyObjectEmptyValues(): void {
		$this->assertTrue($this->invokePrivateMethod('isEffectivelyEmptyObject', [
			['name' => '', 'items' => [], 'val' => null],
		]));
	}

	// ── hydrateObjectMetadata tests ─────────────────────────────────────

	public function testHydrateObjectMetadataImageArrayDownloadUrl(): void {
		$entity = $this->createObjectEntity(1, 'uuid-1', [
			'photos' => [['downloadUrl' => 'https://example.com/photo.jpg']],
		]);
		$schema = $this->createMockSchema(1, 'test', ['objectImageField' => 'photos']);

		$this->metaHydrationHandler->method('getValueFromPath')
			->willReturn([['downloadUrl' => 'https://example.com/photo.jpg']]);

		$this->handler->hydrateObjectMetadata($entity, $schema);
		$this->assertSame('https://example.com/photo.jpg', $entity->getImage());
	}

	public function testHydrateObjectMetadataImageArrayAccessUrl(): void {
		$entity = $this->createObjectEntity(1, 'uuid-1', [
			'photos' => [['accessUrl' => 'https://example.com/access.jpg']],
		]);
		$schema = $this->createMockSchema(1, 'test', ['objectImageField' => 'photos']);

		$this->metaHydrationHandler->method('getValueFromPath')
			->willReturn([['accessUrl' => 'https://example.com/access.jpg']]);

		$this->handler->hydrateObjectMetadata($entity, $schema);
		$this->assertSame('https://example.com/access.jpg', $entity->getImage());
	}

	public function testHydrateObjectMetadataNumericImageValue(): void {
		$entity = $this->createObjectEntity(1, 'uuid-1', ['imageId' => 42]);
		$schema = $this->createMockSchema(1, 'test', ['objectImageField' => 'imageId']);

		$this->metaHydrationHandler->method('getValueFromPath')
			->willReturn(42);

		$this->logger->expects($this->atLeastOnce())->method('debug');

		$this->handler->hydrateObjectMetadata($entity, $schema);
	}

	public function testHydrateObjectMetadataStringImageUrl(): void {
		$entity = $this->createObjectEntity(1, 'uuid-1', ['photo' => 'https://example.com/image.png']);
		$schema = $this->createMockSchema(1, 'test', ['objectImageField' => 'photo']);

		$this->metaHydrationHandler->method('getValueFromPath')
			->willReturn('https://example.com/image.png');

		$this->handler->hydrateObjectMetadata($entity, $schema);
		$this->assertSame('https://example.com/image.png', $entity->getImage());
	}

	public function testHydrateObjectMetadataImageArrayNumericFileIds(): void {
		$entity = $this->createObjectEntity(1, 'uuid-1', ['images' => [123, 456]]);
		$schema = $this->createMockSchema(1, 'test', ['objectImageField' => 'images']);

		$this->metaHydrationHandler->method('getValueFromPath')
			->willReturn([123, 456]);

		$this->handler->hydrateObjectMetadata($entity, $schema);

		// A bare file id is not a file record, so no image URL is set.
		$this->assertNull($entity->getImage());
	}

	// ── clearAllCaches / cache management tests ─────────────────────────

	public function testClearAllCaches(): void {
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

	public function testTrackCreatedSubObject(): void {
		$this->handler->trackCreatedSubObject('sub-uuid', ['name' => 'child']);
		$subObjects = $this->handler->getCreatedSubObjects();
		$this->assertArrayHasKey('sub-uuid', $subObjects);
		$this->assertSame(['name' => 'child'], $subObjects['sub-uuid']);
	}

	public function testClearCreatedSubObjects(): void {
		$this->handler->trackCreatedSubObject('uuid', ['data']);
		$this->handler->clearCreatedSubObjects();
		$this->assertEmpty($this->handler->getCreatedSubObjects());
	}

	// ── getCachedSchema / getCachedRegister tests ───────────────────────

	public function testGetCachedSchemaFetchesAndCaches(): void {
		$schema = $this->createMockSchema(5, 'cached');

		$this->schemaMapper->expects($this->once())
			->method('find')
			->willReturn($schema);

		$result1 = $this->invokePrivateMethod('getCachedSchema', [5]);
		$result2 = $this->invokePrivateMethod('getCachedSchema', [5]);
		$this->assertSame($result1, $result2);
	}

	public function testGetCachedRegisterFetchesAndCaches(): void {
		$register = $this->createMockRegister(3, 'cached');

		$this->registerMapper->expects($this->once())
			->method('find')
			->willReturn($register);

		$result1 = $this->invokePrivateMethod('getCachedRegister', [3]);
		$result2 = $this->invokePrivateMethod('getCachedRegister', [3]);
		$this->assertSame($result1, $result2);
	}

	// ── applyAlwaysDefaults tests ───────────────────────────────────────

	public function testApplyAlwaysDefaultsNoAlwaysDefaults(): void {
		$schema = $this->getMockBuilder(Schema::class)
			->onlyMethods(['getConfiguration', 'getProperties', 'getSchemaObject', 'hasPropertyAuthorization'])
			->getMock();
		$schema->method('getSchemaObject')->willReturn((object)[
			'properties' => (object)[
				'name' => (object)['default' => 'test', 'defaultBehavior' => 'false'],
			],
		]);

		$data = ['name' => 'existing'];
		$this->assertSame($data, $this->handler->applyAlwaysDefaults($schema, $data));
	}

	public function testApplyAlwaysDefaultsAppliesAlwaysDefaults(): void {
		$schema = $this->getMockBuilder(Schema::class)
			->onlyMethods(['getConfiguration', 'getProperties', 'getSchemaObject', 'hasPropertyAuthorization'])
			->getMock();
		$schema->method('getSchemaObject')->willReturn((object)[
			'properties' => (object)[
				'computed' => (object)['default' => 'auto-value', 'defaultBehavior' => 'always'],
			],
		]);

		$data = ['other' => 'val'];
		$result = $this->handler->applyAlwaysDefaults($schema, $data);
		$this->assertSame('auto-value', $result['computed']);
	}

	public function testApplyAlwaysDefaultsSchemaException(): void {
		$schema = $this->getMockBuilder(Schema::class)
			->onlyMethods(['getConfiguration', 'getProperties', 'getSchemaObject', 'hasPropertyAuthorization'])
			->getMock();
		$schema->method('getSchemaObject')->willThrowException(new Exception('Schema error'));

		$data = ['name' => 'test'];
		$this->assertSame($data, $this->handler->applyAlwaysDefaults($schema, $data));
	}

	public function testApplyAlwaysDefaultsNoProperties(): void {
		$schema = $this->getMockBuilder(Schema::class)
			->onlyMethods(['getConfiguration', 'getProperties', 'getSchemaObject', 'hasPropertyAuthorization'])
			->getMock();
		$schema->method('getSchemaObject')->willReturn((object)[]);

		$data = ['name' => 'test'];
		$this->assertSame($data, $this->handler->applyAlwaysDefaults($schema, $data));
	}

	// ── applyPropertyDefaults tests ─────────────────────────────────────

	public function testApplyPropertyDefaultsAppliesForMissing(): void {
		$schema = $this->getMockBuilder(Schema::class)
			->onlyMethods(['getConfiguration', 'getProperties', 'getSchemaObject', 'hasPropertyAuthorization'])
			->getMock();
		$schema->method('getSchemaObject')->willReturn((object)[
			'properties' => (object)[
				'status' => (object)['default' => 'active', 'defaultBehavior' => 'false'],
			],
		]);

		$data = ['name' => 'test'];
		$result = $this->handler->applyPropertyDefaults($schema, $data);
		$this->assertSame('active', $result['status']);
	}

	public function testApplyPropertyDefaultsSkipsExisting(): void {
		$schema = $this->getMockBuilder(Schema::class)
			->onlyMethods(['getConfiguration', 'getProperties', 'getSchemaObject', 'hasPropertyAuthorization'])
			->getMock();
		$schema->method('getSchemaObject')->willReturn((object)[
			'properties' => (object)[
				'status' => (object)['default' => 'active', 'defaultBehavior' => 'false'],
			],
		]);

		$data = ['status' => 'inactive'];
		$result = $this->handler->applyPropertyDefaults($schema, $data);
		$this->assertSame('inactive', $result['status']);
	}

	public function testApplyPropertyDefaultsSchemaException(): void {
		$schema = $this->getMockBuilder(Schema::class)
			->onlyMethods(['getConfiguration', 'getProperties', 'getSchemaObject', 'hasPropertyAuthorization'])
			->getMock();
		$schema->method('getSchemaObject')->willThrowException(new Exception('error'));

		$data = ['name' => 'test'];
		$this->assertSame($data, $this->handler->applyPropertyDefaults($schema, $data));
	}

	// ── updateObjectRelations tests ─────────────────────────────────────

	public function testUpdateObjectRelations(): void {
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

	public function testSetSelfMetadataNoLongerSetsTheSlug(): void {
		// ObjectSlugHandler decides the slug after hydration (#4037).
		$entity = $this->createObjectEntity(1, 'uuid-1');
		$this->invokePrivateMethod('setSelfMetadata', [$entity, ['slug' => 'my-slug'], []]);
		$this->assertNull($entity->getSlug());
	}

	public function testClientSlugReadsSelfFirst(): void {
		$this->assertSame('my-slug', $this->invokePrivateMethod('clientSlug', [['slug' => 'my-slug'], ['slug' => 'data-slug']]));
	}

	public function testSetSelfMetadataOwnerIsIgnored(): void {
		// SECURITY (wave-7 CRITICAL C2): owner must NOT be settable via client @self input.
		// The authoritative setter is applyOwnerAttribution() which stamps the session
		// user's UID. Accepting owner from $selfData allowed ownership hijacking in
		// background/system contexts where applyOwnerAttribution only fills empty owners.
		$entity = $this->createObjectEntity(1, 'uuid-1');
		// Ensure owner starts as null (default for a new entity).
		$this->assertNull($entity->getOwner());
		// Pass a client-supplied owner value — it must be silently ignored.
		$this->invokePrivateMethod('setSelfMetadata', [$entity, ['owner' => 'injected-owner'], []]);
		// Owner remains null; applyOwnerAttribution() will set it from the session.
		$this->assertNull($entity->getOwner());
	}

	public function testSetSelfMetadataOrganisation(): void {
		// SECURITY (wave-11 SB1 / wave-12 Fix 3): @self.organisation is only applied
		// when the caller is an admin or has verified membership in the requested
		// organisation. With the default mock setup (userSession→null user,
		// groupManager→null so callerIsAdmin() is false, hasAccessToOrganisation→false)
		// BOTH arms of the gate are closed and the organisation must NOT be stamped
		// from client input.
		$entity = $this->createObjectEntity(1, 'uuid-1');
		$this->invokePrivateMethod('setSelfMetadata', [$entity, ['organisation' => 'org-uuid'], []]);
		$this->assertNull($entity->getOrganisation());
	}

	public function testSetSelfMetadataOrganisationWhenCallerHasAccess(): void {
		// SECURITY (wave-11 SB1): When hasAccessToOrganisation returns true the
		// organisation value IS applied (admin / verified member use case).
		$this->organisationService
			->method('hasAccessToOrganisation')
			->with('org-uuid')
			->willReturn(true);

		$entity = $this->createObjectEntity(1, 'uuid-1');
		$this->invokePrivateMethod('setSelfMetadata', [$entity, ['organisation' => 'org-uuid'], []]);
		$this->assertSame('org-uuid', $entity->getOrganisation());
	}

	public function testClientSlugFallsBackToTheData(): void {
		$this->assertSame('data-slug', $this->invokePrivateMethod('clientSlug', [[], ['slug' => 'data-slug']]));
		$this->assertNull($this->invokePrivateMethod('clientSlug', [['slug' => ''], []]));
	}

}
