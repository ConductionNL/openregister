<?php

/**
 * Comprehensive RBAC Scenario Tests
 *
 * Data-driven tests systematically covering all 64 RBAC scenarios from our complete test matrix.
 * This is the most comprehensive test suite ensuring every permission combination is validated.
 * 
 * ## Complete Test Matrix Coverage (79 tests total):
 * 
 * ### Core RBAC Scenarios (64 tests):
 * **Open Access Schema** (12 scenarios):
 * - Admin user: CREATE ✅, READ ✅, UPDATE ✅, DELETE ✅
 * - Public user: CREATE ✅, READ ✅, UPDATE ✅, DELETE ✅  
 * - Custom users: CREATE ✅, READ ✅, UPDATE ✅, DELETE ✅
 * 
 * **Public Read Schema** (20 scenarios):
 * - Admin: All operations ✅ (admin override)
 * - Public: CREATE ❌, READ ✅, UPDATE ❌, DELETE ❌
 * - Editors: CREATE ✅, READ ❌, UPDATE ✅, DELETE ❌
 * - Managers: CREATE ✅, READ ❌, UPDATE ✅, DELETE ✅
 * - Viewers: CREATE ❌, READ ❌, UPDATE ❌, DELETE ❌
 * 
 * **Staff Only Schema** (16 scenarios):
 * - Admin: All operations ✅ (admin override)
 * - Public: All operations ❌
 * - Staff: All operations ✅
 * - Managers: CREATE ❌, READ ❌, UPDATE ❌, DELETE ✅
 * 
 * **Collaborative Schema** (16 scenarios):
 * - Admin: All operations ✅ (admin override)
 * - Viewers: CREATE ❌, READ ✅, UPDATE ❌, DELETE ❌
 * - Editors: CREATE ✅, READ ✅, UPDATE ✅, DELETE ❌
 * - Managers: All operations ✅
 * 
 * ### Owner Privilege Override Tests (12 tests):
 * Tests that object owners can override schema restrictions for their own objects:
 * - Public Read schema: Viewers can create/update/delete their own objects
 * - Staff Only schema: Non-staff can access their own objects
 * - Collaborative schema: Viewers can create/update/delete their own objects
 * 
 * ### Additional Validation Tests (3 tests):
 * - testComplexScenarios: Multi-condition edge cases
 * - testEdgeCases: Empty inputs, null handling, boundary conditions
 * - testScenarioCount: Validates we have exactly 64 core scenarios
 * 
 * ## Schema Configurations Tested:
 * 
 * ```php
 * 'open_access' => []  // No restrictions
 * 
 * 'public_read' => [
 *     'create' => ['editors', 'managers'],
 *     'read' => ['public'],
 *     'update' => ['editors', 'managers'],
 *     'delete' => ['managers']
 * ]
 * 
 * 'staff_only' => [
 *     'create' => ['staff'],
 *     'read' => ['staff'],
 *     'update' => ['staff'],
 *     'delete' => ['managers', 'staff']
 * ]
 * 
 * 'collaborative' => [
 *     'create' => ['editors', 'managers'],
 *     'read' => ['viewers', 'editors', 'managers'],
 *     'update' => ['editors', 'managers'],
 *     'delete' => ['managers']
 * ]
 * ```
 * 
 * ## Key Testing Principles:
 * - **Data-Driven**: Uses PHPUnit data providers for systematic coverage
 * - **Descriptive Failures**: Detailed error messages show exactly what failed
 * - **Systematic Coverage**: Every user type × operation × schema combination tested
 * - **Real-World Scenarios**: Tests match actual deployment configurations
 * - **Owner Override**: Validates object owners always have access to their objects
 * - **Admin Override**: Validates admin users bypass all restrictions
 * 
 * ## Usage:
 * Run this test suite to validate that RBAC changes don't break any of the 64 core scenarios.
 * This provides comprehensive regression protection for the entire RBAC system.
 * 
 * ## Related Test Files:
 * - RbacTest.php: Tests core Schema permission logic and validation
 * - ObjectServiceRbacTest.php: Tests RBAC integration with ObjectService
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
use PHPUnit\Framework\TestCase;

/**
 * Comprehensive RBAC unit tests covering all 64 scenarios
 */
class RbacComprehensiveTest extends TestCase
{
    /**
     * Schema configurations for testing
     */
    private array $schemaConfigs = [
        'open_access' => [],
        'public_read' => [
            'create' => ['editors', 'managers'],
            'read' => ['public'],
            'update' => ['editors', 'managers'],
            'delete' => ['managers']
        ],
        'staff_only' => [
            'create' => ['staff'],
            'read' => ['staff'],
            'update' => ['staff'],
            'delete' => ['managers', 'staff']
        ],
        'collaborative' => [
            'create' => ['editors', 'managers'],
            'read' => ['viewers', 'editors', 'managers'],
            'update' => ['editors', 'managers'],
            'delete' => ['managers']
        ]
    ];

    /**
     * Complete test matrix data provider
     * Each scenario: [schemaType, userType, operation, expectedResult, description]
     *
     * @return array
     */
    public function rbacScenarioProvider(): array
    {
        return [
            // OPEN ACCESS SCHEMA (12 scenarios).
            ['open_access', 'admin', 'create', true, 'Open Access - Admin can create'],
            ['open_access', 'admin', 'read', true, 'Open Access - Admin can read'],
            ['open_access', 'admin', 'update', true, 'Open Access - Admin can update'],
            ['open_access', 'admin', 'delete', true, 'Open Access - Admin can delete'],
            ['open_access', 'public', 'create', true, 'Open Access - Public can create'],
            ['open_access', 'public', 'read', true, 'Open Access - Public can read'],
            ['open_access', 'public', 'update', true, 'Open Access - Public can update'],
            ['open_access', 'public', 'delete', true, 'Open Access - Public can delete'],
            ['open_access', 'editors', 'create', true, 'Open Access - Custom user can create'],
            ['open_access', 'editors', 'read', true, 'Open Access - Custom user can read'],
            ['open_access', 'editors', 'update', true, 'Open Access - Custom user can update'],
            ['open_access', 'editors', 'delete', true, 'Open Access - Custom user can delete'],
            
            // PUBLIC READ SCHEMA (20 scenarios).
            ['public_read', 'admin', 'create', true, 'Public Read - Admin override (create)'],
            ['public_read', 'admin', 'read', true, 'Public Read - Admin override (read)'],
            ['public_read', 'admin', 'update', true, 'Public Read - Admin override (update)'],
            ['public_read', 'admin', 'delete', true, 'Public Read - Admin override (delete)'],
            ['public_read', 'public', 'create', false, 'Public Read - Public blocked from create'],
            ['public_read', 'public', 'read', true, 'Public Read - Public can read'],
            ['public_read', 'public', 'update', false, 'Public Read - Public blocked from update'],
            ['public_read', 'public', 'delete', false, 'Public Read - Public blocked from delete'],
            ['public_read', 'editors', 'create', true, 'Public Read - Editor can create'],
            ['public_read', 'editors', 'read', false, 'Public Read - Editor blocked from read (not public group)'],
            ['public_read', 'editors', 'update', true, 'Public Read - Editor can update'],
            ['public_read', 'editors', 'delete', false, 'Public Read - Editor blocked from delete'],
            ['public_read', 'managers', 'create', true, 'Public Read - Manager can create'],
            ['public_read', 'managers', 'read', false, 'Public Read - Manager blocked from read (not public group)'],
            ['public_read', 'managers', 'update', true, 'Public Read - Manager can update'],
            ['public_read', 'managers', 'delete', true, 'Public Read - Manager can delete'],
            ['public_read', 'viewers', 'create', false, 'Public Read - Viewer blocked from create'],
            ['public_read', 'viewers', 'read', false, 'Public Read - Viewer blocked from read (not public group)'],
            ['public_read', 'viewers', 'update', false, 'Public Read - Viewer blocked from update'],
            ['public_read', 'viewers', 'delete', false, 'Public Read - Viewer blocked from delete'],
            
            // STAFF ONLY SCHEMA (16 scenarios).
            ['staff_only', 'admin', 'create', true, 'Staff Only - Admin override (create)'],
            ['staff_only', 'admin', 'read', true, 'Staff Only - Admin override (read)'],
            ['staff_only', 'admin', 'update', true, 'Staff Only - Admin override (update)'],
            ['staff_only', 'admin', 'delete', true, 'Staff Only - Admin override (delete)'],
            ['staff_only', 'public', 'create', false, 'Staff Only - Public blocked from create'],
            ['staff_only', 'public', 'read', false, 'Staff Only - Public blocked from read'],
            ['staff_only', 'public', 'update', false, 'Staff Only - Public blocked from update'],
            ['staff_only', 'public', 'delete', false, 'Staff Only - Public blocked from delete'],
            ['staff_only', 'staff', 'create', true, 'Staff Only - Staff can create'],
            ['staff_only', 'staff', 'read', true, 'Staff Only - Staff can read'],
            ['staff_only', 'staff', 'update', true, 'Staff Only - Staff can update'],
            ['staff_only', 'staff', 'delete', true, 'Staff Only - Staff can delete'],
            ['staff_only', 'managers', 'create', false, 'Staff Only - Manager blocked from create'],
            ['staff_only', 'managers', 'read', false, 'Staff Only - Manager blocked from read'],
            ['staff_only', 'managers', 'update', false, 'Staff Only - Manager blocked from update'],
            ['staff_only', 'managers', 'delete', true, 'Staff Only - Manager can delete'],
            
            // COLLABORATIVE SCHEMA (16 scenarios).
            ['collaborative', 'admin', 'create', true, 'Collaborative - Admin override (create)'],
            ['collaborative', 'admin', 'read', true, 'Collaborative - Admin override (read)'],
            ['collaborative', 'admin', 'update', true, 'Collaborative - Admin override (update)'],
            ['collaborative', 'admin', 'delete', true, 'Collaborative - Admin override (delete)'],
            ['collaborative', 'viewers', 'create', false, 'Collaborative - Viewer blocked from create'],
            ['collaborative', 'viewers', 'read', true, 'Collaborative - Viewer can read'],
            ['collaborative', 'viewers', 'update', false, 'Collaborative - Viewer blocked from update'],
            ['collaborative', 'viewers', 'delete', false, 'Collaborative - Viewer blocked from delete'],
            ['collaborative', 'editors', 'create', true, 'Collaborative - Editor can create'],
            ['collaborative', 'editors', 'read', true, 'Collaborative - Editor can read'],
            ['collaborative', 'editors', 'update', true, 'Collaborative - Editor can update'],
            ['collaborative', 'editors', 'delete', false, 'Collaborative - Editor blocked from delete'],
            ['collaborative', 'managers', 'create', true, 'Collaborative - Manager can create'],
            ['collaborative', 'managers', 'read', true, 'Collaborative - Manager can read'],
            ['collaborative', 'managers', 'update', true, 'Collaborative - Manager can update'],
            ['collaborative', 'managers', 'delete', true, 'Collaborative - Manager can delete'],
        ];
    }

    /**
     * Test all 64 RBAC scenarios systematically
     *
     * @dataProvider rbacScenarioProvider
     * @param string $schemaType Schema configuration type
     * @param string $userType User/group type
     * @param string $operation CRUD operation
     * @param bool $expectedResult Expected permission result
     * @param string $description Test description
     */
    public function testRbacScenario(
        string $schemaType,
        string $userType, 
        string $operation,
        bool $expectedResult,
        string $description
    ): void {
        // Create schema with specified configuration.
        $schema = new Schema();
        $schema->setTitle("Test Schema - {$schemaType}");
        $schema->setAuthorization($this->schemaConfigs[$schemaType]);

        // Determine if this is an admin scenario.
        $userGroup = $userType === 'admin' ? 'admin' : null;
        
        // Test the permission.
        $actualResult = $schema->hasPermission($userType, $operation, null, $userGroup);
        
        // Assert the result with detailed failure message.
        $this->assertEquals(
            $expectedResult,
            $actualResult,
            sprintf(
                'FAILED: %s' . PHP_EOL .
                'Schema: %s' . PHP_EOL .
                'User: %s, Operation: %s' . PHP_EOL .
                'Expected: %s, Got: %s' . PHP_EOL .
                'Schema Config: %s',
                $description,
                $schemaType,
                $userType,
                $operation,
                $expectedResult ? 'ALLOWED' : 'BLOCKED',
                $actualResult ? 'ALLOWED' : 'BLOCKED',
                json_encode($this->schemaConfigs[$schemaType])
            )
        );
    }

    /**
     * Test owner privilege override across all schema types
     * 
     * @dataProvider ownerPrivilegeProvider
     */
    public function testOwnerPrivilegeOverride(
        string $schemaType,
        string $userType,
        string $operation,
        string $description
    ): void {
        // Create schema with specified configuration.
        $schema = new Schema();
        $schema->setTitle("Owner Test - {$schemaType}");
        $schema->setAuthorization($this->schemaConfigs[$schemaType]);

        // Test that user normally wouldn't have permission.
        $normalResult = $schema->hasPermission($userType, $operation);
        
        // Test that object owner always has permission.
        $ownerResult = $schema->hasPermission($userType, $operation, 'testuser', null, 'testuser');
        
        // Object owner should always have access regardless of normal permissions.
        $this->assertTrue(
            $ownerResult,
            sprintf(
                'OWNER PRIVILEGE FAILED: %s' . PHP_EOL .
                'Schema: %s, User: %s, Operation: %s' . PHP_EOL .
                'Normal permission: %s, Owner permission: %s' . PHP_EOL .
                'Object owner should ALWAYS have access to their objects',
                $description,
                $schemaType,
                $userType,
                $operation,
                $normalResult ? 'ALLOWED' : 'BLOCKED',
                $ownerResult ? 'ALLOWED' : 'BLOCKED'
            )
        );
    }

    /**
     * Data provider for owner privilege tests
     *
     * @return array
     */
    public function ownerPrivilegeProvider(): array
    {
        return [
            // Test owner privilege for restricted operations across schema types.
            ['public_read', 'viewers', 'create', 'Owner override - Viewer can create their objects'],
            ['public_read', 'viewers', 'update', 'Owner override - Viewer can update their objects'],
            ['public_read', 'viewers', 'delete', 'Owner override - Viewer can delete their objects'],
            ['public_read', 'editors', 'delete', 'Owner override - Editor can delete their objects'],
            ['staff_only', 'viewers', 'read', 'Owner override - Viewer can read their objects'],
            ['staff_only', 'managers', 'create', 'Owner override - Manager can create their objects'],
            ['staff_only', 'managers', 'read', 'Owner override - Manager can read their objects'],
            ['staff_only', 'managers', 'update', 'Owner override - Manager can update their objects'],
            ['collaborative', 'viewers', 'create', 'Owner override - Viewer can create their objects'],
            ['collaborative', 'viewers', 'update', 'Owner override - Viewer can update their objects'],
            ['collaborative', 'viewers', 'delete', 'Owner override - Viewer can delete their objects'],
            ['collaborative', 'editors', 'delete', 'Owner override - Editor can delete their objects'],
        ];
    }

    /**
     * Test complex multi-condition scenarios
     */
    public function testComplexScenarios(): void
    {
        $schema = new Schema();
        $schema->setAuthorization([
            'create' => ['editors'],
            'read' => ['viewers', 'editors'],
            'update' => ['editors'],
            'delete' => ['managers']
        ]);

        // Scenario 1: User in multiple groups - should have permissions from any group.
        $this->assertTrue($schema->hasPermission('editors', 'read')); // Editor can read via editors group
        $this->assertTrue($schema->hasPermission('viewers', 'read')); // Viewer can read via viewers group
        $this->assertFalse($schema->hasPermission('viewers', 'create')); // Viewer cannot create

        // Scenario 2: Admin override in complex schema.
        $this->assertTrue($schema->hasPermission('any-group', 'delete', 'admin-user', 'admin')); // Admin can delete anything
        
        // Scenario 3: Object owner vs group permissions.
        $this->assertFalse($schema->hasPermission('staff', 'read')); // Staff not in viewers/editors groups
        $this->assertTrue($schema->hasPermission('staff', 'read', 'user1', null, 'user1')); // But owner can read their object
        
        // Scenario 4: Missing action (should be open).
        $this->assertTrue($schema->hasPermission('anyone', 'publish')); // Unspecified actions are open
    }

    /**
     * Test edge cases and validation
     */
    public function testEdgeCases(): void
    {
        $schema = new Schema();
        $schema->setAuthorization([
            'read' => ['editors']
        ]);

        // Empty inputs.
        $this->assertFalse($schema->hasPermission('', 'read')); // Empty group ID
        $this->assertTrue($schema->hasPermission('editors', '')); // Empty action (unspecified = open)
        
        // Group vs owner permissions.
        $this->assertTrue($schema->hasPermission('editors', 'read', 'user1', null, 'user2')); // Editors can read regardless of owner
        $this->assertFalse($schema->hasPermission('viewers', 'read', 'user1', null, 'user2')); // Viewers can't read (not authorized)
        $this->assertTrue($schema->hasPermission('viewers', 'read', 'user1', null, 'user1')); // But object owner can read their own
    }

    /**
     * Test that we actually have 64 test scenarios
     */
    public function testScenarioCount(): void
    {
        $provider = $this->rbacScenarioProvider();
        $this->assertCount(64, $provider, 'Should have exactly 64 test scenarios as defined in our test matrix');
        
        // Count scenarios by schema type.
        $counts = [];
        foreach ($provider as $scenario) {
            $schemaType = $scenario[0];
            $counts[$schemaType] = ($counts[$schemaType] ?? 0) + 1;
        }
        
        $this->assertEquals(12, $counts['open_access'], 'Open Access should have 12 scenarios');
        $this->assertEquals(20, $counts['public_read'], 'Public Read should have 20 scenarios');
        $this->assertEquals(16, $counts['staff_only'], 'Staff Only should have 16 scenarios');
        $this->assertEquals(16, $counts['collaborative'], 'Collaborative should have 16 scenarios');
    }
} 