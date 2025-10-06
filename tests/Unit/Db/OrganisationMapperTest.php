<?php

declare(strict_types=1);

/**
 * OrganisationMapperTest
 *
 * Basic unit tests for the OrganisationMapper class to verify organisation
 * database operations and user management functionality.
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

use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Organisation Mapper Test Suite
 *
 * Basic unit tests for organisation database operations focusing on
 * class structure and basic functionality.
 *
 * @coversDefaultClass OrganisationMapper
 */
class OrganisationMapperTest extends TestCase
{
    private OrganisationMapper $organisationMapper;
    private IDBConnection|MockObject $db;
    private LoggerInterface|MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->createMock(IDBConnection::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        
        $this->organisationMapper = new OrganisationMapper(
            $this->db,
            $this->logger
        );
    }

    /**
     * Test constructor
     *
     * @covers ::__construct
     * @return void
     */
    public function testConstructor(): void
    {
        $this->assertInstanceOf(OrganisationMapper::class, $this->organisationMapper);
    }

    /**
     * Test Organisation entity creation
     *
     * @return void
     */
    public function testOrganisationEntityCreation(): void
    {
        $organisation = new Organisation();
        $organisation->setId(1);
        $organisation->setUuid('test-uuid-123');
        $organisation->setName('Test Organisation');
        $organisation->setDescription('Test Description');
        $organisation->setIsDefault(true);
        $organisation->setCreated(new \DateTime('2024-01-01 00:00:00'));
        $organisation->setUpdated(new \DateTime('2024-01-02 00:00:00'));

        $this->assertEquals(1, $organisation->getId());
        $this->assertEquals('test-uuid-123', $organisation->getUuid());
        $this->assertEquals('Test Organisation', $organisation->getName());
        $this->assertEquals('Test Description', $organisation->getDescription());
        $this->assertTrue($organisation->getIsDefault());
    }

    /**
     * Test Organisation entity JSON serialization
     *
     * @return void
     */
    public function testOrganisationJsonSerialization(): void
    {
        $organisation = new Organisation();
        $organisation->setId(1);
        $organisation->setUuid('test-uuid-123');
        $organisation->setName('Test Organisation');
        $organisation->setDescription('Test Description');
        $organisation->setIsDefault(true);

        $json = $organisation->jsonSerialize();

        $this->assertIsArray($json);
        $this->assertArrayHasKey('id', $json);
        $this->assertArrayHasKey('uuid', $json);
        $this->assertArrayHasKey('name', $json);
        $this->assertArrayHasKey('description', $json);
        $this->assertArrayHasKey('isDefault', $json);
        $this->assertArrayHasKey('created', $json);
        $this->assertArrayHasKey('updated', $json);
    }

    /**
     * Test Organisation entity string representation
     *
     * @return void
     */
    public function testOrganisationToString(): void
    {
        $organisation = new Organisation();
        $organisation->setUuid('test-uuid-123');
        
        $this->assertEquals('test-uuid-123', (string) $organisation);
    }

    /**
     * Test Organisation entity string representation with generated UUID
     *
     * @return void
     */
    public function testOrganisationToStringWithGeneratedUuid(): void
    {
        $organisation = new Organisation();
        
        $uuid = (string) $organisation;
        
        // Should be a valid UUID format
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid);
        
        // Should be the same when called again
        $this->assertEquals($uuid, (string) $organisation);
    }

    /**
     * Test Organisation entity with null values
     *
     * @return void
     */
    public function testOrganisationWithNullValues(): void
    {
        $organisation = new Organisation();
        $organisation->setUuid(null);
        $organisation->setName(null);
        $organisation->setDescription(null);
        $organisation->setIsDefault(null);

        $this->assertNull($organisation->getUuid());
        $this->assertNull($organisation->getName());
        $this->assertNull($organisation->getDescription());
        $this->assertFalse($organisation->getIsDefault()); // Defaults to false
    }

    /**
     * Test Organisation entity with boolean values
     *
     * @return void
     */
    public function testOrganisationWithBooleanValues(): void
    {
        $organisation = new Organisation();
        
        $organisation->setIsDefault(true);
        $this->assertTrue($organisation->getIsDefault());
        
        $organisation->setIsDefault(false);
        $this->assertFalse($organisation->getIsDefault());
    }

    /**
     * Test Organisation entity with DateTime values
     *
     * @return void
     */
    public function testOrganisationWithDateTimeValues(): void
    {
        $organisation = new Organisation();
        $created = new \DateTime('2024-01-01 12:00:00');
        $updated = new \DateTime('2024-01-02 15:30:00');
        
        $organisation->setCreated($created);
        $organisation->setUpdated($updated);

        $this->assertEquals($created, $organisation->getCreated());
        $this->assertEquals($updated, $organisation->getUpdated());
    }

    /**
     * Test Organisation entity class inheritance
     *
     * @return void
     */
    public function testOrganisationClassInheritance(): void
    {
        $organisation = new Organisation();
        
        $this->assertInstanceOf(\OCP\AppFramework\Db\Entity::class, $organisation);
        $this->assertInstanceOf(\JsonSerializable::class, $organisation);
    }

    /**
     * Test Organisation entity field types
     *
     * @return void
     */
    public function testOrganisationFieldTypes(): void
    {
        $organisation = new Organisation();
        $fieldTypes = $organisation->getFieldTypes();

        $this->assertIsArray($fieldTypes);
        $this->assertArrayHasKey('id', $fieldTypes);
        $this->assertArrayHasKey('uuid', $fieldTypes);
        $this->assertArrayHasKey('name', $fieldTypes);
        $this->assertArrayHasKey('description', $fieldTypes);
        $this->assertArrayHasKey('is_default', $fieldTypes);
        $this->assertArrayHasKey('created', $fieldTypes);
        $this->assertArrayHasKey('updated', $fieldTypes);
    }

    /**
     * Test Organisation entity user management
     *
     * @return void
     */
    public function testOrganisationUserManagement(): void
    {
        $organisation = new Organisation();
        
        // Test adding users
        $organisation->addUser('user1');
        $organisation->addUser('user2');
        $organisation->addUser('user1'); // Duplicate should not be added
        
        $userIds = $organisation->getUserIds();
        $this->assertCount(2, $userIds);
        $this->assertContains('user1', $userIds);
        $this->assertContains('user2', $userIds);
        
        // Test removing users
        $organisation->removeUser('user1');
        $userIds = $organisation->getUserIds();
        $this->assertCount(1, $userIds);
        $this->assertContains('user2', $userIds);
        $this->assertNotContains('user1', $userIds);
    }

    /**
     * Test Organisation entity UUID validation
     *
     * @return void
     */
    public function testOrganisationUuidValidation(): void
    {
        // Test valid UUID
        $this->assertTrue(Organisation::isValidUuid('550e8400-e29b-41d4-a716-446655440000'));
        
        // Test invalid UUID
        $this->assertFalse(Organisation::isValidUuid('invalid-uuid'));
        $this->assertFalse(Organisation::isValidUuid(''));
        $this->assertFalse(Organisation::isValidUuid('123'));
    }

    /**
     * Test Organisation entity with various string lengths
     *
     * @return void
     */
    public function testOrganisationWithVariousStringLengths(): void
    {
        $organisation = new Organisation();
        
        // Test with short strings
        $organisation->setName('A');
        $this->assertEquals('A', $organisation->getName());
        
        // Test with long strings
        $longName = str_repeat('A', 255);
        $organisation->setName($longName);
        $this->assertEquals($longName, $organisation->getName());
        
        // Test with empty strings
        $organisation->setName('');
        $this->assertEquals('', $organisation->getName());
    }

    /**
     * Test Organisation entity with special characters
     *
     * @return void
     */
    public function testOrganisationWithSpecialCharacters(): void
    {
        $organisation = new Organisation();
        
        $specialName = 'Test & Co. (Ltd.) - "Special" Characters: éñü';
        $organisation->setName($specialName);
        $this->assertEquals($specialName, $organisation->getName());
        
        $specialDescription = 'Description with <tags> and "quotes" and \'apostrophes\'';
        $organisation->setDescription($specialDescription);
        $this->assertEquals($specialDescription, $organisation->getDescription());
    }
}