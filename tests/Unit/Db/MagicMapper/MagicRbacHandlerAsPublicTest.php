<?php

declare(strict_types=1);

namespace Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\Schema;
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
 * Unit tests for the `_rbac_as_public` primitive in {@see MagicRbacHandler}.
 *
 * Verifies the SQL-emission path (`buildRbacConditionsSql`) under
 * `$asPublic = true`:
 *   - admin bypass is skipped
 *   - `_owner = <userId>` OR-in condition is not emitted
 *   - public-group `read` rules are evaluated
 *   - `authenticated`-only rules do not grant access
 *   - `$asPublic = false` (default) preserves the pre-change behaviour
 *   - admin caller with `$asPublic = true` produces the same conditions
 *     structure as an anonymous caller
 *
 * @spec openspec/changes/rbac-as-public-toggle/specs/rbac-as-public-toggle/spec.md#RBA-PUBLIC-001
 */
class MagicRbacHandlerAsPublicTest extends TestCase
{
    private MagicRbacHandler $handler;
    private IUserSession&MockObject $userSession;
    private IGroupManager&MockObject $groupManager;
    private IUserManager&MockObject $userManager;
    private IAppConfig&MockObject $appConfig;
    private ConditionMatcher&MockObject $conditionMatcher;
    private ContainerInterface&MockObject $container;
    private LoggerInterface&MockObject $logger;
    private PermissionHandler&MockObject $permissionHandler;

    protected function setUp(): void
    {
        $this->userSession       = $this->createMock(IUserSession::class);
        $this->groupManager      = $this->createMock(IGroupManager::class);
        $this->userManager       = $this->createMock(IUserManager::class);
        $this->appConfig         = $this->createMock(IAppConfig::class);
        $this->conditionMatcher  = $this->createMock(ConditionMatcher::class);
        $this->container         = $this->createMock(ContainerInterface::class);
        $this->logger            = $this->createMock(LoggerInterface::class);
        $this->permissionHandler = $this->createMock(PermissionHandler::class);

        $this->permissionHandler->method('resolveInheritFromPublic')->willReturn(true);
        // MagicRbacHandler::resolveSchemaAuthorization delegates here — echo
        // the schema's own authorization array so the SQL builder can walk rules.
        $this->permissionHandler->method('resolveAuthorization')->willReturnCallback(
            fn (Schema $schema) => $schema->getAuthorization()
        );
        $this->container->method('get')->willReturnCallback(
            fn (string $class) => $class === PermissionHandler::class ? $this->permissionHandler : null
        );

        $this->handler = new MagicRbacHandler(
            $this->userSession,
            $this->groupManager,
            $this->userManager,
            $this->appConfig,
            $this->conditionMatcher,
            $this->container,
            $this->logger
        );
    }

    private function mockUser(?string $uid, array $groups): void
    {
        if ($uid === null) {
            $this->userSession->method('getUser')->willReturn(null);
            return;
        }

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('getUserGroupIds')->willReturn($groups);
    }

    private function createSchema(?array $authorization): Schema
    {
        $schema = new Schema();
        $schema->setId(1);
        $schema->setAuthorization($authorization);
        $schema->setTitle('Test Schema');
        return $schema;
    }

    public function testAsPublicTrueIgnoresAdminGroupBypass(): void
    {
        // Session says admin, but $asPublic = true should skip the bypass.
        $this->mockUser('adminuser', ['admin']);
        $schema = $this->createSchema([
            'read' => [
                ['group' => 'public', 'match' => ['status' => 'published']],
            ],
        ]);

        $result = $this->handler->buildRbacConditionsSql($schema, 'read', true);

        // Admin bypass would return ['bypass' => true, 'conditions' => []].
        // Under asPublic=true we expect NOT bypass — public-group rule is evaluated.
        $this->assertFalse($result['bypass'], 'Admin bypass MUST be skipped when asPublic=true');
    }

    public function testAsPublicTrueSkipsOwnerOrInCondition(): void
    {
        // Authenticated non-admin: default behaviour would emit `_owner = '<userId>'`.
        $this->mockUser('jan', ['medewerkers']);
        $schema = $this->createSchema([
            'read' => [
                ['group' => 'public', 'match' => ['status' => 'published']],
            ],
        ]);

        $result = $this->handler->buildRbacConditionsSql($schema, 'read', true);

        // Assert we're not in bypass (otherwise conditions could be legitimately empty).
        $this->assertFalse($result['bypass'], 'Guard: not bypass, so conditions array is meaningful');

        // No condition should reference `_owner = 'jan'` because $userId is forced to null.
        $joined = implode(' | ', $result['conditions']);
        $this->assertStringNotContainsString(
            "_owner = 'jan'",
            $joined,
            '_owner OR-in condition MUST NOT be emitted under asPublic=true'
        );
    }

    public function testAsPublicTrueEvaluatesPublicGroupRule(): void
    {
        // Authenticated caller, but public rule is still evaluated because asPublic
        // forces `$userGroups = []` and `$userId = null` — the anon path.
        $this->mockUser('jan', ['medewerkers']);
        $schema = $this->createSchema([
            'read' => [
                ['group' => 'public', 'match' => ['publicatiedatum' => ['$lte' => '$now']]],
            ],
        ]);

        $result = $this->handler->buildRbacConditionsSql($schema, 'read', true);

        $this->assertFalse($result['bypass']);
        $this->assertNotEmpty(
            $result['conditions'],
            'Public group rule MUST produce a SQL condition under asPublic=true'
        );
    }

    public function testAsPublicTrueDeniesAuthenticatedOnlyRule(): void
    {
        // Authenticated session with a schema that only grants access to
        // `authenticated` — under asPublic=true, $userId is null so the rule
        // must NOT grant.
        $this->mockUser('jan', ['medewerkers']);
        $schema = $this->createSchema([
            'read' => ['authenticated'],
        ]);

        $result = $this->handler->buildRbacConditionsSql($schema, 'read', true);

        // No conditions means deny all (per the caller `buildRbacConditionSql`
        // which turns empty conditions into `1=0`).
        $this->assertFalse($result['bypass']);
        $this->assertSame(
            [],
            $result['conditions'],
            'authenticated-only rule MUST NOT grant access under asPublic=true'
        );
    }

    public function testAsPublicFalseBehavesAsBeforeForAdminBypass(): void
    {
        // Backwards-compat check: admin caller with $asPublic = false (default)
        // still bypasses.
        $this->mockUser('adminuser', ['admin']);
        $schema = $this->createSchema([
            'read' => [
                ['group' => 'public', 'match' => ['status' => 'published']],
            ],
        ]);

        $result = $this->handler->buildRbacConditionsSql($schema, 'read', false);

        $this->assertTrue($result['bypass'], 'Admin bypass MUST still apply when asPublic=false');
        $this->assertSame([], $result['conditions']);
    }

    public function testAsPublicTrueForAdminMatchesAnonForSameQuery(): void
    {
        // Contract: admin+asPublic produces the same result shape as anon+default.
        // We call twice — once with mocked admin session + asPublic=true, once
        // with anon session + asPublic=false — and assert both return the same
        // (bypass, conditions) structure. This is the strongest single invariant.
        $schema = $this->createSchema([
            'read' => [
                ['group' => 'public', 'match' => ['publicatiedatum' => ['$lte' => '$now']]],
            ],
        ]);

        // Admin + asPublic=true.
        $adminHandler = $this->newHandlerWithUser('admin1', ['admin']);
        $adminResult = $adminHandler->buildRbacConditionsSql($schema, 'read', true);

        // Anon + asPublic=false (default).
        $anonHandler = $this->newHandlerWithUser(null, []);
        $anonResult = $anonHandler->buildRbacConditionsSql($schema, 'read', false);

        $this->assertSame(
            $anonResult['bypass'],
            $adminResult['bypass'],
            'bypass status MUST match between admin+asPublic=true and anon default'
        );
        $this->assertSame(
            $anonResult['conditions'],
            $adminResult['conditions'],
            'condition SQL MUST be identical between admin+asPublic=true and anon default'
        );
    }

    /**
     * Build a fresh MagicRbacHandler with the given session context.
     * Used by the contract test to compare two distinct callers.
     */
    private function newHandlerWithUser(?string $uid, array $groups): MagicRbacHandler
    {
        $userSession = $this->createMock(IUserSession::class);
        $groupManager = $this->createMock(IGroupManager::class);

        if ($uid === null) {
            $userSession->method('getUser')->willReturn(null);
        } else {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($uid);
            $userSession->method('getUser')->willReturn($user);
            $groupManager->method('getUserGroupIds')->willReturn($groups);
        }

        return new MagicRbacHandler(
            $userSession,
            $groupManager,
            $this->userManager,
            $this->appConfig,
            $this->conditionMatcher,
            $this->container,
            $this->logger
        );
    }
}
