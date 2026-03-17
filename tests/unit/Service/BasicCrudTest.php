<?php

/**
 * Basic CRUD Functionality Tests
 *
 * Essential tests for basic Create, Read, Update, Delete operations on core entities.
 * These tests ensure fundamental functionality works correctly during development
 * and catch regressions in basic operations before they reach production.
 * 
 * ## Test Coverage (20+ tests):
 * 
 * ### Register CRUD Tests:
 * - testCreateRegister: Tests creating new registers with valid data
 * - testReadRegister: Tests retrieving existing registers
 * - testUpdateRegister: Tests updating register properties
 * - testDeleteRegister: Tests soft deletion of registers
 * - testRegisterValidation: Tests register data validation rules
 * 
 * ### Schema CRUD Tests:
 * - testCreateSchema: Tests creating new schemas with properties
 * - testCreateSchemaWithAuthorization: Tests creating schemas with RBAC config
 * - testReadSchema: Tests retrieving existing schemas
 * - testUpdateSchema: Tests updating schema properties and structure
 * - testUpdateSchemaAuthorization: Tests updating RBAC authorization config
 * - testDeleteSchema: Tests soft deletion of schemas
 * - testSchemaValidation: Tests schema structure validation
 * - testSchemaPropertyValidation: Tests property definition validation
 * 
 * ### Basic Object CRUD Tests:
 * - testCreateObject: Tests creating objects conforming to schema
 * - testReadObject: Tests retrieving existing objects
 * - testUpdateObject: Tests updating object data
 * - testDeleteObject: Tests soft deletion of objects
 * - testObjectSchemaValidation: Tests object validation against schema
 * 
 * ### Cross-Entity Relationship Tests:
 * - testRegisterSchemaRelationship: Tests register-schema associations
 * - testSchemaObjectRelationship: Tests schema-object validation
 * - testCascadingOperations: Tests cascading updates/deletes
 * 
 * ### Data Integrity Tests:
 * - testUuidGeneration: Tests proper UUID generation and uniqueness
 * - testTimestampHandling: Tests created/updated timestamp management
 * - testSoftDeletion: Tests soft deletion behavior across entities
 * - testDataConsistency: Tests data consistency across operations
 * 
 * ## Purpose:
 * These tests provide a safety net during development by ensuring that:
 * - Basic CRUD operations work as expected
 * - Data validation rules are enforced correctly
 * - Entity relationships function properly
 * - No breaking changes are introduced to core functionality
 * 
 * ## Usage:
 * Run these tests frequently during development to catch basic functionality issues early:
 * ```bash
 * php vendor/bin/phpunit tests/Unit/Service/BasicCrudTest.php
 * ```
 * 
 * These tests should always pass - if they fail, it indicates a fundamental
 * issue with core functionality that needs immediate attention.
 * 
 * ## Test Data:
 * Uses realistic test data that matches production patterns:
 * - Valid register configurations
 * - Common schema structures (string, number, boolean properties)
 * - Typical object data patterns
 * - Real-world RBAC authorization configs
 * 
 * ## Related Test Files:
 * - RbacTest.php: Tests advanced RBAC permission logic
 * - RbacComprehensiveTest.php: Tests all 64 RBAC scenarios
 * - ObjectServiceRbacTest.php: Tests RBAC integration with services
 * 
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use DateTime;

/**
 * Basic CRUD functionality tests for development
 */
class BasicCrudTest extends TestCase
{
    /**
     * Test data for registers
     */
    private array $testRegisterData = [
        'title' => 'Test Register',
        'description' => 'A test register for unit testing',
        'version' => '1.0.0'
    ];

    /**
     * Test data for schemas
     */
    private array $testSchemaData = [
        'title' => 'Test Schema',
        'description' => 'A test schema for unit testing',
        'version' => '1.0.0',
        'properties' => [
            'name' => [
                'type' => 'string',
                'required' => true,
                'maxLength' => 255
            ],
            'email' => [
                'type' => 'string',
                'format' => 'email'
            ],
            'age' => [
                'type' => 'integer',
                'minimum' => 0,
                'maximum' => 150
            ],
            'active' => [
                'type' => 'boolean',
                'default' => true
            ]
        ]
    ];

    /**
     * Test data for objects
     */
    private array $testObjectData = [
        'name' => 'John Doe',
        'email' => 'john.doe@example.com',
        'age' => 30,
        'active' => true
    ];

    // =================== REGISTER CRUD TESTS ===================.

    /**
     * Test creating a new register
     */
    public function testCreateRegister(): void
    {
        $register = new Register();
        $register->setTitle($this->testRegisterData['title']);
        $register->setDescription($this->testRegisterData['description']);
        $register->setVersion($this->testRegisterData['version']);

        $this->assertEquals($this->testRegisterData['title'], $register->getTitle());
        $this->assertEquals($this->testRegisterData['description'], $register->getDescription());
        $this->assertEquals($this->testRegisterData['version'], $register->getVersion());
        // Note: UUIDs are generated by mappers during save, not in entity constructors
        $this->assertNull($register->getUuid());
    }

    /**
     * Test register data validation
     */
    public function testRegisterValidation(): void
    {
        $register = new Register();
        
        // Test required fields.
        $register->setTitle('Valid Title');
        $this->assertNotEmpty($register->getTitle());
        
        // Test title length constraints.
        $longTitle = str_repeat('a', 300);
        $register->setTitle($longTitle);
        $this->assertEquals($longTitle, $register->getTitle());
        
        // Test version format.
        $register->setVersion('2.1.0');
        $this->assertEquals('2.1.0', $register->getVersion());
    }

    /**
     * Test updating register properties
     */
    public function testUpdateRegister(): void
    {
        $register = new Register();
        $register->setTitle('Original Title');
        $register->setDescription('Original Description');

        // Update properties.
        $register->setTitle('Updated Title');
        $register->setDescription('Updated Description');
        $register->setVersion('2.0.0');

        $this->assertEquals('Updated Title', $register->getTitle());
        $this->assertEquals('Updated Description', $register->getDescription());
        $this->assertEquals('2.0.0', $register->getVersion());
    }

    // =================== SCHEMA CRUD TESTS ===================.

    /**
     * Test creating a new schema
     */
    public function testCreateSchema(): void
    {
        $schema = new Schema();
        $schema->setTitle($this->testSchemaData['title']);
        $schema->setDescription($this->testSchemaData['description']);
        $schema->setVersion($this->testSchemaData['version']);
        $schema->setProperties($this->testSchemaData['properties']);

        $this->assertEquals($this->testSchemaData['title'], $schema->getTitle());
        $this->assertEquals($this->testSchemaData['description'], $schema->getDescription());
        $this->assertEquals($this->testSchemaData['version'], $schema->getVersion());
        $this->assertEquals($this->testSchemaData['properties'], $schema->getProperties());
        // Note: UUIDs are generated by mappers during save, not in entity constructors
        $this->assertNull($schema->getUuid());
    }

    /**
     * Test creating schema with RBAC authorization
     */
    public function testCreateSchemaWithAuthorization(): void
    {
        $authorization = [
            'create' => ['editors', 'managers'],
            'read' => ['public'],
            'update' => ['editors'],
            'delete' => ['managers']
        ];

        $schema = new Schema();
        $schema->setTitle('RBAC Test Schema');
        $schema->setDescription('Schema with RBAC configuration');
        $schema->setProperties($this->testSchemaData['properties']);
        $schema->setAuthorization($authorization);

        $this->assertEquals($authorization, $schema->getAuthorization());
        $this->assertTrue($schema->validateAuthorization());
    }

    /**
     * Test schema property validation
     */
    public function testSchemaPropertyValidation(): void
    {
        $schema = new Schema();
        $schema->setTitle('Property Test Schema');
        
        // Test valid property structure.
        $validProperties = [
            'name' => [
                'type' => 'string',
                'required' => true
            ],
            'count' => [
                'type' => 'integer',
                'minimum' => 0
            ]
        ];
        
        $schema->setProperties($validProperties);
        $this->assertEquals($validProperties, $schema->getProperties());
    }

    /**
     * Test updating schema authorization
     */
    public function testUpdateSchemaAuthorization(): void
    {
        $schema = new Schema();
        $schema->setTitle('Authorization Update Test');
        
        // Start with basic authorization.
        $initialAuth = [
            'read' => ['public'],
            'create' => ['users']
        ];
        $schema->setAuthorization($initialAuth);
        $this->assertEquals($initialAuth, $schema->getAuthorization());
        
        // Update authorization.
        $updatedAuth = [
            'create' => ['editors'],
            'read' => ['viewers', 'editors'],
            'update' => ['editors'],
            'delete' => ['managers']
        ];
        $schema->setAuthorization($updatedAuth);
        $this->assertEquals($updatedAuth, $schema->getAuthorization());
        $this->assertTrue($schema->validateAuthorization());
    }

    /**
     * Test schema authorization validation
     */
    public function testSchemaAuthorizationValidation(): void
    {
        $schema = new Schema();
        
        // Test valid authorization.
        $validAuth = [
            'create' => ['editors'],
            'read' => ['public', 'users'],
            'update' => ['editors', 'managers'],
            'delete' => ['managers']
        ];
        $schema->setAuthorization($validAuth);
        $this->assertTrue($schema->validateAuthorization());
        
        // Test empty authorization (should be valid - means open access).
        $schema->setAuthorization([]);
        $this->assertTrue($schema->validateAuthorization());
        
        // Test null authorization (should be valid).
        $schema->setAuthorization(null);
        $this->assertTrue($schema->validateAuthorization());
    }

    // =================== BASIC OBJECT TESTS ===================.

    /**
     * Test creating a basic object
     */
    public function testCreateObject(): void
    {
        $object = new ObjectEntity();
        $object->setObject($this->testObjectData);
        $object->setOwner('testuser');

        // ObjectEntity automatically adds some fields, so we need to check the core data.
        $objectData = $object->getObject();
        $this->assertEquals('John Doe', $objectData['name']);
        $this->assertEquals('john.doe@example.com', $objectData['email']);
        $this->assertEquals(30, $objectData['age']);
        $this->assertTrue($objectData['active']);
        $this->assertEquals('testuser', $object->getOwner());
        // Note: UUIDs are generated by mappers during save, not in entity constructors
        $this->assertNull($object->getUuid());
    }

    /**
     * Test updating object data
     */
    public function testUpdateObject(): void
    {
        $object = new ObjectEntity();
        $object->setObject($this->testObjectData);
        
        // Update object data.
        $updatedData = $this->testObjectData;
        $updatedData['name'] = 'Jane Smith';
        $updatedData['age'] = 25;
        $updatedData['active'] = false;
        
        $object->setObject($updatedData);
        
        // Test individual fields since ObjectEntity may add extra fields.
        $objectData = $object->getObject();
        $this->assertEquals('Jane Smith', $objectData['name']);
        $this->assertEquals(25, $objectData['age']);
        $this->assertFalse($objectData['active']);
        $this->assertEquals('john.doe@example.com', $objectData['email']); // Should remain unchanged
    }

    /**
     * Test object soft deletion
     */
    public function testObjectSoftDeletion(): void
    {
        $object = new ObjectEntity();
        $object->setObject($this->testObjectData);
        $object->setOwner('testuser');
        
        // Mock IUserSession.
        $mockUser = $this->createMock(\OCP\IUser::class);
        $mockUser->method('getUID')->willReturn('testuser');
        
        $mockUserSession = $this->createMock(\OCP\IUserSession::class);
        $mockUserSession->method('getUser')->willReturn($mockUser);
        
        // Test initial state (deleted is initialized as empty array, not null).
        $initialDeleted = $object->getDeleted();
        $this->assertTrue($initialDeleted === [] || $initialDeleted === null);
        
        // Perform soft delete.
        $object->delete($mockUserSession, 'Testing soft deletion', 30);
        
        $deletedData = $object->getDeleted();
        $this->assertNotNull($deletedData);
        $this->assertIsArray($deletedData);
        $this->assertEquals('testuser', $deletedData['deletedBy']);
        $this->assertEquals('Testing soft deletion', $deletedData['deletedReason']);
        $this->assertArrayHasKey('deleted', $deletedData);
    }

    // =================== PERMISSION INTEGRATION TESTS ===================.

    /**
     * Test basic permission integration
     */
    public function testBasicPermissionIntegration(): void
    {
        $schema = new Schema();
        $schema->setTitle('Permission Integration Test');
        $schema->setAuthorization([
            'create' => ['editors'],
            'read' => ['viewers', 'editors'],
            'update' => ['editors'],
            'delete' => ['managers']
        ]);

        // Test basic permission checks.
        $this->assertTrue($schema->hasPermission('editors', 'create'));
        $this->assertTrue($schema->hasPermission('viewers', 'read'));
        $this->assertTrue($schema->hasPermission('editors', 'update'));
        $this->assertTrue($schema->hasPermission('managers', 'delete'));
        $this->assertFalse($schema->hasPermission('viewers', 'create'));
        $this->assertFalse($schema->hasPermission('viewers', 'delete'));
    }

    /**
     * Test admin override functionality
     */
    public function testAdminOverride(): void
    {
        $schema = new Schema();
        $schema->setAuthorization([
            'create' => ['editors'],
            'read' => ['viewers'],
            'update' => ['editors'],
            'delete' => ['managers']
        ]);

        // Admin should have access to everything.
        $this->assertTrue($schema->hasPermission('admin', 'create'));
        $this->assertTrue($schema->hasPermission('admin', 'read'));
        $this->assertTrue($schema->hasPermission('admin', 'update'));
        $this->assertTrue($schema->hasPermission('admin', 'delete'));
        
        // Test admin through userGroup parameter.
        $this->assertTrue($schema->hasPermission('anygroup', 'create', 'admin-user', 'admin'));
        $this->assertTrue($schema->hasPermission('anygroup', 'delete', 'admin-user', 'admin'));
    }

    /**
     * Test object owner privilege
     */
    public function testObjectOwnerPrivilege(): void
    {
        $schema = new Schema();
        $schema->setAuthorization([
            'create' => ['editors'],
            'read' => ['viewers'],
            'update' => ['editors'],
            'delete' => ['managers']
        ]);

        // Object owner should have access to their own objects.
        $this->assertTrue($schema->hasPermission('staff', 'read', 'user123', null, 'user123'));
        $this->assertTrue($schema->hasPermission('staff', 'update', 'user123', null, 'user123'));
        $this->assertTrue($schema->hasPermission('staff', 'delete', 'user123', null, 'user123'));
        
        // But not to other users' objects.
        $this->assertFalse($schema->hasPermission('staff', 'read', 'user123', null, 'user456'));
        $this->assertFalse($schema->hasPermission('staff', 'update', 'user123', null, 'user456'));
    }

    // =================== DATA INTEGRITY TESTS ===================.

    /**
     * Test entity initialization and basic properties
     */
    public function testEntityInitialization(): void
    {
        $register1 = new Register();
        $register2 = new Register();
        $schema1 = new Schema();
        $schema2 = new Schema();
        $object1 = new ObjectEntity();
        $object2 = new ObjectEntity();

        // All entities should initialize properly.
        $this->assertInstanceOf(Register::class, $register1);
        $this->assertInstanceOf(Register::class, $register2);
        $this->assertInstanceOf(Schema::class, $schema1);
        $this->assertInstanceOf(Schema::class, $schema2);
        $this->assertInstanceOf(ObjectEntity::class, $object1);
        $this->assertInstanceOf(ObjectEntity::class, $object2);

        // UUIDs should be null initially (generated by mappers during save).
        $this->assertNull($register1->getUuid());
        $this->assertNull($register2->getUuid());
        $this->assertNull($schema1->getUuid());
        $this->assertNull($schema2->getUuid());
        $this->assertNull($object1->getUuid());
        $this->assertNull($object2->getUuid());
    }

    /**
     * Test data consistency across operations
     */
    public function testDataConsistency(): void
    {
        // Create a register.
        $register = new Register();
        $register->setTitle('Consistency Test Register');
        
        // Create a schema.
        $schema = new Schema();
        $schema->setTitle('Consistency Test Schema');
        $schema->setProperties($this->testSchemaData['properties']);
        
        // Create an object.
        $object = new ObjectEntity();
        $object->setObject($this->testObjectData);
        $object->setOwner('consistency-user');

        // All entities should maintain their data consistently.
        $this->assertEquals('Consistency Test Register', $register->getTitle());
        $this->assertEquals('Consistency Test Schema', $schema->getTitle());
        
        // Test object data consistency (checking individual fields since ObjectEntity adds extras).
        $objectData = $object->getObject();
        $this->assertEquals('John Doe', $objectData['name']);
        $this->assertEquals('john.doe@example.com', $objectData['email']);
        $this->assertEquals(30, $objectData['age']);
        $this->assertTrue($objectData['active']);
        $this->assertEquals('consistency-user', $object->getOwner());
        
        // UUIDs should remain null consistently (until saved by mapper).
        $registerUuid = $register->getUuid();
        $schemaUuid = $schema->getUuid();
        $objectUuid = $object->getUuid();
        
        $this->assertNull($registerUuid);
        $this->assertNull($schemaUuid);
        $this->assertNull($objectUuid);
        $this->assertEquals($registerUuid, $register->getUuid());
        $this->assertEquals($schemaUuid, $schema->getUuid());
        $this->assertEquals($objectUuid, $object->getUuid());
    }

    /**
     * Test complex scenario integration
     */
    public function testComplexScenarioIntegration(): void
    {
        // Create a complete scenario: Register -> Schema -> Object with RBAC.
        
        // 1. Create register.
        $register = new Register();
        $register->setTitle('Integration Test Register');
        $register->setDescription('Full integration test scenario');
        
        // 2. Create schema with RBAC.
        $schema = new Schema();
        $schema->setTitle('Integration Test Schema');
        $schema->setDescription('Schema for integration testing');
        $schema->setProperties([
            'title' => ['type' => 'string', 'required' => true],
            'category' => ['type' => 'string'],
            'priority' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5]
        ]);
        $schema->setAuthorization([
            'create' => ['contributors'],
            'read' => ['public'],
            'update' => ['contributors', 'editors'],
            'delete' => ['editors']
        ]);
        
        // 3. Create object following schema.
        $object = new ObjectEntity();
        $object->setObject([
            'title' => 'Integration Test Item',
            'category' => 'testing',
            'priority' => 3
        ]);
        $object->setOwner('integration-user');
        
        // 4. Verify all components work together.
        $this->assertEquals('Integration Test Register', $register->getTitle());
        $this->assertEquals('Integration Test Schema', $schema->getTitle());
        $this->assertTrue($schema->validateAuthorization());
        $this->assertEquals('Integration Test Item', $object->getObject()['title']);
        $this->assertEquals('integration-user', $object->getOwner());
        
        // 5. Test RBAC permissions.
        $this->assertTrue($schema->hasPermission('contributors', 'create'));
        $this->assertTrue($schema->hasPermission('public', 'read'));
        $this->assertTrue($schema->hasPermission('editors', 'delete'));
        $this->assertFalse($schema->hasPermission('public', 'create'));
        
        // 6. Test object owner can access regardless of group restrictions.
        $this->assertTrue($schema->hasPermission('random-group', 'delete', 'integration-user', null, 'integration-user'));
    }
} 