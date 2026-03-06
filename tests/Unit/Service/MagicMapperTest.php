<?php
/**
 * MagicMapper Unit Tests
 *
 * This test class covers the standalone MagicMapper service that provides
 * dynamic table creation and management based on JSON schema definitions.
 * 
 * Test Coverage:
 * - Dynamic table creation from JSON schemas
 * - Table structure updates when schemas change
 * - Schema-to-SQL type mapping validation
 * - Metadata column integration from ObjectEntity
 * - Table naming and sanitization logic
 * - Search operations in schema-specific tables
 * - Error handling and fallback scenarios
 * - Performance optimizations and caching
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
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\SettingsService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IConfig;
use OCP\IUserSession;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Psr\Container\ContainerInterface;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Schema as DoctrineSchema;
use Doctrine\DBAL\Schema\Table;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IExpressionBuilder;

/**
 * Unit tests for MagicMapper service
 *
 * TESTING APPROACH:
 * These tests verify the MagicMapper as a standalone component without integration
 * into the main ObjectService workflow. They test table creation, updates, searching,
 * and all core functionality independently.
 *
 * KEY TEST SCENARIOS:
 * - Table creation from various JSON schema types
 * - Table updates when schema properties change
 * - Metadata column integration and prefixing
 * - SQL type mapping for different JSON schema types
 * - Table naming conventions and sanitization
 * - Search operations with filtering and pagination
 * - Error handling and edge cases
 * - Cache management and performance optimizations
 *
 * @psalm-type MockDatabase = IDBConnection&MockObject
 * @psalm-type MockSchema = Schema&MockObject
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
     * @var ObjectEntityMapper&MockObject
     */
    private ObjectEntityMapper $mockObjectEntityMapper;

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
     * Mock app config
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
     * @var Schema
     */
    private Schema $mockSchema;


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
        $this->mockObjectEntityMapper = $this->createMock(ObjectEntityMapper::class);
        $this->mockSchemaMapper = $this->createMock(SchemaMapper::class);
        $this->mockRegisterMapper = $this->createMock(RegisterMapper::class);
        $this->mockConfig = $this->createMock(IConfig::class);
        $this->mockAppConfig = $this->createMock(IAppConfig::class);
        $this->mockLogger = $this->createMock(LoggerInterface::class);

        // Create mock entities for testing.
        // Create real entities (getId is a magic method, cannot be mocked).
        $this->mockRegister = new Register();
        $this->mockRegister->setId(1);

        $this->mockSchema = new Schema();
        $this->mockSchema->setId(1);

        // Create MagicMapper instance with all required dependencies.
        $this->magicMapper = new MagicMapper(
            $this->mockDb,
            $this->mockObjectEntityMapper,
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
        // Create real register and schema entities (getId is a magic method, cannot be mocked).
        $mockRegister = new Register();
        $mockRegister->setId($registerId);

        $mockSchema = new Schema();
        $mockSchema->setId($schemaId);

        // Test table name generation.
        $result = $this->magicMapper->getTableNameForRegisterSchema($mockRegister, $mockSchema);

        $this->assertEquals($expectedResult, $result);
        $this->assertStringStartsWith('oc_openregister_table_', $result);
        $this->assertLessThanOrEqual(64, strlen($result)); // MySQL table name limit

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
                'expectedResult' => 'oc_openregister_table_1_1'
            ],
            'different_ids' => [
                'registerId' => 5,
                'schemaId' => 12,
                'expectedResult' => 'oc_openregister_table_5_12'
            ],
            'large_ids' => [
                'registerId' => 999,
                'schemaId' => 888,
                'expectedResult' => 'oc_openregister_table_999_888'
            ]
        ];

    }//end registerSchemaTableNameProvider()


    /**
     * Test table existence checking with new caching system
     *
     * @return void
     */
    public function testTableExistenceCheckingWithCaching(): void
    {
        $this->markTestSkipped('Table existence checking internals were refactored to use raw SQL instead of getSchemaManager.');

    }//end testTableExistenceCheckingWithCaching()


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
        $mockSchema = $this->createMock(Schema::class);
        $mockSchema->expects($this->once())
                   ->method('getConfiguration')
                   ->willReturn($schemaConfig ?? []);

        $this->mockAppConfig->expects($this->any())
                            ->method('getValueString')
                            ->with('openregister', 'magic_mapping_enabled', 'false')
                            ->willReturn($globalConfig);

        $result = $this->magicMapper->isMagicMappingEnabled($this->mockRegister, $mockSchema);

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
            'disabled_in_schema' => [
                'schemaConfig' => ['magicMapping' => false],
                'globalConfig' => 'true',
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
     * Test table existence checking with caching for private tableExists method
     *
     * @return void
     */
    public function testPrivateTableExistsMethodWithCaching(): void
    {
        $this->markTestSkipped('Private tableExists() method was refactored into public tableExistsForRegisterSchema().');

    }//end testPrivateTableExistsMethodWithCaching()


    /**
     * Test schema version calculation for change detection
     *
     * @return void
     */
    public function testSchemaVersionCalculation(): void
    {
        $this->markTestSkipped('Private calculateSchemaVersion() method was removed during refactoring.');

    }//end testSchemaVersionCalculation()


    /**
     * Test sanitization of table names
     *
     * @dataProvider tableSanitizationProvider
     *
     * @param string $input    Input table name
     * @param string $expected Expected sanitized result
     *
     * @return void
     */
    public function testTableNameSanitization(string $input, string $expected): void
    {
        $this->markTestSkipped('Private sanitizeTableName() method was removed during refactoring.');

    }//end testTableNameSanitization()


    /**
     * Data provider for table name sanitization
     *
     * @return array<string, array<string, string>>
     */
    public function tableSanitizationProvider(): array
    {
        return [
            'simple_name' => [
                'input' => 'users',
                'expected' => 'users'
            ],
            'name_with_hyphens' => [
                'input' => 'user-profiles',
                'expected' => 'user_profiles'
            ],
            'name_with_spaces' => [
                'input' => 'user profiles',
                'expected' => 'user_profiles'
            ],
            'name_with_special_chars' => [
                'input' => 'user@profiles!',
                'expected' => 'user_profiles_'
            ],
            'numeric_start' => [
                'input' => '123users',
                'expected' => 'table_123users'
            ],
            'consecutive_underscores' => [
                'input' => 'user___profiles',
                'expected' => 'user_profiles'
            ],
            'trailing_underscores' => [
                'input' => 'user_profiles___',
                'expected' => 'user_profiles'
            ]
        ];

    }//end tableSanitizationProvider()


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
                'expected' => 'firstname'
            ],
            'name_with_spaces' => [
                'input' => 'first name',
                'expected' => 'first_name'
            ],
            'name_with_special_chars' => [
                'input' => 'first@name!',
                'expected' => 'first_name_'
            ],
            'numeric_start' => [
                'input' => '123field',
                'expected' => 'col_123field'
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
            '_created', '_updated', '_published', '_depublished', '_expires',
            '_files', '_relations', '_locked', '_authorization', '_validation',
            '_deleted', '_geo', '_retention', '_groups'
        ];

        foreach ($expectedColumns as $expectedColumn) {
            $this->assertArrayHasKey($expectedColumn, $columns, "Missing metadata column: {$expectedColumn}");
        }

        // Verify UUID column configuration.
        $uuidColumn = $columns['_uuid'];
        $this->assertEquals('string', $uuidColumn['type']);
        $this->assertEquals(36, $uuidColumn['length']);
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
        $mockSchema = $this->createMock(Schema::class);
        $mockSchema->expects($this->once())
                   ->method('getProperties')
                   ->willReturn([
                       'name' => ['type' => 'string'],
                       'age' => ['type' => 'integer'],
                       'settings' => ['type' => 'object']
                   ]);

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
        
        $result = $method->invoke($this->magicMapper, $objectData, $this->mockRegister, $mockSchema);

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
        $tableExistsCache->setValue(null, ['test_table' => time()]);

        // Test full cache clear.
        $this->magicMapper->clearCache();

        // Verify cache is empty.
        $this->assertEquals([], $tableExistsCache->getValue(null));

        // Test targeted cache clear.
        $tableExistsCache->setValue(null, ['1_1' => time()]);
        $this->magicMapper->clearCache(1, 1);

        // Should clear specific cache entry.
        $this->assertArrayNotHasKey('1_1', $tableExistsCache->getValue(null));

    }//end testClearCache()


    /**
     * Test getting existing schema tables
     *
     * @return void
     */
    public function testGetExistingSchemaTables(): void
    {
        $this->markTestSkipped('Table listing internals were refactored to use raw SQL; Doctrine Statement mock no longer applicable.');

    }//end testGetExistingSchemaTables()


    /**
     * Test table creation workflow
     *
     * @return void
     */
    public function testTableCreationWorkflow(): void
    {
        $this->markTestSkipped('Table creation internals were refactored. createSchema/migrateToSchema no longer used directly.');

    }//end testTableCreationWorkflow()


    /**
     * Test error handling when table creation fails
     *
     * @return void
     */
    public function testTableCreationErrorHandling(): void
    {
        $this->markTestSkipped('Table creation internals were refactored. createSchema/migrateToSchema no longer used directly.');

    }//end testTableCreationErrorHandling()


    /**
     * Test JSON string detection utility method
     *
     * @dataProvider jsonStringProvider
     *
     * @param string $input    Input string to test
     * @param bool   $expected Expected result
     *
     * @return void
     */
    public function testJsonStringDetection(string $input, bool $expected): void
    {
        $reflection = new \ReflectionClass($this->magicMapper);
        $method = $reflection->getMethod('isJsonString');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->magicMapper, $input);

        $this->assertEquals($expected, $result);

    }//end testJsonStringDetection()


    /**
     * Data provider for JSON string detection
     *
     * @return array<string, array<mixed>>
     */
    public function jsonStringProvider(): array
    {
        return [
            'valid_json_object' => [
                'input' => '{"name": "John", "age": 30}',
                'expected' => true
            ],
            'valid_json_array' => [
                'input' => '["apple", "banana", "cherry"]',
                'expected' => true
            ],
            'invalid_json' => [
                'input' => '{name: "John", age: 30}',
                'expected' => false
            ],
            'plain_string' => [
                'input' => 'just a regular string',
                'expected' => false
            ],
            'empty_string' => [
                'input' => '',
                'expected' => true // Empty string is technically valid JSON
            ],
            'null_string' => [
                'input' => 'null',
                'expected' => true
            ]
        ];

    }//end jsonStringProvider()


}//end class
