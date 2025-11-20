<?php

/**
 * RBAC Core Logic Unit Tests
 *
 * Tests the fundamental RBAC permission logic in the Schema class.
 * These tests focus on the core permission checking mechanisms and validation.
 * 
 * ## Test Coverage (14 tests):
 * 
 * ### Core Permission Logic Tests:
 * - testSchemaHasPermissionOpenAccess: Tests that schemas with no authorization allow all operations
 * - testSchemaHasPermissionAdminOverride: Tests that admin users bypass all restrictions  
 * - testSchemaHasPermissionOwnerOverride: Tests that object owners have full access to their objects
 * - testSchemaHasPermissionGroupAuthorizationPositive: Tests authorized group access
 * - testSchemaHasPermissionGroupAuthorizationNegative: Tests unauthorized group blocking
 * - testSchemaHasPermissionMissingAction: Tests that unspecified actions default to open access
 * - testSchemaHasPermissionPublicAccess: Tests public group access patterns
 * - testSchemaHasPermissionEdgeCases: Tests empty inputs and boundary conditions
 * 
 * ### Utility Method Tests:
 * - testSchemaGetAuthorizedGroups: Tests retrieval of groups authorized for specific actions
 * 
 * ### Real-World Scenario Tests:
 * - testStaffOnlySchemaScenario: Tests complete staff-only access pattern
 * - testPublicReadSchemaScenario: Tests public read with restricted write pattern
 * - testComplexPermissionScenarios: Tests complex multi-condition scenarios
 * 
 * ### Validation Tests:
 * - testSchemaAuthorizationValidation: Tests validation of authorization structure
 * - testSchemaAuthorizationValidationEdgeCases: Tests validation error handling
 * 
 * ## Related Test Files:
 * - RbacComprehensiveTest.php: Data-driven tests covering all 64 RBAC scenarios systematically
 * - ObjectServiceRbacTest.php: Tests RBAC integration with ObjectService and Nextcloud APIs
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

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCP\IUserSession;
use OCP\IUser;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for RBAC functionality
 */
class RbacTest extends TestCase
{
    /** @var MockObject|IUserSession */
    private $userSession;

    /** @var MockObject|IGroupManager */
    private $groupManager;

    /** @var MockObject|IUserManager */
    private $userManager;

    /** @var MockObject|IUser */
    private $mockUser;

    /**
     * Set up test fixtures
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->userSession = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->mockUser = $this->createMock(IUser::class);
    }

    /**
     * Test Schema::hasPermission() - Open Access (No Authorization)
     * Should allow all users to perform all actions
     */
    public function testSchemaHasPermissionOpenAccess(): void
    {
        // Create schema with no authorization (open access).
        $schema = new Schema();
        $schema->setAuthorization([]);

        // Test all CRUD operations for different user types.
        $this->assertTrue($schema->hasPermission('viewers', 'read'));
        $this->assertTrue($schema->hasPermission('editors', 'create'));
        $this->assertTrue($schema->hasPermission('managers', 'delete'));
        $this->assertTrue($schema->hasPermission('public', 'read'));
        
        // Test with null authorization (also open access).
        $schema->setAuthorization(null);
        $this->assertTrue($schema->hasPermission('anyone', 'update'));
    }

    /**
     * Test Schema::hasPermission() - Admin Override
     * Admin group should always have full access regardless of authorization
     */
    public function testSchemaHasPermissionAdminOverride(): void
    {
        // Create restrictive schema (staff only).
        $schema = new Schema();
        $schema->setAuthorization([
            'create' => ['staff'],
            'read' => ['staff'],
            'update' => ['staff'],
            'delete' => ['staff']
        ]);

        // Admin group should bypass all restrictions.
        $this->assertTrue($schema->hasPermission('admin', 'create'));
        $this->assertTrue($schema->hasPermission('admin', 'read'));
        $this->assertTrue($schema->hasPermission('admin', 'update'));
        $this->assertTrue($schema->hasPermission('admin', 'delete'));

        // Admin userGroup parameter should also work.
        $this->assertTrue($schema->hasPermission('other', 'create', 'user123', 'admin'));
    }

    /**
     * Test Schema::hasPermission() - Owner Privilege Override  
     * Object owners should have full access to their specific objects
     */
    public function testSchemaHasPermissionOwnerOverride(): void
    {
        // Create restrictive schema.
        $schema = new Schema();
        $schema->setAuthorization([
            'create' => ['staff'],
            'read' => ['staff'], 
            'update' => ['staff'],
            'delete' => ['managers']
        ]);

        // User not in authorized groups should be blocked normally.
        $this->assertFalse($schema->hasPermission('editors', 'read', 'user123'));
        
        // Same user should have access when they own the object.
        $this->assertTrue($schema->hasPermission('editors', 'read', 'user123', null, 'user123'));
        $this->assertTrue($schema->hasPermission('editors', 'update', 'user123', null, 'user123'));
        $this->assertTrue($schema->hasPermission('editors', 'delete', 'user123', null, 'user123'));
        
        // Different owner should not grant access.
        $this->assertFalse($schema->hasPermission('editors', 'read', 'user123', null, 'user456'));
    }

    /**
     * Test Schema::hasPermission() - Group-Based Authorization (Positive Cases)
     */
    public function testSchemaHasPermissionGroupAuthorizationPositive(): void
    {
        // Create collaborative schema with specific permissions.
        $schema = new Schema();
        $schema->setAuthorization([
            'create' => ['editors', 'managers'],
            'read' => ['viewers', 'editors', 'managers'], 
            'update' => ['editors', 'managers'],
            'delete' => ['managers']
        ]);

        // Test authorized groups can perform allowed actions.
        $this->assertTrue($schema->hasPermission('viewers', 'read'));
        $this->assertTrue($schema->hasPermission('editors', 'read'));
        $this->assertTrue($schema->hasPermission('editors', 'create'));
        $this->assertTrue($schema->hasPermission('editors', 'update'));
        $this->assertTrue($schema->hasPermission('managers', 'read'));
        $this->assertTrue($schema->hasPermission('managers', 'create'));
        $this->assertTrue($schema->hasPermission('managers', 'update'));
        $this->assertTrue($schema->hasPermission('managers', 'delete'));
    }

    /**
     * Test Schema::hasPermission() - Group-Based Authorization (Negative Cases)
     */
    public function testSchemaHasPermissionGroupAuthorizationNegative(): void
    {
        // Create collaborative schema with specific permissions.
        $schema = new Schema();
        $schema->setAuthorization([
            'create' => ['editors', 'managers'],
            'read' => ['viewers', 'editors', 'managers'],
            'update' => ['editors', 'managers'], 
            'delete' => ['managers']
        ]);

        // Test unauthorized groups are blocked from restricted actions.
        $this->assertFalse($schema->hasPermission('viewers', 'create'));
        $this->assertFalse($schema->hasPermission('viewers', 'update'));
        $this->assertFalse($schema->hasPermission('viewers', 'delete'));
        $this->assertFalse($schema->hasPermission('editors', 'delete'));
        $this->assertFalse($schema->hasPermission('staff', 'read'));
        $this->assertFalse($schema->hasPermission('staff', 'create'));
        $this->assertFalse($schema->hasPermission('public', 'create'));
    }

    /**
     * Test Schema::hasPermission() - Missing Action (Open Access for Unspecified Actions)
     */
    public function testSchemaHasPermissionMissingAction(): void
    {
        // Create schema with partial authorization.
        $schema = new Schema();
        $schema->setAuthorization([
            'create' => ['editors'],
            'delete' => ['managers']
            // read and update not specified.
        ]);

        // Unspecified actions should be open to all.
        $this->assertTrue($schema->hasPermission('anyone', 'read'));
        $this->assertTrue($schema->hasPermission('viewers', 'update'));
        
        // Specified actions should respect restrictions.
        $this->assertTrue($schema->hasPermission('editors', 'create'));
        $this->assertFalse($schema->hasPermission('viewers', 'create'));
        $this->assertTrue($schema->hasPermission('managers', 'delete'));
        $this->assertFalse($schema->hasPermission('editors', 'delete'));
    }

    /**
     * Test Schema::hasPermission() - Public Access
     */
    public function testSchemaHasPermissionPublicAccess(): void
    {
        // Create schema with public read access.
        $schema = new Schema();
        $schema->setAuthorization([
            'create' => ['editors'],
            'read' => ['public'],
            'update' => ['editors'],
            'delete' => ['managers']
        ]);

        // Public should have read access.
        $this->assertTrue($schema->hasPermission('public', 'read'));
        
        // Public should not have other permissions.
        $this->assertFalse($schema->hasPermission('public', 'create'));
        $this->assertFalse($schema->hasPermission('public', 'update'));
        $this->assertFalse($schema->hasPermission('public', 'delete'));
    }

    /**
     * Test Schema::getAuthorizedGroups()
     */
    public function testSchemaGetAuthorizedGroups(): void
    {
        // Test open access schema.
        $schema = new Schema();
        $schema->setAuthorization([]);
        $this->assertEmpty($schema->getAuthorizedGroups('read'));
        
        // Test schema with specific permissions.
        $schema->setAuthorization([
            'create' => ['editors', 'managers'],
            'read' => ['viewers', 'editors', 'managers'],
            'update' => ['editors'],
            'delete' => ['managers']
        ]);

        $this->assertEquals(['editors', 'managers'], $schema->getAuthorizedGroups('create'));
        $this->assertEquals(['viewers', 'editors', 'managers'], $schema->getAuthorizedGroups('read'));
        $this->assertEquals(['editors'], $schema->getAuthorizedGroups('update'));
        $this->assertEquals(['managers'], $schema->getAuthorizedGroups('delete'));
        $this->assertEmpty($schema->getAuthorizedGroups('nonexistent'));
    }

    /**
     * Test real-world scenario: Staff Only Schema
     */
    public function testStaffOnlySchemaScenario(): void
    {
        $schema = new Schema();
        $schema->setAuthorization([
            'create' => ['staff'],
            'read' => ['staff'],
            'update' => ['staff'],
            'delete' => ['managers', 'staff']
        ]);

        // Staff should have full access except delete needs managers too.
        $this->assertTrue($schema->hasPermission('staff', 'create'));
        $this->assertTrue($schema->hasPermission('staff', 'read'));
        $this->assertTrue($schema->hasPermission('staff', 'update'));
        $this->assertTrue($schema->hasPermission('staff', 'delete'));
        
        // Managers should only have delete access.
        $this->assertFalse($schema->hasPermission('managers', 'create'));
        $this->assertFalse($schema->hasPermission('managers', 'read'));
        $this->assertFalse($schema->hasPermission('managers', 'update'));
        $this->assertTrue($schema->hasPermission('managers', 'delete'));
        
        // Other groups should have no access.
        $this->assertFalse($schema->hasPermission('editors', 'read'));
        $this->assertFalse($schema->hasPermission('viewers', 'read'));
        $this->assertFalse($schema->hasPermission('public', 'read'));
    }

    /**
     * Test real-world scenario: Public Read Schema
     */
    public function testPublicReadSchemaScenario(): void
    {
        $schema = new Schema();
        $schema->setAuthorization([
            'create' => ['editors', 'managers'],
            'read' => ['public', 'editors', 'managers'], // Allow editors and managers to read too
            'update' => ['editors', 'managers'], 
            'delete' => ['managers']
        ]);

        // Public should only have read access.
        $this->assertTrue($schema->hasPermission('public', 'read'));
        $this->assertFalse($schema->hasPermission('public', 'create'));
        $this->assertFalse($schema->hasPermission('public', 'update'));
        $this->assertFalse($schema->hasPermission('public', 'delete'));
        
        // Editors should have create, read, update.
        $this->assertTrue($schema->hasPermission('editors', 'create'));
        $this->assertTrue($schema->hasPermission('editors', 'read'));
        $this->assertTrue($schema->hasPermission('editors', 'update'));
        $this->assertFalse($schema->hasPermission('editors', 'delete'));
        
        // Managers should have full access.  
        $this->assertTrue($schema->hasPermission('managers', 'create'));
        $this->assertTrue($schema->hasPermission('managers', 'read'));
        $this->assertTrue($schema->hasPermission('managers', 'update'));
        $this->assertTrue($schema->hasPermission('managers', 'delete'));
        
        // Viewers should have no access (not included in any groups).
        $this->assertFalse($schema->hasPermission('viewers', 'create'));
        $this->assertFalse($schema->hasPermission('viewers', 'read'));
        $this->assertFalse($schema->hasPermission('viewers', 'update'));
        $this->assertFalse($schema->hasPermission('viewers', 'delete'));
    }

    /**
     * Test edge cases and error conditions
     */
    public function testSchemaHasPermissionEdgeCases(): void
    {
        $schema = new Schema();
        $schema->setAuthorization([
            'read' => ['editors']
        ]);

        // Empty group ID should be denied.
        $this->assertFalse($schema->hasPermission('', 'read'));
        
        // Empty action should be allowed (default behavior - unspecified actions are open).
        $this->assertTrue($schema->hasPermission('editors', ''));
        
        // User in authorized group should still have access regardless of object owner.
        $this->assertTrue($schema->hasPermission('editors', 'read', 'user1', null, 'user2'));
        
        // User NOT in authorized group should be denied access when different from object owner.
        $this->assertFalse($schema->hasPermission('viewers', 'read', 'user1', null, 'user2'));
    }

    /**
     * Test complex permission scenarios with multiple conditions
     */
    public function testComplexPermissionScenarios(): void
    {
        $schema = new Schema();
        $schema->setAuthorization([
            'create' => ['contributors', 'editors'],
            'read' => ['viewers', 'contributors', 'editors'], 
            'update' => ['editors'],
            'delete' => ['editors']
        ]);

        // Test combinations of conditions.
        
        // Regular user in authorized group.
        $this->assertTrue($schema->hasPermission('editors', 'create', 'user1'));
        $this->assertTrue($schema->hasPermission('viewers', 'read', 'user2'));
        
        // User not in authorized group but is object owner.
        $this->assertFalse($schema->hasPermission('staff', 'read', 'user3'));
        $this->assertTrue($schema->hasPermission('staff', 'read', 'user3', null, 'user3'));
        
        // Admin user overrides everything.
        $this->assertTrue($schema->hasPermission('any-group', 'delete', 'admin-user', 'admin'));
        
        // Multiple scenarios combined.
        $this->assertTrue($schema->hasPermission('contributors', 'create', 'user4', null, 'user5')); // authorized group
        $this->assertTrue($schema->hasPermission('staff', 'delete', 'user4', null, 'user4')); // object owner
        $this->assertTrue($schema->hasPermission('random', 'update', 'admin', 'admin')); // admin override
    }

    /**
     * Test Schema validation of authorization structure
     */
    public function testSchemaAuthorizationValidation(): void
    {
        // Test valid authorization structure.
        $schema = new Schema();
        $schema->setAuthorization([
            'create' => ['editors'],
            'read' => ['public', 'viewers'],
            'update' => ['editors', 'managers'],
            'delete' => ['managers']
        ]);
        
        $this->assertTrue($schema->validateAuthorization());
        
        // Test invalid action should throw exception.
        $schema2 = new Schema();
        $schema2->setAuthorization([
            'invalid-action' => ['editors'] // invalid CRUD action
        ]);
        
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid authorization action: 'invalid-action'");
        $schema2->validateAuthorization();
    }

    /**
     * Test more authorization validation edge cases
     */
    public function testSchemaAuthorizationValidationEdgeCases(): void
    {
        // Test non-array groups should throw exception.
        $schema = new Schema();
        $schema->setAuthorization([
            'create' => 'not-an-array' // should be array
        ]);
        
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Authorization groups for action 'create' must be an array");
        $schema->validateAuthorization();
    }
} 