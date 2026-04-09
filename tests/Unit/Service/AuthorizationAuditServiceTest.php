<?php

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\AuthorizationAuditService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Tests for AuthorizationAuditService.
 */
class AuthorizationAuditServiceTest extends TestCase
{
    private AuthorizationAuditService $service;
    private IUserSession&MockObject $userSession;
    private RegisterMapper&MockObject $registerMapper;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->userSession = $this->createMock(IUserSession::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new AuthorizationAuditService(
            $this->userSession,
            $this->registerMapper,
            $this->logger
        );
    }

    private function mockUser(string $uid, string $displayName): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $user->method('getDisplayName')->willReturn($displayName);
        $this->userSession->method('getUser')->willReturn($user);
    }

    public function testLogSchemaAuthorizationChangeCreatesInfoEntry(): void
    {
        $this->mockUser('admin', 'Admin User');

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('[AuthorizationAudit] Schema authorization changed'),
                $this->callback(function (array $context): bool {
                    return $context['event_type'] === 'openregister_authorization'
                        && $context['entity_type'] === 'schema'
                        && $context['entity_id'] === 42
                        && $context['entity_title'] === 'Test Schema'
                        && $context['changed_by_user'] === 'admin'
                        && $context['changed_by_name'] === 'Admin User';
                })
            );

        $this->service->logSchemaAuthorizationChange(
            schemaId: 42,
            schemaTitle: 'Test Schema',
            oldAuthorization: ['read' => ['public']],
            newAuthorization: ['read' => ['public', 'behandelaars']]
        );
    }

    public function testLogRegisterAuthorizationChangeIncludesAffectedCount(): void
    {
        $this->mockUser('admin', 'Admin User');

        $register = $this->createMock(Register::class);
        $register->method('getSchemas')->willReturn([1, 2, 3]);

        $this->registerMapper->method('find')
            ->with(10)
            ->willReturn($register);

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('[AuthorizationAudit] Register authorization changed'),
                $this->callback(function (array $context): bool {
                    return $context['entity_type'] === 'register'
                        && $context['entity_id'] === 10
                        && $context['affected_schema_count'] === 3;
                })
            );

        $this->service->logRegisterAuthorizationChange(
            registerId: 10,
            registerTitle: 'Test Register',
            oldAuthorization: null,
            newAuthorization: ['read' => ['medewerkers']]
        );
    }

    public function testLogRoleDefinitionChangeCreatesInfoEntry(): void
    {
        $this->mockUser('admin', 'Admin User');

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('[AuthorizationAudit] Role definitions changed'),
                $this->callback(function (array $context): bool {
                    return $context['event_type'] === 'openregister_authorization'
                        && $context['entity_type'] === 'register_roles'
                        && $context['entity_id'] === 10;
                })
            );

        $this->service->logRoleDefinitionChange(
            registerId: 10,
            registerTitle: 'Test Register',
            oldRoles: [['name' => 'viewer', 'actions' => ['read']]],
            newRoles: [
                ['name' => 'viewer', 'actions' => ['read']],
                ['name' => 'editor', 'actions' => ['read', 'create', 'update']],
            ]
        );
    }

    public function testLogWithNoUserSessionUsesSystemFallback(): void
    {
        // No user session configured (null user).
        $this->userSession->method('getUser')->willReturn(null);

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('[AuthorizationAudit] Schema authorization changed'),
                $this->callback(function (array $context): bool {
                    return $context['changed_by_user'] === 'system'
                        && $context['changed_by_name'] === 'System';
                })
            );

        $this->service->logSchemaAuthorizationChange(
            schemaId: 1,
            schemaTitle: 'Test',
            oldAuthorization: null,
            newAuthorization: ['read' => ['public']]
        );
    }
}
