<?php

/**
 * ObjectService RBAC Integration Tests
 *
 * Tests the integration of RBAC functionality with ObjectService and Nextcloud's
 * user management systems. These tests use reflection to test private methods
 * and mock Nextcloud dependencies (IUserSession, IGroupManager, IUserManager).
 * 
 * ## Test Coverage (13+ tests):
 * 
 * ### User Authentication & Authorization Tests:
 * - testHasPermissionUnauthenticatedUser: Tests permission checking for unauthenticated users
 * - testHasPermissionAuthenticatedUser: Tests permission checking for authenticated users with groups
 * - testHasPermissionAdminOverride: Tests that admin users bypass all RBAC restrictions
 * - testHasPermissionObjectOwnerOverride: Tests object owner privilege override functionality
 * 
 * ### Permission Exception Tests:
 * - testCheckPermissionSuccess: Tests that authorized operations don't throw exceptions
 * - testCheckPermissionException: Tests that unauthorized operations throw proper exceptions
 * - testCheckPermissionObjectOwnerOverride: Tests exception behavior with non-owner access
 * - testCheckPermissionObjectOwnerHasAccess: Tests that object owners don't get exceptions
 * 
 * ### Integration Scenario Tests:
 * - testCreateOperationPermissionCheck: Tests CREATE permission integration
 * - testUserWithoutGroups: Tests behavior when users have no group memberships
 * - testNullUserHandling: Tests proper handling of null/unauthenticated users
 * 
 * ## Key Features Tested:
 * - **Mocked Dependencies**: IUserSession, IGroupManager, IUserManager are fully mocked
 * - **Reflection Access**: Tests private hasPermission() and checkPermission() methods
 * - **Exception Handling**: Validates proper exception messages and HTTP status codes
 * - **User Group Integration**: Tests integration with Nextcloud's group system
 * - **Admin Privilege**: Validates admin users can bypass all restrictions
 * - **Owner Privilege**: Validates object owners have full access to their objects
 * 
 * ## Mock Setup:
 * Each test sets up realistic mock scenarios including:
 * - User authentication status
 * - User group memberships  
 * - Schema authorization configurations
 * - Object ownership relationships
 * 
 * ## Related Test Files:
 * - RbacTest.php: Tests core Schema permission logic
 * - RbacComprehensiveTest.php: Data-driven tests covering all 64 RBAC scenarios
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
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\ObjectHandlers\DeleteObject;
use OCA\OpenRegister\Service\ObjectHandlers\GetObject;
use OCA\OpenRegister\Service\ObjectHandlers\SaveObject;
use OCA\OpenRegister\Service\ObjectHandlers\ValidateObject;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\SearchTrailService;
use OCP\IUserSession;
use OCP\IUser;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for ObjectService RBAC functionality
 */
class ObjectServiceRbacTest extends TestCase
{
    /** @var ObjectService */
    private ObjectService $objectService;

    /** @var MockObject|IUserSession */
    private $userSession;

    /** @var MockObject|IGroupManager */
    private $groupManager;

    /** @var MockObject|IUserManager */
    private $userManager;

    /** @var MockObject|IUser */
    private $mockUser;

    /** @var MockObject|SchemaMapper */
    private $schemaMapper;

    /** @var MockObject|RegisterMapper */
    private $registerMapper;

    /** @var MockObject|ObjectEntityMapper */
    private $objectEntityMapper;

    /** @var MockObject|DeleteObject */
    private $deleteHandler;

    /** @var MockObject|GetObject */
    private $getHandler;

    /** @var MockObject|SaveObject */
    private $saveHandler;

    /** @var MockObject|ValidateObject */
    private $validateHandler;

    /** @var MockObject|FileService */
    private $fileService;

    /** @var MockObject|SearchTrailService */
    private $searchTrailService;

    /** @var Schema */
    private Schema $mockSchema;

    /**
     * Set up test fixtures
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Mock all dependencies.
        $this->userSession = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->mockUser = $this->createMock(IUser::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
        $this->deleteHandler = $this->createMock(DeleteObject::class);
        $this->getHandler = $this->createMock(GetObject::class);
        $this->saveHandler = $this->createMock(SaveObject::class);
        $this->validateHandler = $this->createMock(ValidateObject::class);
        $this->fileService = $this->createMock(FileService::class);
        $this->searchTrailService = $this->createMock(SearchTrailService::class);

        // Create ObjectService with mocked dependencies.
        $this->objectService = new ObjectService(
            $this->deleteHandler,
            $this->getHandler,
            $this->saveHandler,
            $this->validateHandler,
            $this->registerMapper,
            $this->schemaMapper,
            $this->objectEntityMapper,
            $this->fileService,
            $this->userSession,
            $this->groupManager,
            $this->userManager,
            $this->searchTrailService,
            null, // renderHandler
            null, // publishHandler
            null  // depublishHandler
        );

        // Create test schema.
        $this->mockSchema = new Schema();
        $this->mockSchema->setId(1);
        $this->mockSchema->setTitle('Test Schema');
    }

    /**
     * Test ObjectService hasPermission - Unauthenticated User (Public Access)
     */
    public function testHasPermissionUnauthenticatedUser(): void
    {
        // Setup: No authenticated user.
        $this->userSession->method('getUser')->willReturn(null);

        // Create open access schema.
        $schema = new Schema();
        $schema->setAuthorization([]);

        // Test using reflection to access private method.
        $reflection = new \ReflectionClass($this->objectService);
        $hasPermissionMethod = $reflection->getMethod('hasPermission');
        $hasPermissionMethod->setAccessible(true);

        // Unauthenticated users should have access to open schemas.
        $this->assertTrue($hasPermissionMethod->invoke($this->objectService, $schema, 'read'));
        $this->assertTrue($hasPermissionMethod->invoke($this->objectService, $schema, 'create'));

        // Test with schema that allows public read.
        $publicReadSchema = new Schema();
        $publicReadSchema->setAuthorization(['read' => ['public']]);
        
        $this->assertTrue($hasPermissionMethod->invoke($this->objectService, $publicReadSchema, 'read'));
        $this->assertFalse($hasPermissionMethod->invoke($this->objectService, $publicReadSchema, 'create'));
    }

    /**
     * Test ObjectService hasPermission - Authenticated User with Groups
     */
    public function testHasPermissionAuthenticatedUser(): void
    {
        // Setup: Authenticated user with groups.
        $this->mockUser->method('getUID')->willReturn('testuser');
        $this->userSession->method('getUser')->willReturn($this->mockUser);
        $this->userManager->method('get')->with('testuser')->willReturn($this->mockUser);
        $this->groupManager->method('getUserGroupIds')
            ->with($this->mockUser)
            ->willReturn(['editors', 'viewers']);

        // Create schema with group-based permissions.
        $schema = new Schema();
        $schema->setAuthorization([
            'create' => ['editors', 'managers'],
            'read' => ['viewers', 'editors', 'managers'],
            'update' => ['editors', 'managers'],
            'delete' => ['managers']
        ]);

        // Test using reflection to access private method.
        $reflection = new \ReflectionClass($this->objectService);
        $hasPermissionMethod = $reflection->getMethod('hasPermission');
        $hasPermissionMethod->setAccessible(true);

        // User in editors and viewers groups should have appropriate permissions.
        $this->assertTrue($hasPermissionMethod->invoke($this->objectService, $schema, 'create')); // editors can create
        $this->assertTrue($hasPermissionMethod->invoke($this->objectService, $schema, 'read'));   // viewers can read
        $this->assertTrue($hasPermissionMethod->invoke($this->objectService, $schema, 'update')); // editors can update
        $this->assertFalse($hasPermissionMethod->invoke($this->objectService, $schema, 'delete')); // only managers can delete
    }

    /**
     * Test ObjectService hasPermission - Admin Override
     */
    public function testHasPermissionAdminOverride(): void
    {
        // Setup: Admin user.
        $this->mockUser->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($this->mockUser);
        $this->userManager->method('get')->with('admin')->willReturn($this->mockUser);
        $this->groupManager->method('getUserGroupIds')
            ->with($this->mockUser)
            ->willReturn(['admin', 'users']);

        // Create restrictive schema.
        $schema = new Schema();
        $schema->setAuthorization([
            'create' => ['staff'],
            'read' => ['staff'],
            'update' => ['staff'],
            'delete' => ['staff']
        ]);

        // Test using reflection to access private method.
        $reflection = new \ReflectionClass($this->objectService);
        $hasPermissionMethod = $reflection->getMethod('hasPermission');
        $hasPermissionMethod->setAccessible(true);

        // Admin should bypass all restrictions.
        $this->assertTrue($hasPermissionMethod->invoke($this->objectService, $schema, 'create'));
        $this->assertTrue($hasPermissionMethod->invoke($this->objectService, $schema, 'read'));
        $this->assertTrue($hasPermissionMethod->invoke($this->objectService, $schema, 'update'));
        $this->assertTrue($hasPermissionMethod->invoke($this->objectService, $schema, 'delete'));
    }

    /**
     * Test ObjectService hasPermission - Object Owner Override
     */
    public function testHasPermissionObjectOwnerOverride(): void
    {
        // Setup: Regular user (not in authorized groups).
        $this->mockUser->method('getUID')->willReturn('objectowner');
        $this->userSession->method('getUser')->willReturn($this->mockUser);
        $this->userManager->method('get')->with('objectowner')->willReturn($this->mockUser);
        $this->groupManager->method('getUserGroupIds')
            ->with($this->mockUser)
            ->willReturn(['users']); // Not in any authorized groups

        // Create restrictive schema.
        $schema = new Schema();
        $schema->setAuthorization([
            'create' => ['staff'],
            'read' => ['staff'],
            'update' => ['staff'],
            'delete' => ['managers']
        ]);

        // Test using reflection to access private method.
        $reflection = new \ReflectionClass($this->objectService);
        $hasPermissionMethod = $reflection->getMethod('hasPermission');
        $hasPermissionMethod->setAccessible(true);

        // User should not have access normally.
        $this->assertFalse($hasPermissionMethod->invoke($this->objectService, $schema, 'read', null, null));
        $this->assertFalse($hasPermissionMethod->invoke($this->objectService, $schema, 'update', null, null));
        $this->assertFalse($hasPermissionMethod->invoke($this->objectService, $schema, 'delete', null, null));

        // Same user should have access when they own the object.
        $this->assertTrue($hasPermissionMethod->invoke($this->objectService, $schema, 'read', null, 'objectowner'));
        $this->assertTrue($hasPermissionMethod->invoke($this->objectService, $schema, 'update', null, 'objectowner'));
        $this->assertTrue($hasPermissionMethod->invoke($this->objectService, $schema, 'delete', null, 'objectowner'));

        // Different owner should not grant access.
        $this->assertFalse($hasPermissionMethod->invoke($this->objectService, $schema, 'read', null, 'differentowner'));
    }

    /**
     * Test ObjectService checkPermission - Success Cases
     */
    public function testCheckPermissionSuccess(): void
    {
        // Setup: User with appropriate permissions.
        $this->mockUser->method('getUID')->willReturn('editor');
        $this->mockUser->method('getDisplayName')->willReturn('Editor User');
        $this->userSession->method('getUser')->willReturn($this->mockUser);
        $this->userManager->method('get')->with('editor')->willReturn($this->mockUser);
        $this->groupManager->method('getUserGroupIds')
            ->with($this->mockUser)
            ->willReturn(['editors']);

        $schema = new Schema();
        $schema->setTitle('Test Schema');
        $schema->setAuthorization([
            'create' => ['editors'],
            'read' => ['editors'],
            'update' => ['editors'],
            'delete' => ['managers']
        ]);

        // Test using reflection to access private method.
        $reflection = new \ReflectionClass($this->objectService);
        $checkPermissionMethod = $reflection->getMethod('checkPermission');
        $checkPermissionMethod->setAccessible(true);

        // Should not throw exception for authorized actions.
        $checkPermissionMethod->invoke($this->objectService, $schema, 'create');
        $checkPermissionMethod->invoke($this->objectService, $schema, 'read');
        $checkPermissionMethod->invoke($this->objectService, $schema, 'update');

        // This assertion passes if no exception was thrown.
        $this->assertTrue(true);
    }

    /**
     * Test ObjectService checkPermission - Exception on Unauthorized Action
     */
    public function testCheckPermissionException(): void
    {
        // Setup: User without delete permissions.
        $this->mockUser->method('getUID')->willReturn('editor');
        $this->mockUser->method('getDisplayName')->willReturn('Editor User');
        $this->userSession->method('getUser')->willReturn($this->mockUser);
        $this->userManager->method('get')->with('editor')->willReturn($this->mockUser);
        $this->groupManager->method('getUserGroupIds')
            ->with($this->mockUser)
            ->willReturn(['editors']);

        $schema = new Schema();
        $schema->setTitle('Test Schema');
        $schema->setAuthorization([
            'create' => ['editors'],
            'read' => ['editors'],
            'update' => ['editors'],
            'delete' => ['managers'] // Editor cannot delete
        ]);

        // Test using reflection to access private method.
        $reflection = new \ReflectionClass($this->objectService);
        $checkPermissionMethod = $reflection->getMethod('checkPermission');
        $checkPermissionMethod->setAccessible(true);

        // Should throw exception for unauthorized action.
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("User 'Editor User' does not have permission to 'delete' objects in schema 'Test Schema'");
        
        $checkPermissionMethod->invoke($this->objectService, $schema, 'delete');
    }

    /**
     * Test ObjectService checkPermission - Object Owner Override
     */
    public function testCheckPermissionObjectOwnerOverride(): void
    {
        // Setup: User without delete permissions but is object owner.
        $this->mockUser->method('getUID')->willReturn('editor');
        $this->mockUser->method('getDisplayName')->willReturn('Editor User');
        $this->userSession->method('getUser')->willReturn($this->mockUser);
        $this->userManager->method('get')->with('editor')->willReturn($this->mockUser);
        $this->groupManager->method('getUserGroupIds')
            ->with($this->mockUser)
            ->willReturn(['editors']);

        $schema = new Schema();
        $schema->setTitle('Test Schema');
        $schema->setAuthorization([
            'delete' => ['managers'] // Editor cannot delete normally
        ]);

        // Test using reflection to access private method.
        $reflection = new \ReflectionClass($this->objectService);
        $checkPermissionMethod = $reflection->getMethod('checkPermission');
        $checkPermissionMethod->setAccessible(true);

        // Should throw exception for unauthorized action normally.
        $this->expectException(\Exception::class);
        $checkPermissionMethod->invoke($this->objectService, $schema, 'delete', null, null);
    }

    /**
     * Test ObjectService checkPermission - Object Owner Has Access
     */
    public function testCheckPermissionObjectOwnerHasAccess(): void
    {
        // Setup: User without delete permissions but is object owner.
        $this->mockUser->method('getUID')->willReturn('editor');
        $this->mockUser->method('getDisplayName')->willReturn('Editor User');
        $this->userSession->method('getUser')->willReturn($this->mockUser);
        $this->userManager->method('get')->with('editor')->willReturn($this->mockUser);
        $this->groupManager->method('getUserGroupIds')
            ->with($this->mockUser)
            ->willReturn(['editors']);

        $schema = new Schema();
        $schema->setTitle('Test Schema');
        $schema->setAuthorization([
            'delete' => ['managers'] // Editor cannot delete normally
        ]);

        // Test using reflection to access private method.
        $reflection = new \ReflectionClass($this->objectService);
        $checkPermissionMethod = $reflection->getMethod('checkPermission');
        $checkPermissionMethod->setAccessible(true);

        // Should NOT throw exception when user is object owner.
        $checkPermissionMethod->invoke($this->objectService, $schema, 'delete', null, 'editor');
        
        // This assertion passes if no exception was thrown.
        $this->assertTrue(true);
    }

    /**
     * Test integration scenario: Create operation permission check
     */
    public function testCreateOperationPermissionCheck(): void
    {
        // This would test the integration with actual CRUD operations.
        // but we'll focus on the permission logic itself.
        
        $this->mockUser->method('getUID')->willReturn('contributor');
        $this->userSession->method('getUser')->willReturn($this->mockUser);
        $this->userManager->method('get')->with('contributor')->willReturn($this->mockUser);
        $this->groupManager->method('getUserGroupIds')
            ->with($this->mockUser)
            ->willReturn(['contributors']);

        $schema = new Schema();
        $schema->setAuthorization([
            'create' => ['contributors', 'editors'],
            'read' => ['public'],
            'update' => ['editors'],
            'delete' => ['managers']
        ]);

        // Test using reflection.
        $reflection = new \ReflectionClass($this->objectService);
        $hasPermissionMethod = $reflection->getMethod('hasPermission');
        $hasPermissionMethod->setAccessible(true);

        // Contributor should be able to create.
        $this->assertTrue($hasPermissionMethod->invoke($this->objectService, $schema, 'create'));
        
        // But not update or delete.
        $this->assertFalse($hasPermissionMethod->invoke($this->objectService, $schema, 'update'));
        $this->assertFalse($hasPermissionMethod->invoke($this->objectService, $schema, 'delete'));
    }

    /**
     * Test edge case: User without groups
     */
    public function testUserWithoutGroups(): void
    {
        $this->mockUser->method('getUID')->willReturn('isolateduser');
        $this->userSession->method('getUser')->willReturn($this->mockUser);
        $this->userManager->method('get')->with('isolateduser')->willReturn($this->mockUser);
        $this->groupManager->method('getUserGroupIds')
            ->with($this->mockUser)
            ->willReturn([]); // No groups

        $schema = new Schema();
        $schema->setAuthorization([
            'read' => ['viewers'],
            'create' => ['editors']
        ]);

        // Test using reflection.
        $reflection = new \ReflectionClass($this->objectService);
        $hasPermissionMethod = $reflection->getMethod('hasPermission');
        $hasPermissionMethod->setAccessible(true);

        // User with no groups should have no permissions.
        $this->assertFalse($hasPermissionMethod->invoke($this->objectService, $schema, 'read'));
        $this->assertFalse($hasPermissionMethod->invoke($this->objectService, $schema, 'create'));
    }

    /**
     * Test edge case: Null user handling
     */
    public function testNullUserHandling(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        
        // Test open access schema.
        $openSchema = new Schema();
        $openSchema->setAuthorization([]);

        // Test restricted schema.  
        $restrictedSchema = new Schema();
        $restrictedSchema->setAuthorization([
            'read' => ['users']
        ]);

        // Test public access schema.
        $publicSchema = new Schema();
        $publicSchema->setAuthorization([
            'read' => ['public']
        ]);

        // Test using reflection.
        $reflection = new \ReflectionClass($this->objectService);
        $hasPermissionMethod = $reflection->getMethod('hasPermission');
        $hasPermissionMethod->setAccessible(true);

        // Null user should have access to open and public schemas.
        $this->assertTrue($hasPermissionMethod->invoke($this->objectService, $openSchema, 'read'));
        $this->assertTrue($hasPermissionMethod->invoke($this->objectService, $publicSchema, 'read'));
        
        // But not to restricted schemas.
        $this->assertFalse($hasPermissionMethod->invoke($this->objectService, $restrictedSchema, 'read'));
    }
} 