<?php

declare(strict_types=1);

/**
 * ConfigurationMapperTest
 *
 * Basic unit tests for the ConfigurationMapper class to verify configuration
 * database operations and CRUD functionality.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 OpenRegister
 * @license   AGPL-3.0
 * @version   1.0.0
 * @link      https://github.com/OpenRegister/openregister
 */

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Configuration Mapper Test Suite
 *
 * Basic unit tests for configuration database operations focusing on
 * class structure and basic functionality.
 *
 * @coversDefaultClass ConfigurationMapper
 */
class ConfigurationMapperTest extends TestCase
{
    private ConfigurationMapper $configurationMapper;
    private IDBConnection|MockObject $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->createMock(IDBConnection::class);
        $this->configurationMapper = new ConfigurationMapper($this->db);
    }

    /**
     * Test constructor
     *
     * @covers ::__construct
     * @return void
     */
    public function testConstructor(): void
    {
        $this->assertInstanceOf(ConfigurationMapper::class, $this->configurationMapper);
    }

    /**
     * Test Configuration entity creation
     *
     * @return void
     */
    public function testConfigurationEntityCreation(): void
    {
        $configuration = new Configuration();
        $configuration->setId(1);
        $configuration->setType('test');
        $configuration->setApp('openregister');
        $configuration->setTitle('Test Configuration');
        $configuration->setDescription('Test Description');
        $configuration->setVersion('1.0.0');
        $configuration->setRegisters([1, 2, 3]);
        $configuration->setSchemas([4, 5, 6]);
        $configuration->setObjects([7, 8, 9]);

        $this->assertEquals(1, $configuration->getId());
        $this->assertEquals('test', $configuration->getType());
        $this->assertEquals('openregister', $configuration->getApp());
        $this->assertEquals('Test Configuration', $configuration->getTitle());
        $this->assertEquals('Test Description', $configuration->getDescription());
        $this->assertEquals('1.0.0', $configuration->getVersion());
        $this->assertEquals([1, 2, 3], $configuration->getRegisters());
        $this->assertEquals([4, 5, 6], $configuration->getSchemas());
        $this->assertEquals([7, 8, 9], $configuration->getObjects());
    }

    /**
     * Test Configuration entity JSON serialization
     *
     * @return void
     */
    public function testConfigurationJsonSerialization(): void
    {
        $configuration = new Configuration();
        $configuration->setId(1);
        $configuration->setType('test');
        $configuration->setApp('openregister');
        $configuration->setTitle('Test Configuration');
        $configuration->setDescription('Test Description');
        $configuration->setVersion('1.0.0');
        $configuration->setRegisters([1, 2, 3]);
        $configuration->setSchemas([4, 5, 6]);
        $configuration->setObjects([7, 8, 9]);

        $json = $configuration->jsonSerialize();

        $this->assertIsArray($json);
        $this->assertArrayHasKey('id', $json);
        $this->assertArrayHasKey('type', $json);
        $this->assertArrayHasKey('app', $json);
        $this->assertArrayHasKey('title', $json);
        $this->assertArrayHasKey('description', $json);
        $this->assertArrayHasKey('version', $json);
        $this->assertArrayHasKey('registers', $json);
        $this->assertArrayHasKey('schemas', $json);
        $this->assertArrayHasKey('objects', $json);
        $this->assertArrayHasKey('owner', $json); // Backwards compatibility field
    }

    /**
     * Test Configuration entity string representation
     *
     * @return void
     */
    public function testConfigurationToString(): void
    {
        $configuration = new Configuration();
        $configuration->setTitle('Test Configuration');
        
        $this->assertEquals('Test Configuration', (string) $configuration);
    }

    /**
     * Test Configuration entity string representation with type fallback
     *
     * @return void
     */
    public function testConfigurationToStringWithTypeFallback(): void
    {
        $configuration = new Configuration();
        $configuration->setType('test');
        
        $this->assertEquals('Config: test', (string) $configuration);
    }

    /**
     * Test Configuration entity string representation with ID fallback
     *
     * @return void
     */
    public function testConfigurationToStringWithIdFallback(): void
    {
        $configuration = new Configuration();
        $configuration->setId(123);
        
        $this->assertEquals('Configuration #123', (string) $configuration);
    }

    /**
     * Test Configuration entity string representation with default fallback
     *
     * @return void
     */
    public function testConfigurationToStringWithDefaultFallback(): void
    {
        $configuration = new Configuration();
        
        $this->assertEquals('Configuration', (string) $configuration);
    }

    /**
     * Test Configuration entity getJsonFields method
     *
     * @return void
     */
    public function testConfigurationGetJsonFields(): void
    {
        $configuration = new Configuration();
        $jsonFields = $configuration->getJsonFields();

        $this->assertIsArray($jsonFields);
        $this->assertContains('registers', $jsonFields);
        $this->assertContains('schemas', $jsonFields);
        $this->assertContains('objects', $jsonFields);
    }

    /**
     * Test Configuration entity hydrate method
     *
     * @return void
     */
    public function testConfigurationHydrate(): void
    {
        $configuration = new Configuration();
        $data = [
            'id' => 1,
            'type' => 'test',
            'app' => 'openregister',
            'title' => 'Test Configuration',
            'description' => 'Test Description',
            'version' => '1.0.0',
            'registers' => [1, 2, 3],
            'schemas' => [4, 5, 6],
            'objects' => [7, 8, 9]
        ];

        $result = $configuration->hydrate($data);

        $this->assertInstanceOf(Configuration::class, $result);
        $this->assertEquals(1, $configuration->getId());
        $this->assertEquals('test', $configuration->getType());
        $this->assertEquals('openregister', $configuration->getApp());
        $this->assertEquals('Test Configuration', $configuration->getTitle());
        $this->assertEquals('Test Description', $configuration->getDescription());
        $this->assertEquals('1.0.0', $configuration->getVersion());
        $this->assertEquals([1, 2, 3], $configuration->getRegisters());
        $this->assertEquals([4, 5, 6], $configuration->getSchemas());
        $this->assertEquals([7, 8, 9], $configuration->getObjects());
    }

    /**
     * Test Configuration entity backwards compatibility methods
     *
     * @return void
     */
    public function testConfigurationBackwardsCompatibility(): void
    {
        $configuration = new Configuration();
        $configuration->setApp('openregister');

        // Test getOwner method (backwards compatibility)
        $this->assertEquals('openregister', $configuration->getOwner());

        // Test setOwner method (backwards compatibility)
        $configuration->setOwner('testapp');
        $this->assertEquals('testapp', $configuration->getApp());
        $this->assertEquals('testapp', $configuration->getOwner());
    }

    /**
     * Test Configuration entity with null values
     *
     * @return void
     */
    public function testConfigurationWithNullValues(): void
    {
        $configuration = new Configuration();
        $configuration->setRegisters(null);
        $configuration->setSchemas(null);
        $configuration->setObjects(null);

        $this->assertEquals([], $configuration->getRegisters());
        $this->assertEquals([], $configuration->getSchemas());
        $this->assertEquals([], $configuration->getObjects());
    }

    /**
     * Test Configuration entity with empty arrays
     *
     * @return void
     */
    public function testConfigurationWithEmptyArrays(): void
    {
        $configuration = new Configuration();
        $configuration->setRegisters([]);
        $configuration->setSchemas([]);
        $configuration->setObjects([]);

        $this->assertEquals([], $configuration->getRegisters());
        $this->assertEquals([], $configuration->getSchemas());
        $this->assertEquals([], $configuration->getObjects());
    }

    /**
     * Test Configuration entity class inheritance
     *
     * @return void
     */
    public function testConfigurationClassInheritance(): void
    {
        $configuration = new Configuration();
        
        $this->assertInstanceOf(\OCP\AppFramework\Db\Entity::class, $configuration);
        $this->assertInstanceOf(\JsonSerializable::class, $configuration);
    }

    /**
     * Test Configuration entity field types
     *
     * @return void
     */
    public function testConfigurationFieldTypes(): void
    {
        $configuration = new Configuration();
        $fieldTypes = $configuration->getFieldTypes();

        $this->assertIsArray($fieldTypes);
        $this->assertArrayHasKey('id', $fieldTypes);
        $this->assertArrayHasKey('title', $fieldTypes);
        $this->assertArrayHasKey('description', $fieldTypes);
        $this->assertArrayHasKey('type', $fieldTypes);
        $this->assertArrayHasKey('app', $fieldTypes);
        $this->assertArrayHasKey('version', $fieldTypes);
        $this->assertArrayHasKey('registers', $fieldTypes);
        $this->assertArrayHasKey('schemas', $fieldTypes);
        $this->assertArrayHasKey('objects', $fieldTypes);
        $this->assertArrayHasKey('created', $fieldTypes);
        $this->assertArrayHasKey('updated', $fieldTypes);
    }
}