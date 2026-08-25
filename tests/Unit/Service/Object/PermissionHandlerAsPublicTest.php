<?php

declare(strict_types=1);

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the `$_rbacAsPublic` parameter on {@see PermissionHandler}.
 *
 * Verifies that:
 *   - `hasPermission($_rbacAsPublic=true)` routes to the public-group check
 *     regardless of the caller's session, mirroring the anonymous path
 *   - admin group membership does NOT bypass under `$_rbacAsPublic=true`
 *   - owner grant does NOT apply under `$_rbacAsPublic=true`
 *   - `authenticated`-only rules do NOT grant access under `$_rbacAsPublic=true`
 *   - default behavior (`$_rbacAsPublic=false`) is preserved
 *
 * @spec openspec/changes/rbac-as-public-toggle/specs/rbac-as-public-toggle/spec.md#RBA-PUBLIC-006
 */
class PermissionHandlerAsPublicTest extends TestCase
{
    private PermissionHandler $handler;
    private IUserSession&MockObject $userSession;
    private IUserManager&MockObject $userManager;
    private IGroupManager&MockObject $groupManager;
    private SchemaMapper&MockObject $schemaMapper;
    private MagicMapper&MockObject $objectEntityMapper;
    private ConditionMatcher&MockObject $conditionMatcher;
    private IAppConfig&MockObject $appConfig;
    private LoggerInterface&MockObject $logger;
    private ContainerInterface&MockObject $container;

    protected function setUp(): void
    {
        $this->userSession        = $this->createMock(IUserSession::class);
        $this->userManager        = $this->createMock(IUserManager::class);
        $this->groupManager       = $this->createMock(IGroupManager::class);
        $this->schemaMapper       = $this->createMock(SchemaMapper::class);
        $this->objectEntityMapper = $this->createMock(MagicMapper::class);
        $this->conditionMatcher   = $this->createMock(ConditionMatcher::class);
        $this->appConfig          = $this->createMock(IAppConfig::class);
        $this->logger             = $this->createMock(LoggerInterface::class);
        $this->container          = $this->createMock(ContainerInterface::class);

        $this->handler = new PermissionHandler(
            $this->userSession,
            $this->userManager,
            $this->groupManager,
            $this->schemaMapper,
            $this->objectEntityMapper,
            $this->conditionMatcher,
            $this->appConfig,
            $this->logger,
            $this->container
        );
    }

    private function mockAdminSession(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin1');
        $this->userSession->method('getUser')->willReturn($user);
        $this->userManager->method('get')->willReturn($user);
        $this->groupManager->method('getUserGroupIds')->willReturn(['admin']);
    }

    private function createSchema(?array $authorization): Schema
    {
        $schema = new Schema();
        $schema->setId(1);
        $schema->setAuthorization($authorization);
        $schema->setTitle('Test Schema');
        return $schema;
    }

    public function testAsPublicTrueIgnoresAdminForNonPublicObject(): void
    {
        // Admin session; schema has ONLY a conditional public rule.
        // Under asPublic=true the admin bypass MUST be skipped and the public
        // rule evaluated. Because the mock ConditionMatcher returns false
        // (default), the check must return false — an admin sees no more
        // than an anonymous caller on this public-shaped endpoint.
        $this->mockAdminSession();
        $schema = $this->createSchema([
            'read' => [
                ['group' => 'public', 'match' => ['status' => 'published']],
            ],
        ]);

        $this->conditionMatcher->method('objectMatchesConditions')->willReturn(false);

        $result = $this->handler->hasPermission(
            schema: $schema,
            action: 'read',
            objectOwner: 'someone_else',
            _rbacAsPublic: true
        );

        $this->assertFalse($result, 'Admin bypass MUST be skipped under _rbacAsPublic=true');
    }

    public function testAsPublicTrueMatchesPublicRuleWhenObjectMatches(): void
    {
        // Admin session; conditional public rule where the object DOES match.
        // Under asPublic=true, the check routes to hasGroupPermission('public'),
        // which delegates to ConditionMatcher. If the object matches, return true.
        $this->mockAdminSession();
        $schema = $this->createSchema([
            'read' => [
                ['group' => 'public', 'match' => ['status' => 'published']],
            ],
        ]);

        $this->conditionMatcher->method('objectMatchesConditions')->willReturn(true);

        $result = $this->handler->hasPermission(
            schema: $schema,
            action: 'read',
            _rbacAsPublic: true
        );

        $this->assertTrue($result);
    }

    public function testAsPublicTrueDeniesAuthenticatedOnlyRule(): void
    {
        // Schema grants read only to `authenticated`. Under asPublic=true,
        // $userId is forced to null so the simple-rule "authenticated" check
        // returns false — no access.
        $this->mockAdminSession();
        $schema = $this->createSchema([
            'read' => ['authenticated'],
        ]);

        $result = $this->handler->hasPermission(
            schema: $schema,
            action: 'read',
            _rbacAsPublic: true
        );

        $this->assertFalse($result, 'authenticated-only rule MUST NOT grant under _rbacAsPublic=true');
    }

    public function testAsPublicTrueDoesNotGrantViaOwner(): void
    {
        // Even though the caller is the object owner, asPublic=true forces
        // anonymous context — no owner grant. `objectOwner` is still passed
        // but never consulted because $userId is forced to null in the
        // hasGroupPermission early-route branch.
        $this->mockAdminSession();
        $schema = $this->createSchema([
            'read' => [
                ['group' => 'public', 'match' => ['status' => 'published']],
            ],
        ]);

        $this->conditionMatcher->method('objectMatchesConditions')->willReturn(false);

        $result = $this->handler->hasPermission(
            schema: $schema,
            action: 'read',
            objectOwner: 'admin1',
            _rbacAsPublic: true
        );

        $this->assertFalse($result, 'Owner grant MUST NOT apply under _rbacAsPublic=true');
    }

    public function testAsPublicFalsePreservesAdminBypass(): void
    {
        // Backwards-compat: admin caller with _rbacAsPublic=false still bypasses.
        $this->mockAdminSession();
        $schema = $this->createSchema([
            'read' => [
                ['group' => 'public', 'match' => ['status' => 'published']],
            ],
        ]);

        $result = $this->handler->hasPermission(
            schema: $schema,
            action: 'read'
            // _rbacAsPublic defaults to false
        );

        $this->assertTrue($result, 'Admin bypass MUST still apply when _rbacAsPublic=false');
    }
}
