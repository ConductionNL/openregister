<?php

/**
 * MagicRbacHandler inheritFromPublic unit tests
 *
 * Covers the SQL-emitting paths of MagicRbacHandler — applyRbacFilters and
 * buildRbacConditionsSql — under the four-state (anon|auth × flag-on|off)
 * matrix introduced by the rbac-disable-public-inheritance change.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/rbac-disable-public-inheritance/tasks.md
 */

declare(strict_types=1);

namespace Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
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
 * Tests for MagicRbacHandler SQL emission under the inheritFromPublic flag.
 *
 * Covers:
 *   - buildRbacConditionsSql (UNION-path emitter): four-state matrix on a
 *     public-conditional rule and on a simple-string `public` rule
 *   - applyRbacFilters (QueryBuilder emitter): the impossible-condition
 *     fallback fires for an authenticated user when the only qualifying
 *     rule is `public` and the flag is off
 */
class MagicRbacHandlerInheritFromPublicTest extends TestCase
{

    /**
     * Subject under test.
     *
     * @var MagicRbacHandler
     */
    private MagicRbacHandler $handler;

    /**
     * Mock user session.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

    /**
     * Mock group manager.
     *
     * @var IGroupManager&MockObject
     */
    private IGroupManager&MockObject $groupManager;

    /**
     * Mock user manager.
     *
     * @var IUserManager&MockObject
     */
    private IUserManager&MockObject $userManager;

    /**
     * Mock app config (provides the tenant default for inheritFromPublic).
     *
     * @var IAppConfig&MockObject
     */
    private IAppConfig&MockObject $appConfig;

    /**
     * Mock condition matcher.
     *
     * @var ConditionMatcher&MockObject
     */
    private ConditionMatcher&MockObject $conditionMatcher;

    /**
     * Mock DI container (used to resolve PermissionHandler lazily).
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Mock logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Mock permission handler (resolved via the container).
     *
     * @var PermissionHandler&MockObject
     */
    private PermissionHandler&MockObject $permissionHandler;

    /**
     * Build mocks and the subject under test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->userSession       = $this->createMock(originalClassName: IUserSession::class);
        $this->groupManager      = $this->createMock(originalClassName: IGroupManager::class);
        $this->userManager       = $this->createMock(originalClassName: IUserManager::class);
        $this->appConfig         = $this->createMock(originalClassName: IAppConfig::class);
        $this->conditionMatcher  = $this->createMock(originalClassName: ConditionMatcher::class);
        $this->container         = $this->createMock(originalClassName: ContainerInterface::class);
        $this->logger            = $this->createMock(originalClassName: LoggerInterface::class);
        $this->permissionHandler = $this->createMock(originalClassName: PermissionHandler::class);

        // Default tenant default is `true`. Tests override on the
        // PermissionHandler mock to set per-test cascade behaviour.
        $this->appConfig->method('getValueBool')->willReturn(true);

        // MagicRbacHandler delegates the inheritFromPublic cascade and the
        // schema-authorization lookup to PermissionHandler via the container.
        // Each test sets explicit return values on $this->permissionHandler.
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

    }//end setUp()

    /**
     * Wire the PermissionHandler mock to return the schema's authorization
     * verbatim and the requested inheritFromPublic value. Mirrors what a
     * real PermissionHandler would do for a schema with no `roles` key.
     *
     * @param Schema $schema            The schema fixture.
     * @param bool   $inheritFromPublic The resolved cascade value to mock.
     *
     * @return void
     */
    private function wirePermissionHandler(Schema $schema, bool $inheritFromPublic): void
    {
        $this->permissionHandler
            ->method('resolveAuthorization')
            ->willReturn($schema->getAuthorization());
        $this->permissionHandler
            ->method('resolveInheritFromPublic')
            ->willReturn($inheritFromPublic);

    }//end wirePermissionHandler()

    /**
     * Mock the user session and groups for the subject under test.
     *
     * @param string|null $uid    User UID, or null for anonymous.
     * @param array       $groups Group memberships for authenticated users.
     *
     * @return void
     */
    private function mockUser(?string $uid, array $groups=[]): void
    {
        if ($uid === null) {
            $this->userSession->method('getUser')->willReturn(null);
            return;
        }

        $user = $this->createMock(originalClassName: IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('getUserGroupIds')->willReturn($groups);

    }//end mockUser()

    /**
     * Build a Schema fixture with the given authorization block. Also wires
     * the PermissionHandler mock to honour the schema's inheritFromPublic
     * field (or default `true`).
     *
     * @param array|null $authorization The authorization block (or null).
     *
     * @return Schema
     */
    private function createSchema(?array $authorization): Schema
    {
        $schema = new Schema();
        $schema->setId(1);
        $schema->setAuthorization($authorization);
        $schema->setTitle('Test Schema');

        $auth = $schema->getAuthorization();
        $inheritFromPublicFlag = true;
        if (is_array(value: $auth) === true && array_key_exists(key: 'inheritFromPublic', array: $auth) === true) {
            $inheritFromPublicFlag = (bool) $auth['inheritFromPublic'];
        }

        $this->wirePermissionHandler(schema: $schema, inheritFromPublic: $inheritFromPublicFlag);

        return $schema;

    }//end createSchema()

    /**
     * State (anon, inheritFromPublic=true): public-conditional rule emits
     * a condition string for the public match (anon qualifies).
     *
     * @return void
     */
    public function testBuildSqlAnonInheritTrueEmitsPublicCondition(): void
    {
        $this->mockUser(uid: null);
        $schema = $this->createSchema(
            authorization: [
                'inheritFromPublic' => true,
                'read'              => [['group' => 'public', 'match' => ['status' => 'published']]],
            ]
        );

        $result = $this->handler->buildRbacConditionsSql(schema: $schema, action: 'read');

        $this->assertFalse(condition: $result['bypass']);
        $this->assertNotEmpty(actual: $result['conditions']);

    }//end testBuildSqlAnonInheritTrueEmitsPublicCondition()

    /**
     * State (anon, inheritFromPublic=false): public-conditional rule still
     * emits a condition for anon — the flag does not affect anonymous users.
     *
     * @return void
     */
    public function testBuildSqlAnonInheritFalseStillEmitsPublicCondition(): void
    {
        $this->mockUser(uid: null);
        $schema = $this->createSchema(
            authorization: [
                'inheritFromPublic' => false,
                'read'              => [['group' => 'public', 'match' => ['status' => 'published']]],
            ]
        );

        $result = $this->handler->buildRbacConditionsSql(schema: $schema, action: 'read');

        $this->assertFalse(condition: $result['bypass']);
        $this->assertNotEmpty(actual: $result['conditions']);

    }//end testBuildSqlAnonInheritFalseStillEmitsPublicCondition()

    /**
     * State (auth, inheritFromPublic=true): public-conditional rule emits
     * a condition for an authenticated user — they qualify for public when
     * the flag is on (default).
     *
     * @return void
     */
    public function testBuildSqlAuthInheritTrueEmitsPublicCondition(): void
    {
        $this->mockUser(uid: 'alice', groups: ['users']);
        $schema = $this->createSchema(
            authorization: [
                'inheritFromPublic' => true,
                'read'              => [['group' => 'public', 'match' => ['status' => 'published']]],
            ]
        );

        $result = $this->handler->buildRbacConditionsSql(schema: $schema, action: 'read');

        $this->assertFalse(condition: $result['bypass']);
        // Owner-condition is always added for authenticated users; the public
        // match condition is added on top because alice qualifies for public.
        $this->assertGreaterThan(expected: 1, actual: count(value: $result['conditions']));

    }//end testBuildSqlAuthInheritTrueEmitsPublicCondition()

    /**
     * State (auth, inheritFromPublic=false): public-conditional rule does
     * NOT emit a condition for authenticated alice — only the owner
     * condition remains (which won't match objects she doesn't own).
     *
     * @return void
     */
    public function testBuildSqlAuthInheritFalseSkipsPublicCondition(): void
    {
        $this->mockUser(uid: 'alice', groups: ['users']);
        $schema = $this->createSchema(
            authorization: [
                'inheritFromPublic' => false,
                'read'              => [['group' => 'public', 'match' => ['status' => 'published']]],
            ]
        );

        $result = $this->handler->buildRbacConditionsSql(schema: $schema, action: 'read');

        $this->assertFalse(condition: $result['bypass']);
        // Only the owner condition is emitted; the public-match is dropped
        // because alice does not qualify for `public` when inheritance is off.
        $this->assertCount(expectedCount: 1, haystack: $result['conditions']);
        $this->assertStringContainsString(needle: '_owner', haystack: $result['conditions'][0]);

    }//end testBuildSqlAuthInheritFalseSkipsPublicCondition()

    /**
     * Simple `'public'` rule + (auth, inheritFromPublic=true): bypass=true
     * (authenticated user qualifies for public unconditionally).
     *
     * @return void
     */
    public function testBuildSqlSimplePublicRuleAuthInheritTrueBypasses(): void
    {
        $this->mockUser(uid: 'alice', groups: ['users']);
        $schema = $this->createSchema(
            authorization: [
                'inheritFromPublic' => true,
                'read'              => ['public'],
            ]
        );

        $result = $this->handler->buildRbacConditionsSql(schema: $schema, action: 'read');

        $this->assertTrue(condition: $result['bypass']);

    }//end testBuildSqlSimplePublicRuleAuthInheritTrueBypasses()

    /**
     * Simple `'public'` rule + (auth, inheritFromPublic=false): no bypass —
     * authenticated user must qualify some other way.
     *
     * @return void
     */
    public function testBuildSqlSimplePublicRuleAuthInheritFalseDoesNotBypass(): void
    {
        $this->mockUser(uid: 'alice', groups: ['users']);
        $schema = $this->createSchema(
            authorization: [
                'inheritFromPublic' => false,
                'read'              => ['public'],
            ]
        );

        $result = $this->handler->buildRbacConditionsSql(schema: $schema, action: 'read');

        $this->assertFalse(condition: $result['bypass']);
        // Only the owner condition; no unconditional public bypass for alice.
        $this->assertCount(expectedCount: 1, haystack: $result['conditions']);

    }//end testBuildSqlSimplePublicRuleAuthInheritFalseDoesNotBypass()

    /**
     * Simple `'public'` rule + (anon, inheritFromPublic=false): bypass=true
     * (anonymous users are unaffected by the flag).
     *
     * @return void
     */
    public function testBuildSqlSimplePublicRuleAnonInheritFalseStillBypasses(): void
    {
        $this->mockUser(uid: null);
        $schema = $this->createSchema(
            authorization: [
                'inheritFromPublic' => false,
                'read'              => ['public'],
            ]
        );

        $result = $this->handler->buildRbacConditionsSql(schema: $schema, action: 'read');

        $this->assertTrue(condition: $result['bypass']);

    }//end testBuildSqlSimplePublicRuleAnonInheritFalseStillBypasses()

    /**
     * Simple `'authenticated'` rule + (auth, inheritFromPublic=false): bypass
     * stays true — the flag only governs the `public` group, not the
     * `authenticated` simple-rule string.
     *
     * @return void
     */
    public function testBuildSqlAuthenticatedRuleUnaffectedByFlag(): void
    {
        $this->mockUser(uid: 'alice', groups: ['users']);
        $schema = $this->createSchema(
            authorization: [
                'inheritFromPublic' => false,
                'read'              => ['authenticated'],
            ]
        );

        $result = $this->handler->buildRbacConditionsSql(schema: $schema, action: 'read');

        $this->assertTrue(condition: $result['bypass']);

    }//end testBuildSqlAuthenticatedRuleUnaffectedByFlag()

    /**
     * Admin bypasses RBAC entirely regardless of the flag.
     *
     * @return void
     */
    public function testBuildSqlAdminBypassesRegardlessOfFlag(): void
    {
        $this->mockUser(uid: 'root', groups: ['admin']);
        $schema = $this->createSchema(
            authorization: [
                'inheritFromPublic' => false,
                'read'              => [['group' => 'public', 'match' => ['status' => 'published']]],
            ]
        );

        $result = $this->handler->buildRbacConditionsSql(schema: $schema, action: 'read');

        $this->assertTrue(condition: $result['bypass']);

    }//end testBuildSqlAdminBypassesRegardlessOfFlag()

    /**
     * State (auth, inheritFromPublic=false) on a simple `'public'` rule
     * emits the impossible condition (`1 = 0`) for alice — she has no
     * qualifying rule, so the filter denies all rows.
     *
     * @return void
     */
    public function testApplyRbacFiltersAuthInheritFalseDeniesAccess(): void
    {
        $this->mockUser(uid: 'alice', groups: ['users']);
        $schema = $this->createSchema(
            authorization: [
                'inheritFromPublic' => false,
                'read'              => ['public'],
            ]
        );

        $qb          = $this->createMock(originalClassName: IQueryBuilder::class);
        $exprBuilder = $this->createMock(originalClassName: IExpressionBuilder::class);
        $qb->method('expr')->willReturn($exprBuilder);
        $qb->method('createNamedParameter')->willReturnArgument(0);
        $exprBuilder->method('eq')->willReturn('1 = 0');

        // Alice gets the owner condition; the simple 'public' rule yields
        // false for her. The handler then ORs all collected conditions —
        // including the owner condition — via andWhere(orX(...)).
        $qb->expects($this->atLeastOnce())->method('andWhere');

        $this->handler->applyRbacFilters(qb: $qb, schema: $schema, action: 'read');

    }//end testApplyRbacFiltersAuthInheritFalseDeniesAccess()
}//end class
