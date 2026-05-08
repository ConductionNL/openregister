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
 * Unit tests for MagicRbacHandler::hasPermission().
 *
 * Verifies that the PHP-side permission-check path:
 *   - short-circuits on admin / owner bypass (no ConditionMatcher call)
 *   - grants access for simple `public` / group-match rules without delegation
 *   - delegates conditional rule evaluation (rules with a `match` clause) to
 *     {@see ConditionMatcher}, matching the behaviour of PermissionHandler
 *     (ADR-011 — single PHP-side evaluator).
 *
 * These tests are the parity counterpart to PermissionHandlerRbacTest's
 * ConditionMatcher-delegation tests: the same rule grammar must produce the
 * same verdict in both handlers because both pipe through the same service.
 *
 * The SQL-emission path (applyRbacFilters / buildRbacConditionsSql) is
 * covered by the integration test at tests/Db/MagicRbacHandlerIntegrationTest.php.
 */
class MagicRbacHandlerTest extends TestCase
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

        // MagicRbacHandler delegates the inheritFromPublic cascade to
        // PermissionHandler via the container. The default of true mirrors
        // pre-change behaviour for tests that don't care about the flag.
        $this->permissionHandler->method('resolveInheritFromPublic')->willReturn(true);
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

    public function testAdminBypassShortCircuitsBeforeConditionMatcher(): void
    {
        $this->mockUser('admin1', ['admin']);
        $schema = $this->createSchema([
            'read' => [['group' => 'behandelaars', 'match' => ['status' => 'open']]],
        ]);

        $this->conditionMatcher
            ->expects($this->never())
            ->method('objectMatchesConditions');

        $this->assertTrue($this->handler->hasPermission($schema, 'read'));
    }

    public function testOwnerBypassShortCircuitsBeforeConditionMatcher(): void
    {
        $this->mockUser('jan', ['medewerkers']);
        $schema = $this->createSchema([
            'read' => [['group' => 'behandelaars', 'match' => ['status' => 'open']]],
        ]);

        $this->conditionMatcher
            ->expects($this->never())
            ->method('objectMatchesConditions');

        $this->assertTrue(
            $this->handler->hasPermission(
                schema: $schema,
                action: 'read',
                objectOwner: 'jan',
                objectData: ['status' => 'closed']
            )
        );
    }

    public function testNoAuthorizationGrantsOpenAccess(): void
    {
        $this->mockUser('jan', ['medewerkers']);
        $schema = $this->createSchema(null);

        $this->conditionMatcher
            ->expects($this->never())
            ->method('objectMatchesConditions');

        $this->assertTrue($this->handler->hasPermission($schema, 'read'));
    }

    public function testActionNotConfiguredGrantsOpenAccess(): void
    {
        $this->mockUser('jan', ['medewerkers']);
        $schema = $this->createSchema([
            'read' => ['behandelaars'],
            // 'update' deliberately omitted.
        ]);

        $this->conditionMatcher
            ->expects($this->never())
            ->method('objectMatchesConditions');

        $this->assertTrue($this->handler->hasPermission($schema, 'update'));
    }

    public function testSimplePublicRuleGrantsAnonymousAccessWithoutDelegation(): void
    {
        $this->mockUser(null, []);
        $schema = $this->createSchema(['read' => ['public']]);

        $this->conditionMatcher
            ->expects($this->never())
            ->method('objectMatchesConditions');

        $this->assertTrue($this->handler->hasPermission($schema, 'read'));
    }

    public function testSimpleGroupRuleGrantsMemberWithoutDelegation(): void
    {
        $this->mockUser('jan', ['behandelaars']);
        $schema = $this->createSchema(['read' => ['behandelaars']]);

        $this->conditionMatcher
            ->expects($this->never())
            ->method('objectMatchesConditions');

        $this->assertTrue($this->handler->hasPermission($schema, 'read'));
    }

    public function testSimpleGroupRuleDeniesNonMemberWithoutDelegation(): void
    {
        $this->mockUser('jan', ['kcc-team']);
        $schema = $this->createSchema(['read' => ['behandelaars']]);

        $this->conditionMatcher
            ->expects($this->never())
            ->method('objectMatchesConditions');

        $this->assertFalse($this->handler->hasPermission($schema, 'read'));
    }

    public function testConditionalPublicRuleWithMatchDelegatesToConditionMatcher(): void
    {
        $this->mockUser(null, []);
        $schema = $this->createSchema([
            'read' => [
                ['group' => 'public', 'match' => ['publishDate' => ['$lte' => '$now']]],
            ],
        ]);

        $this->conditionMatcher
            ->expects($this->once())
            ->method('objectMatchesConditions')
            ->with(
                ['publishDate' => '2025-01-01'],
                ['publishDate' => ['$lte' => '$now']]
            )
            ->willReturn(true);

        $this->assertTrue(
            $this->handler->hasPermission(
                schema: $schema,
                action: 'read',
                objectOwner: null,
                objectData: ['publishDate' => '2025-01-01']
            )
        );
    }

    public function testConditionalRuleReturnsFalseWhenConditionMatcherReturnsFalse(): void
    {
        $this->mockUser(null, []);
        $schema = $this->createSchema([
            'read' => [
                ['group' => 'public', 'match' => ['publishDate' => ['$lte' => '$now']]],
            ],
        ]);

        $this->conditionMatcher
            ->expects($this->once())
            ->method('objectMatchesConditions')
            ->willReturn(false);

        $this->assertFalse(
            $this->handler->hasPermission(
                schema: $schema,
                action: 'read',
                objectOwner: null,
                objectData: ['publishDate' => '2099-01-01']
            )
        );
    }

    public function testConditionalRuleSkippedWhenUserDoesNotQualifyForGroup(): void
    {
        $this->mockUser('jan', ['kcc-team']);
        $schema = $this->createSchema([
            'read' => [
                ['group' => 'behandelaars', 'match' => ['status' => 'open']],
            ],
        ]);

        // User is not in 'behandelaars' → rule is skipped, no delegation, no access.
        $this->conditionMatcher
            ->expects($this->never())
            ->method('objectMatchesConditions');

        $this->assertFalse(
            $this->handler->hasPermission(
                schema: $schema,
                action: 'read',
                objectOwner: null,
                objectData: ['status' => 'open']
            )
        );
    }

    public function testConditionalRuleGrantsAccessWhenNoObjectDataSupplied(): void
    {
        // Without object data the match cannot be evaluated — the rule grants
        // group-level access (same semantics as the removed private matcher).
        $this->mockUser('jan', ['behandelaars']);
        $schema = $this->createSchema([
            'read' => [
                ['group' => 'behandelaars', 'match' => ['status' => 'open']],
            ],
        ]);

        $this->conditionMatcher
            ->expects($this->never())
            ->method('objectMatchesConditions');

        $this->assertTrue($this->handler->hasPermission($schema, 'read'));
    }

    public function testConditionalRuleWithEmptyMatchActsAsPlainGroupMatch(): void
    {
        $this->mockUser('jan', ['behandelaars']);
        $schema = $this->createSchema([
            'read' => [['group' => 'behandelaars', 'match' => []]],
        ]);

        $this->conditionMatcher
            ->expects($this->never())
            ->method('objectMatchesConditions');

        $this->assertTrue(
            $this->handler->hasPermission(
                schema: $schema,
                action: 'read',
                objectOwner: null,
                objectData: ['status' => 'anything']
            )
        );
    }

    public function testInOperatorDelegation(): void
    {
        $this->mockUser('jan', ['behandelaars']);
        $schema = $this->createSchema([
            'read' => [
                ['group' => 'behandelaars', 'match' => ['status' => ['$in' => ['open', 'review']]]],
            ],
        ]);

        $this->conditionMatcher
            ->expects($this->once())
            ->method('objectMatchesConditions')
            ->with(
                $this->anything(),
                ['status' => ['$in' => ['open', 'review']]]
            )
            ->willReturn(true);

        $this->assertTrue(
            $this->handler->hasPermission(
                schema: $schema,
                action: 'read',
                objectOwner: null,
                objectData: ['status' => 'open']
            )
        );
    }

    public function testUserIdVariableDelegation(): void
    {
        $this->mockUser('jan', ['medewerkers']);
        $schema = $this->createSchema([
            'read' => [
                ['group' => 'medewerkers', 'match' => ['assignedTo' => '$userId']],
            ],
        ]);

        $this->conditionMatcher
            ->expects($this->once())
            ->method('objectMatchesConditions')
            ->willReturn(true);

        $this->assertTrue(
            $this->handler->hasPermission(
                schema: $schema,
                action: 'read',
                objectOwner: null,
                objectData: ['assignedTo' => 'jan']
            )
        );
    }
}
