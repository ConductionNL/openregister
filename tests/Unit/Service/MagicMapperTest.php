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

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\SettingsService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * Testable Schema subclass for MagicMapper tests.
 *
 * Allows overriding getSchemaObject, getConfiguration, and getProperties
 * without relying on PHPUnit mocks of Entity __call methods.
 */
class TestableSchema extends Schema
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
class MagicMapperTest extends TestCase
{

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
     * Set up test environment before each test
     *
     * @return void
     */
    protected function setUp(): void
    {
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

        // Reset static construct counter to avoid circular dependency guard.
        $ref = new \ReflectionClass(MagicMapper::class);
        $prop = $ref->getProperty('constructCount');
        $prop->setAccessible(true);
        $prop->setValue(null, 0);

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
            $this->createMock(ContainerInterface::class)
        );

    }//end setUp()


    /**
     * Test table name generation for register+schema combinations
     *
     * @dataProvider registerSchemaTableNameProvider
     *
     * @param int    $registerId     The register ID to test
     * @param int    $schemaId       The schema ID to test
     * @param string $expectedResult The expected table name
     *
     * @return void
     */
    public function testGetTableNameForRegisterSchema(int $registerId, int $schemaId, string $expectedResult): void
    {
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
    public function registerSchemaTableNameProvider(): array
    {
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
     * @param array|null $schemaConfig  Schema configuration
     * @param string     $globalConfig  Global configuration value
     * @param bool       $expectedResult Expected enablement result
     *
     * @return void
     */
    public function testIsMagicMappingEnabled(?array $schemaConfig, string $globalConfig, bool $expectedResult): void
    {
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
    public function magicMappingConfigProvider(): array
    {
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
     * @param string $input    Input column name
     * @param string $expected Expected sanitized result
     *
     * @return void
     */
    public function testColumnNameSanitization(string $input, string $expected): void
    {
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
    public function columnSanitizationProvider(): array
    {
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
    public function testMetadataColumnsGeneration(): void
    {
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
     * @param array       $propertyConfig Expected property configuration
     * @param array       $expectedColumn Expected column definition
     * @param string|null $propertyName   Optional property name
     *
     * @return void
     */
    public function testSchemaPropertyToColumnMapping(array $propertyConfig, array $expectedColumn, ?string $propertyName=null): void
    {
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
    public function schemaPropertyMappingProvider(): array
    {
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
                'expectedColumn' => ['type' => 'json', 'nullable' => true]
            ]
        ];

    }//end schemaPropertyMappingProvider()


    /**
     * Test object data preparation for table storage
     *
     * @return void
     */
    public function testObjectDataPreparationForTable(): void
    {
        $schema = new TestableSchema();
        $schema->testProperties = [
            'name' => ['type' => 'string'],
            'age' => ['type' => 'integer'],
            'settings' => ['type' => 'object']
        ];

        $objectData = [
            '@self' => [
                'uuid' => 'test-uuid-123',
                'register' => 'test-register',
                'schema' => 'test-schema',
                'owner' => 'testuser',
                'organisation' => 'test-org'
            ],
            'name' => 'John Doe',
            'age' => 30,
            'settings' => ['theme' => 'dark', 'language' => 'en']
        ];

        $reflection = new \ReflectionClass($this->magicMapper);
        $method = $reflection->getMethod('prepareObjectDataForTable');
        $method->setAccessible(true);

        $result = $method->invoke($this->magicMapper, $objectData, $this->mockRegister, $schema);

        // Verify metadata fields are prefixed.
        $this->assertEquals('test-uuid-123', $result['_uuid']);
        $this->assertEquals('test-register', $result['_register']);
        $this->assertEquals('test-schema', $result['_schema']);
        $this->assertEquals('testuser', $result['_owner']);
        $this->assertEquals('test-org', $result['_organisation']);

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
     * Test clear cache functionality
     *
     * @return void
     */
    public function testClearCache(): void
    {
        // Set some static cache values using reflection.
        $reflection = new \ReflectionClass($this->magicMapper);

        $tableExistsCache = $reflection->getProperty('tableExistsCache');
        $tableExistsCache->setAccessible(true);
        $tableExistsCache->setValue(['test_table' => time()]);

        // Test full cache clear.
        $this->magicMapper->clearCache();

        // Verify caches are empty.
        $this->assertEquals([], $tableExistsCache->getValue());

        // Test targeted cache clear.
        $tableExistsCache->setValue(['1_1' => time()]);
        $this->magicMapper->clearCache(1, 1);

        // Should clear specific cache entry.
        $this->assertArrayNotHasKey('1_1', $tableExistsCache->getValue());

    }//end testClearCache()


    /**
     * Test register+schema version calculation
     *
     * @return void
     */
    public function testRegisterSchemaVersionCalculation(): void
    {
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
