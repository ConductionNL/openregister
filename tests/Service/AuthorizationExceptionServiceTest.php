<?php

/**
 * OpenRegister Authorization Exception Service Test
 *
 * This file contains tests for the authorization exception service
 * in the OpenRegister application.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Service;

use OCA\OpenRegister\Db\AuthorizationException;
use OCA\OpenRegister\Db\AuthorizationExceptionMapper;
use OCA\OpenRegister\Service\AuthorizationExceptionService;
use OCP\IUserSession;
use OCP\IUser;
use OCP\IGroup;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Test class for AuthorizationExceptionService
 *
 * This class tests the business logic for authorization exceptions,
 * including creating exceptions and evaluating user permissions.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Service
 *
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://www.OpenRegister.app
 */
class AuthorizationExceptionServiceTest extends TestCase
{

    /** @var AuthorizationExceptionMapper|MockObject */
    private $mapper;

    /** @var IUserSession|MockObject */
    private $userSession;

    /** @var IGroupManager|MockObject */
    private $groupManager;

    /** @var LoggerInterface|MockObject */
    private $logger;

    /** @var AuthorizationExceptionService */
    private $service;

    /** @var IUser|MockObject */
    private $user;

    /** @var IGroup|MockObject */
    private $group;


    /**
     * Set up test fixtures
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = $this->createMock(AuthorizationExceptionMapper::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->user = $this->createMock(IUser::class);
        $this->user->method('getUID')->willReturn('test-user');

        $this->group = $this->createMock(IGroup::class);
        $this->group->method('getGID')->willReturn('test-group');

        $this->service = new AuthorizationExceptionService(
            $this->mapper,
            $this->userSession,
            $this->groupManager,
            $this->logger
        );

    }//end setUp()


    /**
     * Test creating a user inclusion exception
     *
     * @return void
     */
    public function testCreateUserInclusionException(): void
    {
        $this->userSession->method('getUser')->willReturn($this->user);

        $expectedException = new AuthorizationException();
        $expectedException->setUuid('test-uuid');

        $this->mapper->expects($this->once())
            ->method('createException')
            ->willReturn($expectedException);

        $result = $this->service->createException(
            AuthorizationException::TYPE_INCLUSION,
            AuthorizationException::SUBJECT_TYPE_USER,
            'target-user',
            AuthorizationException::ACTION_READ,
            'schema-uuid',
            'register-uuid',
            'org-uuid',
            10,
            'Allow user to read schema'
        );

        $this->assertInstanceOf(AuthorizationException::class, $result);
        $this->assertEquals('test-uuid', $result->getUuid());

    }//end testCreateUserInclusionException()


    /**
     * Test creating a group exclusion exception
     *
     * @return void
     */
    public function testCreateGroupExclusionException(): void
    {
        $this->userSession->method('getUser')->willReturn($this->user);
        $this->groupManager->method('groupExists')->with('target-group')->willReturn(true);

        $expectedException = new AuthorizationException();
        $expectedException->setUuid('test-uuid-2');

        $this->mapper->expects($this->once())
            ->method('createException')
            ->willReturn($expectedException);

        $result = $this->service->createException(
            AuthorizationException::TYPE_EXCLUSION,
            AuthorizationException::SUBJECT_TYPE_GROUP,
            'target-group',
            AuthorizationException::ACTION_UPDATE,
            'schema-uuid',
            null,
            null,
            5,
            'Deny group from updating schema'
        );

        $this->assertInstanceOf(AuthorizationException::class, $result);
        $this->assertEquals('test-uuid-2', $result->getUuid());

    }//end testCreateGroupExclusionException()


    /**
     * Test creating exception fails without authenticated user
     *
     * @return void
     */
    public function testCreateExceptionFailsWithoutUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No authenticated user to create authorization exception');

        $this->service->createException(
            AuthorizationException::TYPE_INCLUSION,
            AuthorizationException::SUBJECT_TYPE_USER,
            'target-user',
            AuthorizationException::ACTION_READ
        );

    }//end testCreateExceptionFailsWithoutUser()


    /**
     * Test creating exception with invalid type
     *
     * @return void
     */
    public function testCreateExceptionWithInvalidType(): void
    {
        $this->userSession->method('getUser')->willReturn($this->user);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid exception type: invalid-type');

        $this->service->createException(
            'invalid-type',
            AuthorizationException::SUBJECT_TYPE_USER,
            'target-user',
            AuthorizationException::ACTION_READ
        );

    }//end testCreateExceptionWithInvalidType()


    /**
     * Test evaluating user permission with exclusion
     *
     * @return void
     */
    public function testEvaluateUserPermissionWithExclusion(): void
    {
        $exclusion = new AuthorizationException();
        $exclusion->setType(AuthorizationException::TYPE_EXCLUSION);
        $exclusion->setPriority(10);

        $this->mapper->method('findApplicableExceptions')
            ->willReturn([$exclusion]);

        $this->groupManager->method('get')->willReturn($this->user);
        $this->groupManager->method('getUserGroups')->willReturn([$this->group]);

        $result = $this->service->evaluateUserPermission(
            'test-user',
            AuthorizationException::ACTION_READ,
            'schema-uuid'
        );

        $this->assertFalse($result);

    }//end testEvaluateUserPermissionWithExclusion()


    /**
     * Test evaluating user permission with inclusion
     *
     * @return void
     */
    public function testEvaluateUserPermissionWithInclusion(): void
    {
        $inclusion = new AuthorizationException();
        $inclusion->setType(AuthorizationException::TYPE_INCLUSION);
        $inclusion->setPriority(5);

        $this->mapper->method('findApplicableExceptions')
            ->willReturn([$inclusion]);

        $this->groupManager->method('get')->willReturn($this->user);
        $this->groupManager->method('getUserGroups')->willReturn([$this->group]);

        $result = $this->service->evaluateUserPermission(
            'test-user',
            AuthorizationException::ACTION_READ,
            'schema-uuid'
        );

        $this->assertTrue($result);

    }//end testEvaluateUserPermissionWithInclusion()


    /**
     * Test evaluating user permission with no exceptions
     *
     * @return void
     */
    public function testEvaluateUserPermissionWithNoExceptions(): void
    {
        $this->mapper->method('findApplicableExceptions')
            ->willReturn([]);

        $this->groupManager->method('get')->willReturn($this->user);
        $this->groupManager->method('getUserGroups')->willReturn([$this->group]);

        $result = $this->service->evaluateUserPermission(
            'test-user',
            AuthorizationException::ACTION_READ,
            'schema-uuid'
        );

        $this->assertNull($result);

    }//end testEvaluateUserPermissionWithNoExceptions()


    /**
     * Test evaluating user permission with mixed exceptions (exclusion wins)
     *
     * @return void
     */
    public function testEvaluateUserPermissionWithMixedExceptions(): void
    {
        $exclusion = new AuthorizationException();
        $exclusion->setType(AuthorizationException::TYPE_EXCLUSION);
        $exclusion->setPriority(10);

        $inclusion = new AuthorizationException();
        $inclusion->setType(AuthorizationException::TYPE_INCLUSION);
        $inclusion->setPriority(5);

        // Exclusion should be evaluated first due to higher priority
        $this->mapper->method('findApplicableExceptions')
            ->willReturn([$exclusion, $inclusion]);

        $this->groupManager->method('get')->willReturn($this->user);
        $this->groupManager->method('getUserGroups')->willReturn([$this->group]);

        $result = $this->service->evaluateUserPermission(
            'test-user',
            AuthorizationException::ACTION_READ,
            'schema-uuid'
        );

        $this->assertFalse($result);

    }//end testEvaluateUserPermissionWithMixedExceptions()


    /**
     * Test checking if user has exceptions
     *
     * @return void
     */
    public function testUserHasExceptions(): void
    {
        $exception = new AuthorizationException();
        
        $this->mapper->method('findBySubject')
            ->willReturn([$exception]);

        $result = $this->service->userHasExceptions('test-user');

        $this->assertTrue($result);

    }//end testUserHasExceptions()


    /**
     * Test checking if user has no exceptions
     *
     * @return void
     */
    public function testUserHasNoExceptions(): void
    {
        $this->mapper->method('findBySubject')
            ->willReturn([]);

        $this->groupManager->method('get')->willReturn($this->user);
        $this->groupManager->method('getUserGroups')->willReturn([$this->group]);

        $result = $this->service->userHasExceptions('test-user');

        $this->assertFalse($result);

    }//end testUserHasNoExceptions()


    /**
     * Test getting all user exceptions including group exceptions
     *
     * @return void
     */
    public function testGetUserExceptions(): void
    {
        $userException = new AuthorizationException();
        $userException->setPriority(5);

        $groupException = new AuthorizationException();
        $groupException->setPriority(10);

        $this->mapper->expects($this->exactly(2))
            ->method('findBySubject')
            ->willReturnCallback(function ($subjectType, $subjectId) use ($userException, $groupException) {
                if ($subjectType === AuthorizationException::SUBJECT_TYPE_USER) {
                    return [$userException];
                } else {
                    return [$groupException];
                }
            });

        $this->groupManager->method('get')->willReturn($this->user);
        $this->groupManager->method('getUserGroups')->willReturn([$this->group]);

        $result = $this->service->getUserExceptions('test-user');

        $this->assertCount(2, $result);
        // Should be sorted by priority (group exception first with priority 10)
        $this->assertEquals(10, $result[0]->getPriority());
        $this->assertEquals(5, $result[1]->getPriority());

    }//end testGetUserExceptions()


}//end class

