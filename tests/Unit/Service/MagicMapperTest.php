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
use OCP\IDBConnection;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
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
     * Mock logger
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $mockLogger;

    /**
     * Mock register entity for testing
     *
     * @var Register&MockObject
     */
    private Register $mockRegister;

    /**
     * Mock schema entity for testing
     *
     * @var Schema&MockObject
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
        $this->mockLogger = $this->createMock(LoggerInterface::class);

        // Create mock entities for testing.
        $this->mockRegister = $this->createMock(Register::class);
        $this->mockRegister->method('getId')->willReturn(1);
        $this->mockRegister->method('getSlug')->willReturn('test-register');
        $this->mockRegister->method('getTitle')->willReturn('Test Register');
        $this->mockRegister->method('getVersion')->willReturn('1.0');

        $this->mockSchema = $this->createMock(Schema::class);
        $this->mockSchema->method('getId')->willReturn(1);
        $this->mockSchema->method('getSlug')->willReturn('test-schema');
        $this->mockSchema->method('getTitle')->willReturn('Test Schema');
        $this->mockSchema->method('getVersion')->willReturn('1.0');
        $this->mockSchema->method('getConfiguration')->willReturn([]);

        // Create MagicMapper instance.
        $this->magicMapper = new MagicMapper(
            $this->mockDb,
            $this->mockObjectEntityMapper,
            $this->mockSchemaMapper,
            $this->mockRegisterMapper,
            $this->mockConfig,
            $this->mockLogger
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
        // Create mock register and schema.
        $mockRegister = $this->createMock(Register::class);
        $mockRegister->method('getId')->willReturn($registerId);
        
        $mockSchema = $this->createMock(Schema::class);
        $mockSchema->method('getId')->willReturn($schemaId);

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
        // Mock schema manager to return true on first call.
        $mockSchemaManager = $this->createMock(AbstractSchemaManager::class);
        $mockSchemaManager->expects($this->once())
                          ->method('tablesExist')
                          ->with(['oc_openregister_table_1_1'])
                          ->willReturn(true);

        $this->mockDb->expects($this->once())
                     ->method('getSchemaManager')
                     ->willReturn($mockSchemaManager);

        // First call should hit database.
        $result1 = $this->magicMapper->existsTableForRegisterSchema($this->mockRegister, $this->mockSchema);
        $this->assertTrue($result1);

        // Second call should use cache (no additional database call expected).
        $result2 = $this->magicMapper->existsTableForRegisterSchema($this->mockRegister, $this->mockSchema);
        $this->assertTrue($result2);

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

        $this->mockConfig->expects($this->any())
                         ->method('getAppValue')
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
     * Test table existence checking with caching
     *
     * @return void
     */
    public function testTableExistenceCheckingWithCaching(): void
    {
        $tableName = 'oc_openregister_table_test';
        
        // Mock schema manager.
        $mockSchemaManager = $this->createMock(AbstractSchemaManager::class);
        $mockSchemaManager->expects($this->once())
                          ->method('tablesExist')
                          ->with([$tableName])
                          ->willReturn(true);

        $this->mockDb->expects($this->once())
                     ->method('getSchemaManager')
                     ->willReturn($mockSchemaManager);

        // First call should hit database.
        $reflection = new \ReflectionClass($this->magicMapper);
        $method = $reflection->getMethod('tableExists');
        $method->setAccessible(true);
        
        $result1 = $method->invoke($this->magicMapper, $tableName);
        $this->assertTrue($result1);

        // Second call should use cache (no additional database call expected).
        $result2 = $method->invoke($this->magicMapper, $tableName);
        $this->assertTrue($result2);

    }//end testTableExistenceCheckingWithCaching()


    /**
     * Test schema version calculation for change detection
     *
     * @return void
     */
    public function testSchemaVersionCalculation(): void
    {
        $mockSchema = $this->createMock(Schema::class);
        $mockSchema->expects($this->once())
                   ->method('getProperties')
                   ->willReturn(['name' => ['type' => 'string'], 'age' => ['type' => 'integer']]);
        $mockSchema->expects($this->once())
                   ->method('getRequired')
                   ->willReturn(['name']);
        $mockSchema->expects($this->once())
                   ->method('getTitle')
                   ->willReturn('Test Schema');
        $mockSchema->expects($this->once())
                   ->method('getVersion')
                   ->willReturn('1.0');

        $reflection = new \ReflectionClass($this->magicMapper);
        $method = $reflection->getMethod('calculateSchemaVersion');
        $method->setAccessible(true);
        
        $version = $method->invoke($this->magicMapper, $mockSchema);

        $this->assertIsString($version);
        $this->assertEquals(32, strlen($version)); // MD5 hash length

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
        $reflection = new \ReflectionClass($this->magicMapper);
        $method = $reflection->getMethod('sanitizeTableName');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->magicMapper, $input);

        $this->assertEquals($expected, $result);

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
        $tableExistsCache->setValue(['test_table' => time()]);
        
        $schemaTableCache = $reflection->getProperty('schemaTableCache');
        $schemaTableCache->setAccessible(true);
        $schemaTableCache->setValue([1 => 'test_table']);

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
     * Test getting existing schema tables
     *
     * @return void
     */
    public function testGetExistingSchemaTables(): void
    {
        $allTables = [
            'oc_openregister_table_users',
            'oc_openregister_table_products',
            'oc_other_table',
            'oc_openregister_objects', // The regular objects table
            'oc_openregister_table_orders'
        ];

        $expectedSchemaTables = [
            'oc_openregister_table_users',
            'oc_openregister_table_products',
            'oc_openregister_table_orders'
        ];

        $mockSchemaManager = $this->createMock(AbstractSchemaManager::class);
        $mockSchemaManager->expects($this->once())
                          ->method('listTableNames')
                          ->willReturn($allTables);

        $this->mockDb->expects($this->once())
                     ->method('getSchemaManager')
                     ->willReturn($mockSchemaManager);

        $result = $this->magicMapper->getExistingRegisterSchemaTables();
        
        // Should parse table names and extract register+schema IDs.
        $this->assertIsArray($result);
        $this->assertCount(3, $result); // Should find 3 matching tables
        
        // Check structure of returned data.
        foreach ($result as $tableInfo) {
            $this->assertArrayHasKey('registerId', $tableInfo);
            $this->assertArrayHasKey('schemaId', $tableInfo);
            $this->assertArrayHasKey('tableName', $tableInfo);
        }

    }//end testGetExistingSchemaTables()


    /**
     * Test table creation workflow
     *
     * @return void
     */
    public function testTableCreationWorkflow(): void
    {
        $mockSchema = $this->createMock(Schema::class);
        $mockSchema->expects($this->any())
                   ->method('getId')
                   ->willReturn(1);
        $mockSchema->expects($this->any())
                   ->method('getSlug')
                   ->willReturn('test_schema');
        $mockSchema->expects($this->any())
                   ->method('getTitle')
                   ->willReturn('Test Schema');
        $mockSchema->expects($this->any())
                   ->method('getProperties')
                   ->willReturn([
                       'name' => ['type' => 'string', 'maxLength' => 255],
                       'age' => ['type' => 'integer']
                   ]);
        $mockSchema->expects($this->any())
                   ->method('getRequired')
                   ->willReturn(['name']);
        $mockSchema->expects($this->any())
                   ->method('getVersion')
                   ->willReturn('1.0');

        // Mock database schema operations.
        $mockDoctrineSchema = $this->createMock(DoctrineSchema::class);
        $mockTable = $this->createMock(Table::class);
        
        $mockDoctrineSchema->expects($this->once())
                           ->method('createTable')
                           ->with('oc_openregister_table_test_schema')
                           ->willReturn($mockTable);

        $this->mockDb->expects($this->once())
                     ->method('createSchema')
                     ->willReturn($mockDoctrineSchema);

        $this->mockDb->expects($this->once())
                     ->method('migrateToSchema')
                     ->with($mockDoctrineSchema);

        // Mock schema manager for table existence check.
        $mockSchemaManager = $this->createMock(AbstractSchemaManager::class);
        $mockSchemaManager->expects($this->once())
                          ->method('tablesExist')
                          ->willReturn(false); // Table doesn't exist

        $this->mockDb->expects($this->once())
                     ->method('getSchemaManager')
                     ->willReturn($mockSchemaManager);

        // Mock config for schema version storage.
        $this->mockConfig->expects($this->once())
                         ->method('setAppValue')
                         ->with('openregister', 'schema_version_1', $this->anything());

        // Test table creation.
        $result = $this->magicMapper->ensureTableForRegisterSchema($this->mockRegister, $mockSchema);

        $this->assertTrue($result);

    }//end testTableCreationWorkflow()


    /**
     * Test error handling when table creation fails
     *
     * @return void
     */
    public function testTableCreationErrorHandling(): void
    {
        $mockSchema = $this->createMock(Schema::class);
        $mockSchema->expects($this->any())
                   ->method('getId')
                   ->willReturn(1);
        $mockSchema->expects($this->any())
                   ->method('getSlug')
                   ->willReturn('test_schema');
        $mockSchema->expects($this->any())
                   ->method('getTitle')
                   ->willReturn('Test Schema');

        // Mock database to throw exception.
        $this->mockDb->expects($this->once())
                     ->method('createSchema')
                     ->willThrowException(new \Exception('Database error'));

        // Mock schema manager for table existence check.
        $mockSchemaManager = $this->createMock(AbstractSchemaManager::class);
        $mockSchemaManager->expects($this->once())
                          ->method('tablesExist')
                          ->willReturn(false);

        $this->mockDb->expects($this->once())
                     ->method('getSchemaManager')
                     ->willReturn($mockSchemaManager);

        // Test that exception is properly wrapped and rethrown.
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Failed to create\/update table for schema/');

        $this->magicMapper->ensureTableForRegisterSchema($this->mockRegister, $mockSchema);

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
