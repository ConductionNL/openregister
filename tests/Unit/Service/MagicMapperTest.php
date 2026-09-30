<?php

/**
 * MagicMapper Unit Tests
 *
 * This test class covers the standalone MagicMapper service that provides
 * dynamic table creation and management based on JSON schema definitions.
 *
 * Test Coverage:
 * - Dynamic table creation from JSON schemas
 * - Schema-to-SQL type mapping validation
 * - Metadata column integration from ObjectEntity
 * - Table naming logic
 * - Column name sanitization
 * - JSON string detection
 * - Cache management
 * - Error handling and fallback scenarios
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\SettingsService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * Testable Schema subclass for MagicMapper tests.
 *
 * Allows overriding getSchemaObject, getConfiguration, and getProperties
 * without relying on PHPUnit mocks of Entity __call methods.
 */
class TestableSchema extends Schema {
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
	public function getSchemaObject(IURLGenerator $urlGenerator): stdClass {
		return $this->testSchemaObject ?? new stdClass();
	}

	/**
	 * Override getConfiguration to return the test value.
	 *
	 * @return array|null
	 */
	public function getConfiguration(): ?array {
		return $this->testConfiguration;
	}

	/**
	 * Override getProperties to return the test value.
	 *
	 * @return array
	 */
	public function getProperties(): array {
		return $this->testProperties ?? [];
	}
}

/**
 * Unit tests for MagicMapper service
 *
 * TESTING APPROACH:
 * These tests verify the MagicMapper as a standalone component without integration
 * into the main ObjectService workflow. They test table naming, column mapping,
 * sanitization, and all core functionality independently.
 *
 * @psalm-type MockDatabase = IDBConnection&MockObject
 * @psalm-type MockConfig = IConfig&MockObject
 */
class MagicMapperTest extends TestCase {

	/**
	 * MagicMapper service instance for testing
	 *
	 * @var MagicMapper
	 */
	private MagicMapper $magicMapper;

	/**
	 * Mock database connection
	 *
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection $mockDb;

	/**
	 * Mock object entity mapper
	 *
	 * @var MagicMapper&MockObject
	 */
	private MagicMapper $mockObjectMapper;

	/**
	 * Mock schema mapper
	 *
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper $mockSchemaMapper;

	/**
	 * Mock register mapper
	 *
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper $mockRegisterMapper;

	/**
	 * Mock configuration service
	 *
	 * @var IConfig&MockObject
	 */
	private IConfig $mockConfig;

	/**
	 * Mock app configuration
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig $mockAppConfig;

	/**
	 * Mock logger
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $mockLogger;

	/**
	 * Register entity for testing
	 *
	 * @var Register
	 */
	private Register $mockRegister;

	/**
	 * Schema entity for testing
	 *
	 * @var TestableSchema
	 */
	private TestableSchema $mockSchema;

	/**
	 * Whether the container hands out a FieldEncryptionHandler (off to test fail-closed).
	 *
	 * @var bool
	 */
	private bool $encryptionAvailable = true;

	/**
	 * Set up test environment before each test
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// Create mock dependencies.
		$this->mockDb = $this->createMock(IDBConnection::class);
		$this->mockObjectMapper = $this->createMock(MagicMapper::class);
		$this->mockSchemaMapper = $this->createMock(SchemaMapper::class);
		$this->mockRegisterMapper = $this->createMock(RegisterMapper::class);
		$this->mockConfig = $this->createMock(IConfig::class);
		$this->mockAppConfig = $this->createMock(IAppConfig::class);
		$this->mockLogger = $this->createMock(LoggerInterface::class);

		// Create real entity instances (Entity __call methods cannot be mocked in PHPUnit 10+).
		$this->mockRegister = new Register();
		$this->mockRegister->setId(1);
		$this->mockRegister->setSlug('test-register');
		$this->mockRegister->setTitle('Test Register');
		$this->mockRegister->setVersion('1.0');

		$this->mockSchema = new TestableSchema();
		$this->mockSchema->setId(1);
		$this->mockSchema->setSlug('test-schema');
		$this->mockSchema->setTitle('Test Schema');
		$this->mockSchema->setVersion('1.0');
		$this->mockSchema->testConfiguration = [];

		// Build a container mock that returns the DateTimeNormalizer when asked
		// — MagicMapper resolves it lazily from the container to construct
		// MagicBulkHandler, and the typed parameter rejects null.
		$dateTimeNormalizer = $this->createMock(DateTimeNormalizer::class);
		$container = $this->createMock(ContainerInterface::class);
		$conditionMatcher = $this->createMock(\OCA\OpenRegister\Service\ConditionMatcher::class);
		$schemaTypeConverter = $this->createMock(\OCA\OpenRegister\Service\Object\SchemaTypeConverter::class);
		// A real FieldEncryptionHandler over a fake ICrypto, so the envelope the
		// mapper writes is the real format with a recognisable payload.
		$crypto = $this->createMock(\OCP\Security\ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(static fn (string $plain): string => 'CIPHER(' . strrev($plain) . ')');
		$fieldEncryption = new \OCA\OpenRegister\Service\FieldEncryptionHandler(crypto: $crypto, logger: $this->mockLogger);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($dateTimeNormalizer, $conditionMatcher, $schemaTypeConverter, $fieldEncryption) {
				if ($id === \OCA\OpenRegister\Service\FieldEncryptionHandler::class) {
					if ($this->encryptionAvailable === false) {
						return null;
					}
					return $fieldEncryption;
				}
				if ($id === DateTimeNormalizer::class
					|| $id === \OCA\OpenRegister\Service\DateTimeNormalizer::class
				) {
					return $dateTimeNormalizer;
				}
				if ($id === \OCA\OpenRegister\Service\ConditionMatcher::class) {
					return $conditionMatcher;
				}
				if ($id === \OCA\OpenRegister\Service\Object\SchemaTypeConverter::class) {
					return $schemaTypeConverter;
				}
				return null;
			}
		);

		// Create MagicMapper instance with all required dependencies.
		$this->magicMapper = new MagicMapper(
			$this->mockDb,
			$this->mockSchemaMapper,
			$this->mockRegisterMapper,
			$this->mockConfig,
			$this->createMock(IEventDispatcher::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->mockAppConfig,
			$this->mockLogger,
			$this->createMock(SettingsService::class),
			$container
		);

	}//end setUp()

	/**
	 * Test table name generation for register+schema combinations
	 *
	 * @dataProvider registerSchemaTableNameProvider
	 *
	 * @param int $registerId The register ID to test
	 * @param int $schemaId The schema ID to test
	 * @param string $expectedResult The expected table name
	 *
	 * @return void
	 */
	public function testGetTableNameForRegisterSchema(int $registerId, int $schemaId, string $expectedResult): void {
		// Create real register and schema instances.
		$register = new Register();
		$register->setId($registerId);

		$schema = new TestableSchema();
		$schema->setId($schemaId);

		// Test table name generation.
		$result = $this->magicMapper->getTableNameForRegisterSchema($register, $schema);

		$this->assertEquals($expectedResult, $result);
		$this->assertStringStartsWith('openregister_table_', $result);

	}//end testGetTableNameForRegisterSchema()

	/**
	 * Data provider for register+schema table name testing
	 *
	 * @return array<string, array<mixed>>
	 */
	public static function registerSchemaTableNameProvider(): array {
		return [
			'basic_combination' => [
				'registerId' => 1,
				'schemaId' => 1,
				'expectedResult' => 'openregister_table_1_1'
			],
			'different_ids' => [
				'registerId' => 5,
				'schemaId' => 12,
				'expectedResult' => 'openregister_table_5_12'
			],
			'large_ids' => [
				'registerId' => 999,
				'schemaId' => 888,
				'expectedResult' => 'openregister_table_999_888'
			]
		];

	}//end registerSchemaTableNameProvider()

	/**
	 * Test magic mapping enablement check for register+schema
	 *
	 * @dataProvider magicMappingConfigProvider
	 *
	 * @param array|null $schemaConfig Schema configuration
	 * @param string $globalConfig Global configuration value
	 * @param bool $expectedResult Expected enablement result
	 *
	 * @return void
	 */
	public function testIsMagicMappingEnabled(?array $schemaConfig, string $globalConfig, bool $expectedResult): void {
		$schema = new TestableSchema();
		$schema->testConfiguration = $schemaConfig ?? [];

		$this->mockAppConfig->expects($this->any())
			->method('getValueString')
			->with('openregister', 'magic_mapping_enabled', 'false')
			->willReturn($globalConfig);

		$result = $this->magicMapper->isMagicMappingEnabled($this->mockRegister, $schema);

		$this->assertEquals($expectedResult, $result);

	}//end testIsMagicMappingEnabled()

	/**
	 * Data provider for magic mapping configuration testing
	 *
	 * @return array<string, array<mixed>>
	 */
	public static function magicMappingConfigProvider(): array {
		return [
			'enabled_in_schema' => [
				'schemaConfig' => ['magicMapping' => true],
				'globalConfig' => 'false',
				'expectedResult' => true
			],
			'disabled_in_schema_global_enabled' => [
				'schemaConfig' => ['magicMapping' => false],
				'globalConfig' => 'true',
				'expectedResult' => true // Schema false does not override global true
			],
			'disabled_in_schema_global_disabled' => [
				'schemaConfig' => ['magicMapping' => false],
				'globalConfig' => 'false',
				'expectedResult' => false
			],
			'not_set_in_schema_global_enabled' => [
				'schemaConfig' => [],
				'globalConfig' => 'true',
				'expectedResult' => true
			],
			'not_set_in_schema_global_disabled' => [
				'schemaConfig' => [],
				'globalConfig' => 'false',
				'expectedResult' => false
			],
			'null_schema_config_global_enabled' => [
				'schemaConfig' => null,
				'globalConfig' => 'true',
				'expectedResult' => true
			]
		];

	}//end magicMappingConfigProvider()

	/**
	 * Test column name sanitization
	 *
	 * @dataProvider columnSanitizationProvider
	 *
	 * @param string $input Input column name
	 * @param string $expected Expected sanitized result
	 *
	 * @return void
	 */
	public function testColumnNameSanitization(string $input, string $expected): void {
		$reflection = new \ReflectionClass($this->magicMapper);
		$method = $reflection->getMethod('sanitizeColumnName');
		$method->setAccessible(true);

		$result = $method->invoke($this->magicMapper, $input);

		$this->assertEquals($expected, $result);

	}//end testColumnNameSanitization()

	/**
	 * Data provider for column name sanitization
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function columnSanitizationProvider(): array {
		return [
			'simple_name' => [
				'input' => 'name',
				'expected' => 'name'
			],
			'camelcase_name' => [
				'input' => 'firstName',
				'expected' => 'first_name'
			],
			'name_with_spaces' => [
				'input' => 'first name',
				'expected' => 'first_name'
			],
			'name_with_special_chars' => [
				'input' => 'first@name!',
				'expected' => 'first_name'
			]
		];

	}//end columnSanitizationProvider()

	/**
	 * Test metadata columns generation
	 *
	 * @return void
	 */
	public function testMetadataColumnsGeneration(): void {
		$reflection = new \ReflectionClass($this->magicMapper);
		$method = $reflection->getMethod('getMetadataColumns');
		$method->setAccessible(true);

		$columns = $method->invoke($this->magicMapper);

		// Verify all expected metadata columns are present.
		$expectedColumns = [
			'_id', '_uuid', '_slug', '_uri', '_version', '_register', '_schema',
			'_owner', '_organisation', '_application', '_folder', '_name',
			'_description', '_summary', '_image', '_size', '_schema_version',
			'_created', '_updated', '_expires',
			'_files', '_relations', '_locked', '_authorization', '_validation',
			'_deleted', '_geo', '_retention', '_groups'
		];

		foreach ($expectedColumns as $expectedColumn) {
			$this->assertArrayHasKey($expectedColumn, $columns, "Missing metadata column: {$expectedColumn}");
		}

		// Verify UUID column configuration.
		$uuidColumn = $columns['_uuid'];
		$this->assertEquals('string', $uuidColumn['type']);
		$this->assertEquals(40, $uuidColumn['length']); // ArchiMate identifiers are max 39 chars
		$this->assertFalse($uuidColumn['nullable']);
		$this->assertTrue($uuidColumn['unique']);

		// Verify primary key configuration.
		$idColumn = $columns['_id'];
		$this->assertEquals('bigint', $idColumn['type']);
		$this->assertFalse($idColumn['nullable']);
		$this->assertTrue($idColumn['autoincrement']);
		$this->assertTrue($idColumn['primary']);

	}//end testMetadataColumnsGeneration()

	/**
	 * Test JSON schema property to SQL column mapping
	 *
	 * @dataProvider schemaPropertyMappingProvider
	 *
	 * @param array $propertyConfig Expected property configuration
	 * @param array $expectedColumn Expected column definition
	 * @param string|null $propertyName Optional property name
	 *
	 * @return void
	 */
	public function testSchemaPropertyToColumnMapping(array $propertyConfig, array $expectedColumn, ?string $propertyName = null): void {
		$propertyName = $propertyName ?? 'testProperty';

		$reflection = new \ReflectionClass($this->magicMapper);
		$method = $reflection->getMethod('mapSchemaPropertyToColumn');
		$method->setAccessible(true);

		$result = $method->invoke($this->magicMapper, $propertyName, $propertyConfig);

		$this->assertIsArray($result);
		$this->assertEquals($expectedColumn['type'], $result['type']);

		if (isset($expectedColumn['length'])) {
			$this->assertEquals($expectedColumn['length'], $result['length']);
		}

		if (isset($expectedColumn['nullable'])) {
			$this->assertEquals($expectedColumn['nullable'], $result['nullable']);
		}

	}//end testSchemaPropertyToColumnMapping()

	/**
	 * Data provider for schema property mapping
	 *
	 * @return array<string, array<mixed>>
	 */
	public static function schemaPropertyMappingProvider(): array {
		return [
			'string_property' => [
				'propertyConfig' => ['type' => 'string'],
				'expectedColumn' => ['type' => 'text', 'nullable' => true]
			],
			'string_with_max_length' => [
				'propertyConfig' => ['type' => 'string', 'maxLength' => 100],
				'expectedColumn' => ['type' => 'string', 'length' => 100, 'nullable' => true]
			],
			'email_format' => [
				'propertyConfig' => ['type' => 'string', 'format' => 'email'],
				'expectedColumn' => ['type' => 'string', 'length' => 320, 'nullable' => true]
			],
			'uuid_format' => [
				'propertyConfig' => ['type' => 'string', 'format' => 'uuid'],
				'expectedColumn' => ['type' => 'string', 'length' => 36, 'nullable' => true]
			],
			'datetime_format' => [
				'propertyConfig' => ['type' => 'string', 'format' => 'date-time'],
				'expectedColumn' => ['type' => 'datetime', 'nullable' => true]
			],
			'integer_property' => [
				'propertyConfig' => ['type' => 'integer'],
				'expectedColumn' => ['type' => 'integer', 'nullable' => true]
			],
			'small_integer' => [
				'propertyConfig' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1000],
				'expectedColumn' => ['type' => 'smallint', 'nullable' => true]
			],
			'big_integer' => [
				'propertyConfig' => ['type' => 'integer', 'maximum' => 9999999999],
				'expectedColumn' => ['type' => 'bigint', 'nullable' => true]
			],
			'number_property' => [
				'propertyConfig' => ['type' => 'number'],
				'expectedColumn' => ['type' => 'decimal', 'nullable' => true]
			],
			'boolean_property' => [
				'propertyConfig' => ['type' => 'boolean'],
				'expectedColumn' => ['type' => 'boolean', 'nullable' => true]
			],
			'array_property' => [
				'propertyConfig' => ['type' => 'array'],
				'expectedColumn' => ['type' => 'json', 'nullable' => true]
			],
			'object_property' => [
				'propertyConfig' => ['type' => 'object'],
				// Object-typed properties use json_ordered to preserve
				// JSON key order on writes (see #1720 / commit 11576838a).
				'expectedColumn' => ['type' => 'json_ordered', 'nullable' => true]
			]
		];

	}//end schemaPropertyMappingProvider()

	/**
	 * Test object data preparation for table storage
	 *
	 * @return void
	 */
	public function testObjectDataPreparationForTable(): void {
		$schema = new TestableSchema();
		$schema->setId(42);
		$schema->testProperties = [
			'name' => ['type' => 'string'],
			'age' => ['type' => 'integer'],
			'settings' => ['type' => 'object'],
		];

		$objectData = [
			'@self' => [
				'uuid' => 'test-uuid-123',
				// register and schema are intentionally supplied with wrong values to
				// verify that the security fix forces them from the authoritative
				// $register/$schema parameters instead (wave-7 CRITICAL C2).
				'register' => 'client-supplied-register',
				'schema' => 'client-supplied-schema',
				// owner reaches this layer ONLY as the server-stamped UID string set by
				// SaveObject::applyOwnerAttribution (client `owner` is stripped upstream in
				// SaveObject::setSelfMetadata, never copied onto the entity). A scalar
				// string owner MUST be persisted so the creator owns their object; a
				// non-string (forged-array) owner is still dropped (asserted below).
				'owner' => 'server-stamped-owner-uid',
				'organisation' => 'test-org',
			],
			'name' => 'John Doe',
			'age' => 30,
			'settings' => ['theme' => 'dark', 'language' => 'en'],
		];

		$reflection = new \ReflectionClass($this->magicMapper);
		$method = $reflection->getMethod('prepareObjectDataForTable');
		$method->setAccessible(true);

		$result = $method->invoke($this->magicMapper, $objectData, $this->mockRegister, $schema);

		// Verify metadata fields are prefixed.
		$this->assertEquals('test-uuid-123', $result['_uuid']);

		// SECURITY (wave-7 C2): register and schema must come from the authoritative
		// method parameters, NOT from client-supplied @self values.
		$this->assertEquals(1, $result['_register']);
		$this->assertEquals(42, $result['_schema']);

		// OWNERSHIP FIX: a scalar string owner (the server-stamped UID produced by
		// SaveObject::applyOwnerAttribution) MUST be persisted to `_owner`. Previously
		// it was unconditionally stripped, leaving an empty `_owner` so the creator
		// could not update their own object and ownership-based RBAC was neutered.
		$this->assertEquals('server-stamped-owner-uid', $result['_owner']);

		$this->assertEquals('test-org', $result['_organisation']);

		// SECURITY (defence-in-depth): a non-string (forged-array) owner shape — which a
		// raw @self injection payload would take — is still dropped at this DB-write
		// boundary, so it can never persist as ownership.
		$forgedData = $objectData;
		$forgedData['@self']['owner'] = ['group' => 'admin'];
		$forgedResult = $method->invoke($this->magicMapper, $forgedData, $this->mockRegister, $schema);
		$this->assertNull($forgedResult['_owner']);

		// Verify schema properties are included.
		$this->assertEquals('John Doe', $result['name']);
		$this->assertEquals(30, $result['age']);

		// Verify complex types are JSON encoded.
		$this->assertIsString($result['settings']);
		$this->assertEquals(['theme' => 'dark', 'language' => 'en'], json_decode($result['settings'], true));

		// Verify created/updated timestamps are set.
		$this->assertNotNull($result['_created']);
		$this->assertNotNull($result['_updated']);

	}//end testObjectDataPreparationForTable()

	/**
	 * A property the schema does not declare is dropped — and now SAYS SO.
	 *
	 * The loop in prepareObjectDataForTable is a whitelist by omission: it walks
	 * the schema's declared properties and copies those out of the payload.
	 * Anything else is never read, and there is no `object` JSON blob column to
	 * fall back on, so it is gone. Until now that happened with no error, no
	 * warning and no trace — the write succeeded and the field was simply
	 * missing.
	 *
	 * Measured 2026-08-02 on the live agentflow schema: the hydra flow documents
	 * carry `$bindings` and `$comment`, the schema declares neither, and the
	 * table has no column for either. Both were being discarded on every save
	 * with nothing anywhere recording it.
	 *
	 * @return void
	 */
	public function testUndeclaredPropertiesAreDroppedButReported(): void {
		$schema = new TestableSchema();
		$schema->setId(42);
		$schema->setTitle('Agent flow');
		$schema->testProperties = [
			'name' => ['type' => 'string'],
			'nodes' => ['type' => 'array'],
		];

		$dropped = [];
		$this->mockLogger->method('warning')->willReturnCallback(
			static function (string $message, array $context = []) use (&$dropped): void {
				if (str_contains($message, 'does not declare') === true) {
					$dropped = ($context['dropped'] ?? []);
				}
			}
		);

		$objectData = [
			'@self' => ['uuid' => 'flow-uuid-1'],
			'name' => 'hydra-file-findings',
			'nodes' => [['id' => 'in']],
			// Undeclared, and load-bearing-looking. These are the real ones.
			'$bindings' => ['forgeCredential' => 'abc'],
			'$comment' => 'why this flow exists',
			// Envelope/metadata keys must NOT be reported — they are not user
			// data the schema was ever meant to declare, and warning about them
			// would fire on literally every save.
			'id' => 'flow-uuid-1',
			'_version' => 3,
		];

		$reflection = new \ReflectionClass($this->magicMapper);
		$method = $reflection->getMethod('prepareObjectDataForTable');
		$method->setAccessible(true);

		$result = $method->invoke($this->magicMapper, $objectData, $this->mockRegister, $schema);

		// Still dropped — this change makes the loss visible, it does not store
		// the field. Rejecting at the DB write boundary would be far too late.
		$this->assertArrayNotHasKey('$bindings', $result);
		$this->assertArrayNotHasKey('$comment', $result);
		$this->assertSame('hydra-file-findings', $result['name']);

		// ...and now it is reported, naming exactly the user-data keys.
		sort($dropped);
		$this->assertSame(['$bindings', '$comment'], $dropped);

	}//end testUndeclaredPropertiesAreDroppedButReported()

	/**
	 * POSITIVE CONTROL: a payload the schema fully declares warns about nothing.
	 *
	 * Without this, the test above is satisfied by a warning that fires always.
	 *
	 * @return void
	 */
	public function testAFullyDeclaredPayloadReportsNoDrop(): void {
		$schema = new TestableSchema();
		$schema->setId(42);
		$schema->setTitle('Agent flow');
		$schema->testProperties = ['name' => ['type' => 'string']];

		$warned = false;
		$this->mockLogger->method('warning')->willReturnCallback(
			static function (string $message) use (&$warned): void {
				if (str_contains($message, 'does not declare') === true) {
					$warned = true;
				}
			}
		);

		$reflection = new \ReflectionClass($this->magicMapper);
		$method = $reflection->getMethod('prepareObjectDataForTable');
		$method->setAccessible(true);

		$method->invoke(
			$this->magicMapper,
			['@self' => ['uuid' => 'u'], 'name' => 'ok', 'id' => 'u'],
			$this->mockRegister,
			$schema
		);

		$this->assertFalse($warned, 'A fully declared payload must not warn.');

	}//end testAFullyDeclaredPayloadReportsNoDrop()

	/**
	 * An encrypted property gets a column, so the write path can store it (#4197).
	 *
	 * The table sync skipped `x-openregister-encrypted` properties, saying the
	 * value "still lives in the table's `object` JSON blob column". No such
	 * column exists. prepareObjectDataForTable() kept naming the property, so a
	 * single-object UPDATE (or INSERT) failed with "column personal_number does
	 * not exist", and the bulk path, which drops unknown columns, silently threw
	 * the value away. The invariant under test: every column the write path
	 * names exists in the table the sync builds.
	 *
	 * Uses the real Schema entity, shaped like learniq's LearnerProfile.
	 *
	 * @return void
	 */
	public function testAnEncryptedPropertyGetsAColumnTheWritePathCanUse(): void {
		$schema = new Schema();
		$schema->setId(43);
		$schema->setSlug('learner-profile');
		$schema->setProperties(
			[
				'displayName'        => ['type' => 'string'],
				'personalNumber'     => ['type' => 'string', 'x-openregister-encrypted' => true],
				'personalNumberType' => ['type' => 'string', 'enum' => ['bsn', 'other']],
				'birthYear'          => ['type' => 'integer', 'x-openregister-encrypted' => true],
			]
		);

		$columns = $this->magicMapper->buildTableColumnsFromSchema(schema: $schema);
		$tableColumns = array_column($columns, 'name');

		$reflection = new \ReflectionClass($this->magicMapper);
		$method = $reflection->getMethod('prepareObjectDataForTable');
		$method->setAccessible(true);

		// What SaveObject hands the mapper: the encrypted values are envelopes by now.
		$prepared = $method->invoke(
			$this->magicMapper,
			[
				'@self'              => ['uuid' => 'profile-1'],
				'displayName'        => 'Learner One',
				'personalNumber'     => 'openregister:enc:v1:ciphertext-for-the-bsn',
				'personalNumberType' => 'bsn',
				'birthYear'          => 'openregister:enc:v1:ciphertext-for-the-year',
			],
			$this->mockRegister,
			$schema
		);

		foreach (array_keys($prepared) as $column) {
			$this->assertContains(
				$column,
				$tableColumns,
				'the write path names column "' . $column . '", which the table sync never creates'
			);
		}

		$this->assertSame('openregister:enc:v1:ciphertext-for-the-bsn', $prepared['personal_number']);

		// Ciphertext is an opaque string whatever the declared type, so the column
		// is TEXT, nullable, and carries no index: it can hold the value and still
		// cannot be searched, sorted or faceted on.
		foreach (['personalNumber', 'birthYear'] as $property) {
			$this->assertArrayHasKey($property, $columns);
			$this->assertSame('text', $columns[$property]['type']);
			$this->assertTrue($columns[$property]['nullable']);
			$this->assertEmpty($columns[$property]['index'] ?? null);
			$this->assertEmpty($columns[$property]['unique'] ?? null);
		}

		// CONTROL: the same property unencrypted keeps its ordinary typed column,
		// so the TEXT above is the encryption flag's doing.
		$plain = new Schema();
		$plain->setId(44);
		$plain->setProperties(['birthYear' => ['type' => 'integer']]);
		$plainColumns = $this->magicMapper->buildTableColumnsFromSchema(schema: $plain);
		$this->assertNotSame('text', $plainColumns['birthYear']['type']);
	}//end testAnEncryptedPropertyGetsAColumnTheWritePathCanUse()

	/**
	 * A learner profile shaped like learniq's, with two encrypted properties.
	 *
	 * @return Schema
	 */
	private function encryptedLearnerProfile(): Schema {
		$schema = new Schema();
		$schema->setId(43);
		$schema->setSlug('learner-profile');
		$schema->setProperties(
			[
				'displayName'    => ['type' => 'string'],
				'personalNumber' => ['type' => 'string', 'x-openregister-encrypted' => true],
			]
		);
		return $schema;
	}//end encryptedLearnerProfile()

	/**
	 * The single-object write path stores an encrypted property only as an envelope.
	 *
	 * SaveObject normally encrypts first; this proves the table itself refuses
	 * plaintext too, and leaves an existing envelope untouched.
	 *
	 * @return void
	 */
	public function testTheSingleWritePathStoresAnEncryptedPropertyOnlyAsAnEnvelope(): void {
		$schema = $this->encryptedLearnerProfile();
		$method = (new \ReflectionClass($this->magicMapper))->getMethod('prepareObjectDataForTable');
		$method->setAccessible(true);

		$plain = $method->invoke(
			$this->magicMapper,
			['@self' => ['uuid' => 'p-1'], 'displayName' => 'Learner', 'personalNumber' => '123456782'],
			$this->mockRegister,
			$schema
		);
		$this->assertSame('openregister:enc:v1:CIPHER(287654321)', $plain['personal_number']);
		$this->assertSame('Learner', $plain['display_name'] ?? $plain['displayName'] ?? null);

		$envelope = $method->invoke(
			$this->magicMapper,
			['@self' => ['uuid' => 'p-1'], 'personalNumber' => 'openregister:enc:v1:already'],
			$this->mockRegister,
			$schema
		);
		$this->assertSame('openregister:enc:v1:already', $envelope['personal_number']);
	}//end testTheSingleWritePathStoresAnEncryptedPropertyOnlyAsAnEnvelope()

	/**
	 * The bulk write path encrypts before the bulk handler sees a row.
	 *
	 * Bulk never ran SaveObject's encryption step. It used to drop the value for
	 * lack of a column; with the column in place it must not store plaintext.
	 * Both row shapes MagicBulkHandler reads are covered.
	 *
	 * @return void
	 */
	public function testTheBulkWritePathEncryptsBeforeTheHandlerSeesARow(): void {
		$seen = [];
		$bulk = $this->createMock(MagicMapper\MagicBulkHandler::class);
		$bulk->method('bulkUpsert')->willReturnCallback(
			static function (array $objects) use (&$seen): array {
				$seen = $objects;
				return [];
			}
		);
		$property = (new \ReflectionClass($this->magicMapper))->getProperty('bulkHandler');
		$property->setAccessible(true);
		$property->setValue($this->magicMapper, $bulk);

		$this->magicMapper->bulkUpsert(
			objects: [
				['@self' => ['uuid' => 'p-1'], 'personalNumber' => '123456782'],
				['@self' => ['uuid' => 'p-2'], 'object' => ['personalNumber' => '999999990']],
				['@self' => ['uuid' => 'p-3'], 'displayName' => 'No number'],
			],
			register: $this->mockRegister,
			schema: $this->encryptedLearnerProfile(),
			tableName: 'openregister_table_1_43'
		);

		$this->assertSame('openregister:enc:v1:CIPHER(287654321)', $seen[0]['personalNumber']);
		$this->assertSame('openregister:enc:v1:CIPHER(099999999)', $seen[1]['object']['personalNumber']);
		$this->assertArrayNotHasKey('personalNumber', $seen[2]);
	}//end testTheBulkWritePathEncryptsBeforeTheHandlerSeesARow()

	/**
	 * Without an encryption handler the write is refused, never stored in the clear.
	 *
	 * @return void
	 */
	public function testAnEncryptedPropertyIsNeverWrittenInTheClearWhenEncryptionIsUnavailable(): void {
		$this->encryptionAvailable = false;
		$method = (new \ReflectionClass($this->magicMapper))->getMethod('prepareObjectDataForTable');
		$method->setAccessible(true);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/refusing to store them in the clear/');

		$method->invoke(
			$this->magicMapper,
			['@self' => ['uuid' => 'p-1'], 'personalNumber' => '123456782'],
			$this->mockRegister,
			$this->encryptedLearnerProfile()
		);
	}//end testAnEncryptedPropertyIsNeverWrittenInTheClearWhenEncryptionIsUnavailable()

	/**
	 * Test clear cache functionality
	 *
	 * @return void
	 */
	public function testClearCache(): void {
		// Set some static cache values using reflection.
		$reflection = new \ReflectionClass($this->magicMapper);

		$tableExistsCache = $reflection->getProperty('tableExistsCache');
		$tableExistsCache->setAccessible(true);
		$tableExistsCache->setValue(null, ['test_table' => time()]);

		// Test full cache clear.
		$this->magicMapper->clearCache();

		// Verify caches are empty.
		$this->assertEquals([], $tableExistsCache->getValue());

		// Test targeted cache clear.
		$tableExistsCache->setValue(null, ['1_1' => time()]);
		$this->magicMapper->clearCache(1, 1);

		// Should clear specific cache entry.
		$this->assertArrayNotHasKey('1_1', $tableExistsCache->getValue());

	}//end testClearCache()

	/**
	 * Test register+schema version calculation
	 *
	 * @return void
	 */
	public function testRegisterSchemaVersionCalculation(): void {
		$schema = new TestableSchema();
		$schema->setId(1);
		$schema->testProperties = ['name' => ['type' => 'string'], 'age' => ['type' => 'integer']];
		$schema->setRequired(['name']);
		$schema->setTitle('Test Schema');
		$schema->setVersion('1.0');

		$register = new Register();
		$register->setId(1);
		$register->setTitle('Test Register');
		$register->setVersion('1.0');

		$reflection = new \ReflectionClass($this->magicMapper);
		$method = $reflection->getMethod('calculateRegisterSchemaVersion');
		$method->setAccessible(true);

		$version = $method->invoke($this->magicMapper, $register, $schema);

		$this->assertIsString($version);
		$this->assertEquals(32, strlen($version)); // MD5 hash length

	}//end testRegisterSchemaVersionCalculation()

}//end class
