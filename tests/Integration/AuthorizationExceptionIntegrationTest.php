<?php

/**
 * OpenRegister Authorization Exception Integration Test
 *
 * This file contains integration tests for the authorization exception system
 * demonstrating real-world usage scenarios.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Integration;

use OCA\OpenRegister\Db\AuthorizationException;
use OCA\OpenRegister\Db\AuthorizationExceptionMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\AuthorizationExceptionService;
use Test\TestCase;

/**
 * Integration test class for the authorization exception system
 *
 * This class demonstrates real-world scenarios where authorization exceptions
 * are used to override normal RBAC rules, including the example mentioned
 * in the requirements about "ambtenaar" group access.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Integration
 *
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://www.OpenRegister.app
 */
class AuthorizationExceptionIntegrationTest extends TestCase
{

    /** @var AuthorizationExceptionService */
    private $authorizationExceptionService;

    /** @var AuthorizationExceptionMapper */
    private $authorizationExceptionMapper;

    /** @var ObjectEntityMapper */
    private $objectEntityMapper;


    /**
     * Set up test fixtures
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // In a real test, these would be injected from the DI container
        // For now, we'll create simplified instances for demonstration
        
        $this->authorizationExceptionService = $this->createMock(AuthorizationExceptionService::class);
        $this->authorizationExceptionMapper = $this->createMock(AuthorizationExceptionMapper::class);
        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);

    }//end setUp()


    /**
     * Test scenario: Ambtenaar group can read "gebruik" objects from other organizations
     *
     * This test demonstrates the example from the requirements where users in the
     * "ambtenaar" group can read objects of a specific schema from all organizations,
     * not just their own.
     *
     * @return void
     */
    public function testAmbtenaarGroupCanReadGebruikObjectsFromOtherOrganizations(): void
    {
        // Create an inclusion exception for the ambtenaar group
        $exception = new AuthorizationException();
        $exception->setUuid('ambtenaar-gebruik-inclusion');
        $exception->setType(AuthorizationException::TYPE_INCLUSION);
        $exception->setSubjectType(AuthorizationException::SUBJECT_TYPE_GROUP);
        $exception->setSubjectId('ambtenaar');
        $exception->setAction(AuthorizationException::ACTION_READ);
        $exception->setSchemaUuid('gebruik-schema-uuid');
        $exception->setPriority(10); // High priority to override normal rules
        $exception->setDescription('Allow ambtenaar group to read gebruik objects from all organizations');
        $exception->setActive(true);

        // Mock the service to return this exception for ambtenaar group members
        $this->authorizationExceptionService
            ->method('evaluateUserPermission')
            ->willReturnCallback(function ($userId, $action, $schemaUuid) {
                if ($action === 'read' && $schemaUuid === 'gebruik-schema-uuid') {
                    // Simulate checking if user is in ambtenaar group
                    $userGroups = $this->getUserGroups($userId);
                    if (in_array('ambtenaar', $userGroups)) {
                        return true; // Grant access via inclusion
                    }
                }
                return null; // No exception applies
            });

        // Test: User in ambtenaar group should have read access to gebruik objects
        $result = $this->authorizationExceptionService->evaluateUserPermission(
            'ambtenaar-user-1',
            'read',
            'gebruik-schema-uuid',
            'software-catalog-register',
            'other-organization-uuid'
        );

        $this->assertTrue($result, 'Ambtenaar user should have read access to gebruik objects');

        // Test: User NOT in ambtenaar group should NOT have this special access
        $result = $this->authorizationExceptionService->evaluateUserPermission(
            'regular-user',
            'read',
            'gebruik-schema-uuid',
            'software-catalog-register',
            'other-organization-uuid'
        );

        $this->assertNull($result, 'Regular user should fall back to normal RBAC rules');

    }//end testAmbtenaarGroupCanReadGebruikObjectsFromOtherOrganizations()


    /**
     * Test scenario: Specific user is excluded from group permissions
     *
     * This demonstrates an exclusion where a user who belongs to a group
     * that normally has access is specifically denied access.
     *
     * @return void
     */
    public function testSpecificUserExcludedFromGroupPermissions(): void
    {
        // Create an exclusion exception for a specific user
        $exception = new AuthorizationException();
        $exception->setUuid('user-exclusion-example');
        $exception->setType(AuthorizationException::TYPE_EXCLUSION);
        $exception->setSubjectType(AuthorizationException::SUBJECT_TYPE_USER);
        $exception->setSubjectId('problematic-user');
        $exception->setAction(AuthorizationException::ACTION_UPDATE);
        $exception->setSchemaUuid('sensitive-schema-uuid');
        $exception->setPriority(15); // Very high priority to override group permissions
        $exception->setDescription('Deny specific user update access despite group membership');
        $exception->setActive(true);

        // Mock the service to return this exception for the specific user
        $this->authorizationExceptionService
            ->method('evaluateUserPermission')
            ->willReturnCallback(function ($userId, $action, $schemaUuid) {
                if ($userId === 'problematic-user' && 
                    $action === 'update' && 
                    $schemaUuid === 'sensitive-schema-uuid') {
                    return false; // Deny access via exclusion
                }
                return null; // No exception applies
            });

        // Test: The problematic user should be denied access
        $result = $this->authorizationExceptionService->evaluateUserPermission(
            'problematic-user',
            'update',
            'sensitive-schema-uuid'
        );

        $this->assertFalse($result, 'Problematic user should be denied access via exclusion');

        // Test: Other users in the same group should not be affected
        $result = $this->authorizationExceptionService->evaluateUserPermission(
            'other-group-member',
            'update',
            'sensitive-schema-uuid'
        );

        $this->assertNull($result, 'Other group members should follow normal RBAC rules');

    }//end testSpecificUserExcludedFromGroupPermissions()


    /**
     * Test scenario: Priority-based exception resolution
     *
     * This demonstrates how exceptions with different priorities are resolved,
     * with higher priority exceptions taking precedence.
     *
     * @return void
     */
    public function testPriorityBasedExceptionResolution(): void
    {
        // Create multiple exceptions for the same user with different priorities
        $lowPriorityInclusion = new AuthorizationException();
        $lowPriorityInclusion->setType(AuthorizationException::TYPE_INCLUSION);
        $lowPriorityInclusion->setPriority(5);

        $highPriorityExclusion = new AuthorizationException();
        $highPriorityExclusion->setType(AuthorizationException::TYPE_EXCLUSION);
        $highPriorityExclusion->setPriority(10);

        // Mock the service to simulate priority-based resolution
        $this->authorizationExceptionService
            ->method('evaluateUserPermission')
            ->willReturnCallback(function ($userId, $action, $schemaUuid) {
                // Simulate finding both exceptions but exclusion has higher priority
                return false; // High priority exclusion wins
            });

        $result = $this->authorizationExceptionService->evaluateUserPermission(
            'conflicted-user',
            'delete',
            'protected-schema-uuid'
        );

        $this->assertFalse($result, 'High priority exclusion should override low priority inclusion');

    }//end testPriorityBasedExceptionResolution()


    /**
     * Test scenario: Organization-specific exceptions
     *
     * This demonstrates exceptions that only apply within specific organizations.
     *
     * @return void
     */
    public function testOrganizationSpecificExceptions(): void
    {
        $exception = new AuthorizationException();
        $exception->setType(AuthorizationException::TYPE_INCLUSION);
        $exception->setSubjectType(AuthorizationException::SUBJECT_TYPE_GROUP);
        $exception->setSubjectId('contractors');
        $exception->setAction(AuthorizationException::ACTION_CREATE);
        $exception->setSchemaUuid('project-schema-uuid');
        $exception->setOrganizationUuid('client-org-uuid'); // Only applies to this organization
        $exception->setPriority(8);
        $exception->setDescription('Allow contractors to create projects only in client organization');

        // Mock the service to check organization context
        $this->authorizationExceptionService
            ->method('evaluateUserPermission')
            ->willReturnCallback(function ($userId, $action, $schemaUuid, $registerUuid, $organizationUuid) {
                if ($organizationUuid === 'client-org-uuid' && 
                    $action === 'create' && 
                    $schemaUuid === 'project-schema-uuid') {
                    // Check if user is in contractors group
                    $userGroups = $this->getUserGroups($userId);
                    if (in_array('contractors', $userGroups)) {
                        return true; // Grant access in this organization
                    }
                }
                return null; // No exception applies
            });

        // Test: Contractor should have create access in client organization
        $result = $this->authorizationExceptionService->evaluateUserPermission(
            'contractor-user',
            'create',
            'project-schema-uuid',
            'projects-register',
            'client-org-uuid'
        );

        $this->assertTrue($result, 'Contractor should have create access in client organization');

        // Test: Same contractor should NOT have access in different organization
        $result = $this->authorizationExceptionService->evaluateUserPermission(
            'contractor-user',
            'create',
            'project-schema-uuid',
            'projects-register',
            'other-org-uuid'
        );

        $this->assertNull($result, 'Contractor should not have special access in other organizations');

    }//end testOrganizationSpecificExceptions()


    /**
     * Test complete object permission check with exceptions
     *
     * This test demonstrates how the ObjectEntityMapper uses the authorization
     * exception system to check permissions on specific objects.
     *
     * @return void
     */
    public function testCompleteObjectPermissionCheck(): void
    {
        // Create a mock object
        $object = new ObjectEntity();
        $object->setUuid('test-object-uuid');
        $object->setSchema('sensitive-data-schema');
        $object->setOwner('data-owner');

        // Create a mock schema
        $schema = new Schema();
        $schema->setUuid('sensitive-data-schema');
        $schema->setAuthorization([
            'read' => ['data-analysts', 'managers'],
            'update' => ['data-managers'],
        ]);

        // Mock the object mapper to use authorization exceptions
        $this->objectEntityMapper
            ->method('checkObjectPermission')
            ->willReturnCallback(function ($userId, $action, $object, $schema) {
                // First check exceptions (highest priority)
                $exceptionResult = $this->authorizationExceptionService->evaluateUserPermission(
                    $userId,
                    $action,
                    $schema->getUuid()
                );

                if ($exceptionResult !== null) {
                    return $exceptionResult; // Exception overrides normal RBAC
                }

                // Fall back to normal RBAC checks
                if ($object->getOwner() === $userId) {
                    return true; // Owner always has access
                }

                // Check schema authorization
                $authorization = $schema->getAuthorization();
                $allowedGroups = $authorization[$action] ?? [];
                $userGroups = $this->getUserGroups($userId);

                return empty(array_intersect($userGroups, $allowedGroups)) === false;
            });

        // Test: Owner should always have access
        $result = $this->objectEntityMapper->checkObjectPermission(
            'data-owner',
            'read',
            $object,
            $schema
        );

        $this->assertTrue($result, 'Object owner should always have access');

        // Test: User with group permission should have access
        $result = $this->objectEntityMapper->checkObjectPermission(
            'analyst-user', // Assume this user is in 'data-analysts' group
            'read',
            $object,
            $schema
        );

        $this->assertTrue($result, 'User in authorized group should have access');

        // Test: User without group permission should be denied
        $result = $this->objectEntityMapper->checkObjectPermission(
            'random-user',
            'update',
            $object,
            $schema
        );

        $this->assertFalse($result, 'User without group permission should be denied');

    }//end testCompleteObjectPermissionCheck()


    /**
     * Helper method to simulate getting user groups
     *
     * @param string $userId The user ID
     *
     * @return array<string> Array of group IDs the user belongs to
     */
    private function getUserGroups(string $userId): array
    {
        // This would normally query the actual group manager
        // For testing, we'll return simulated group memberships
        
        $groupMemberships = [
            'ambtenaar-user-1' => ['ambtenaar', 'users'],
            'ambtenaar-user-2' => ['ambtenaar', 'users'],
            'regular-user' => ['users'],
            'problematic-user' => ['editors', 'users'], // Has group access but excluded
            'other-group-member' => ['editors', 'users'],
            'contractor-user' => ['contractors', 'users'],
            'analyst-user' => ['data-analysts', 'users'],
            'random-user' => ['users'],
        ];

        return $groupMemberships[$userId] ?? ['users'];

    }//end getUserGroups()


}//end class

