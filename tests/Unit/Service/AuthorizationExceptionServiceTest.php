<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\AuthorizationExceptionService;
use OCA\OpenRegister\Db\AuthorizationExceptionMapper;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use OCP\IUserSession;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use OCP\ICacheFactory;

/**
 * Test class for AuthorizationExceptionService
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://www.OpenRegister.app
 */
class AuthorizationExceptionServiceTest extends TestCase
{
    private AuthorizationExceptionService $authorizationExceptionService;
    private $mapper;
    private $userSession;
    private $groupManager;
    private $logger;
    private $cacheFactory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = $this->createMock(AuthorizationExceptionMapper::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->cacheFactory->method('createDistributed')->willReturn($this->createMock(\OCP\IMemcache::class));

        $this->authorizationExceptionService = new AuthorizationExceptionService(
            $this->mapper,
            $this->userSession,
            $this->groupManager,
            $this->logger,
            $this->cacheFactory
        );
    }

    /**
     * Test constructor
     */
    public function testConstructor(): void
    {
        $this->assertInstanceOf(AuthorizationExceptionService::class, $this->authorizationExceptionService);
    }

    /**
     * Test createException method with valid parameters
     */
    public function testCreateExceptionWithValidParameters(): void
    {
        $user = $this->createMock(\OCP\IUser::class);
        $user->method('getUID')->willReturn('test-user');
        $this->userSession->method('getUser')->willReturn($user);

        $exception = $this->createMock(\OCA\OpenRegister\Db\AuthorizationException::class);
        $this->mapper->expects($this->once())
            ->method('createException')
            ->willReturn($exception);

        $result = $this->authorizationExceptionService->createException(
            'inclusion',
            'user',
            'test-user',
            'read',
            'schema-uuid',
            'register-uuid',
            'org-uuid',
            1,
            'Test description'
        );

        $this->assertInstanceOf(\OCA\OpenRegister\Db\AuthorizationException::class, $result);
    }

    /**
     * Test createException method with invalid type
     */
    public function testCreateExceptionWithInvalidType(): void
    {
        $user = $this->createMock(\OCP\IUser::class);
        $user->method('getUID')->willReturn('test-user');
        $this->userSession->method('getUser')->willReturn($user);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid exception type');

        $this->authorizationExceptionService->createException(
            'invalid-type',
            'user',
            'test-user',
            'read'
        );
    }

    /**
     * Test createException method with invalid subject type
     */
    public function testCreateExceptionWithInvalidSubjectType(): void
    {
        $user = $this->createMock(\OCP\IUser::class);
        $user->method('getUID')->willReturn('test-user');
        $this->userSession->method('getUser')->willReturn($user);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid subject type');

        $this->authorizationExceptionService->createException(
            'inclusion',
            'invalid-subject',
            'test-user',
            'read'
        );
    }

    /**
     * Test createException method with invalid action
     */
    public function testCreateExceptionWithInvalidAction(): void
    {
        $user = $this->createMock(\OCP\IUser::class);
        $user->method('getUID')->willReturn('test-user');
        $this->userSession->method('getUser')->willReturn($user);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid action');

        $this->authorizationExceptionService->createException(
            'inclusion',
            'user',
            'test-user',
            'invalid-action'
        );
    }

    /**
     * Test createException method with non-existent group
     */
    public function testCreateExceptionWithNonExistentGroup(): void
    {
        $user = $this->createMock(\OCP\IUser::class);
        $user->method('getUID')->willReturn('test-user');
        $this->userSession->method('getUser')->willReturn($user);

        $this->groupManager->method('groupExists')
            ->with('non-existent-group')
            ->willReturn(false);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Group does not exist');

        $this->authorizationExceptionService->createException(
            'inclusion',
            'group',
            'non-existent-group',
            'read'
        );
    }

    /**
     * Test createException method without authenticated user
     */
    public function testCreateExceptionWithoutAuthenticatedUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No authenticated user to create authorization exception');

        $this->authorizationExceptionService->createException(
            'inclusion',
            'user',
            'test-user',
            'read'
        );
    }

    /**
     * Test evaluateUserPermission method
     */
    public function testEvaluateUserPermission(): void
    {
        $exception = $this->createMock(\OCA\OpenRegister\Db\AuthorizationException::class);
        $exception->method('isExclusion')->willReturn(false);
        $exception->method('isInclusion')->willReturn(true);

        $this->mapper->method('findApplicableExceptions')
            ->willReturn([$exception]);

        $result = $this->authorizationExceptionService->evaluateUserPermission(
            'test-user',
            'read',
            'schema-uuid',
            'register-uuid',
            'org-uuid'
        );

        $this->assertTrue($result);
    }

    /**
     * Test getUserExceptions method
     */
    public function testGetUserExceptions(): void
    {
        $exception = $this->createMock(\OCA\OpenRegister\Db\AuthorizationException::class);

        $this->mapper->method('findBySubject')
            ->willReturn([$exception]);

        $result = $this->authorizationExceptionService->getUserExceptions('test-user');

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertInstanceOf(\OCA\OpenRegister\Db\AuthorizationException::class, $result[0]);
    }

    /**
     * Test userHasExceptions method
     */
    public function testUserHasExceptions(): void
    {
        $exception = $this->createMock(\OCA\OpenRegister\Db\AuthorizationException::class);

        $this->mapper->method('findBySubject')
            ->willReturn([$exception]);

        $result = $this->authorizationExceptionService->userHasExceptions('test-user');

        $this->assertTrue($result);
    }

    /**
     * Test userHasExceptions method with no exceptions
     */
    public function testUserHasExceptionsWithNoExceptions(): void
    {
        $this->mapper->method('findBySubject')
            ->willReturn([]);

        $result = $this->authorizationExceptionService->userHasExceptions('test-user');

        $this->assertFalse($result);
    }

    /**
     * Test getPerformanceMetrics method
     */
    public function testGetPerformanceMetrics(): void
    {
        $result = $this->authorizationExceptionService->getPerformanceMetrics();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('memory_cache_entries', $result);
        $this->assertArrayHasKey('group_cache_entries', $result);
        $this->assertArrayHasKey('distributed_cache_available', $result);
        $this->assertArrayHasKey('cache_factory_available', $result);
    }
}
