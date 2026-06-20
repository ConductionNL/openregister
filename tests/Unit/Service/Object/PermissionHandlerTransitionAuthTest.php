<?php

/**
 * PermissionHandler declarative per-transition authorization tests.
 *
 * Verifies {@see PermissionHandler::isTransitionAuthorized()} — the
 * group/role gate enforced on lifecycle transitions that declare an
 * `authorization` list. Covers: authorized vs unauthorized group,
 * admin bypass, anonymous denial, empty-list fail-closed, and the
 * `{ "role": "<name>" }` indirection resolved via the schema's
 * `authorization.roles` assignment.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-declarative-per-transition-authorization-gate
 */

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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \OCA\OpenRegister\Service\Object\PermissionHandler
 */
class PermissionHandlerTransitionAuthTest extends TestCase
{

    private PermissionHandler $handler;

    private IUserManager&MockObject $userManager;

    private IGroupManager&MockObject $groupManager;

    /**
     * Build a PermissionHandler with the minimum mock surface needed
     * for the transition-authorization code path.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $userSession        = $this->createMock(IUserSession::class);
        $this->userManager  = $this->createMock(IUserManager::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $schemaMapper       = $this->createMock(SchemaMapper::class);
        $objectEntityMapper = $this->createMock(MagicMapper::class);
        $conditionMatcher   = $this->createMock(ConditionMatcher::class);
        $logger             = $this->createMock(LoggerInterface::class);
        $container          = $this->createMock(ContainerInterface::class);
        $appConfig          = $this->createMock(IAppConfig::class);

        $this->handler = new PermissionHandler(
            $userSession,
            $this->userManager,
            $this->groupManager,
            $schemaMapper,
            $objectEntityMapper,
            $conditionMatcher,
            $appConfig,
            $logger,
            $container,
            null
        );
    }//end setUp()

    /**
     * Wire the user/group managers so $userId resolves to a user
     * belonging to $groups.
     *
     * @param string        $userId The uid under test.
     * @param array<string> $groups The uid's group memberships.
     *
     * @return void
     */
    private function withUserInGroups(string $userId, array $groups): void
    {
        $user = $this->createMock(IUser::class);
        $this->userManager->method('get')->with($userId)->willReturn($user);
        $this->groupManager->method('getUserGroupIds')->with($user)->willReturn($groups);
    }//end withUserInGroups()

    /**
     * Build a Schema carrying the given authorization block.
     *
     * @param array<string, mixed> $authorization Authorization block.
     *
     * @return Schema
     */
    private function schemaWithAuthorization(array $authorization): Schema
    {
        $schema = new Schema();
        $schema->setAuthorization($authorization);
        return $schema;
    }//end schemaWithAuthorization()

    /**
     * A caller in a listed group is authorized.
     *
     * @return void
     */
    public function testGroupMemberIsAuthorized(): void
    {
        $this->withUserInGroups('alice', ['vergunningverleners', 'users']);
        $schema = $this->schemaWithAuthorization([]);

        $this->assertTrue(
            $this->handler->isTransitionAuthorized(['vergunningverleners'], 'alice', $schema)
        );
    }//end testGroupMemberIsAuthorized()

    /**
     * A caller NOT in any listed group is denied (fail-closed).
     *
     * @return void
     */
    public function testNonMemberIsDenied(): void
    {
        $this->withUserInGroups('bob', ['behandelaars', 'users']);
        $schema = $this->schemaWithAuthorization([]);

        $this->assertFalse(
            $this->handler->isTransitionAuthorized(['vergunningverleners'], 'bob', $schema)
        );
    }//end testNonMemberIsDenied()

    /**
     * The admin group bypasses every transition gate.
     *
     * @return void
     */
    public function testAdminAlwaysAuthorized(): void
    {
        $this->withUserInGroups('root', ['admin']);
        $schema = $this->schemaWithAuthorization([]);

        $this->assertTrue(
            $this->handler->isTransitionAuthorized(['vergunningverleners'], 'root', $schema)
        );
    }//end testAdminAlwaysAuthorized()

    /**
     * An anonymous caller (null uid) is denied.
     *
     * @return void
     */
    public function testAnonymousIsDenied(): void
    {
        $schema = $this->schemaWithAuthorization([]);
        $this->assertFalse(
            $this->handler->isTransitionAuthorized(['vergunningverleners'], null, $schema)
        );
    }//end testAnonymousIsDenied()

    /**
     * An empty authorization list authorizes nobody (fail-closed).
     *
     * @return void
     */
    public function testEmptyListAuthorizesNobody(): void
    {
        $schema = $this->schemaWithAuthorization([]);
        $this->assertFalse(
            $this->handler->isTransitionAuthorized([], 'alice', $schema)
        );
    }//end testEmptyListAuthorizesNobody()

    /**
     * An unknown uid (user manager returns null) is denied.
     *
     * @return void
     */
    public function testUnknownUserIsDenied(): void
    {
        $this->userManager->method('get')->with('ghost')->willReturn(null);
        $schema = $this->schemaWithAuthorization([]);

        $this->assertFalse(
            $this->handler->isTransitionAuthorized(['vergunningverleners'], 'ghost', $schema)
        );
    }//end testUnknownUserIsDenied()

    /**
     * A `{ "role": "<name>" }` entry resolves to the groups assigned to
     * that named role on the schema's authorization block, and a member
     * of one of those groups is authorized.
     *
     * @return void
     */
    public function testNamedRoleResolvesToAssignedGroups(): void
    {
        $this->withUserInGroups('carol', ['vergunningverleners']);
        $schema = $this->schemaWithAuthorization(
            ['roles' => ['handler' => ['vergunningverleners', 'senior']]]
        );

        $this->assertTrue(
            $this->handler->isTransitionAuthorized([['role' => 'handler']], 'carol', $schema)
        );
    }//end testNamedRoleResolvesToAssignedGroups()

    /**
     * A `{ "role": "<name>" }` entry referencing a role whose assigned
     * groups the caller does not belong to is denied.
     *
     * @return void
     */
    public function testNamedRoleNonMemberIsDenied(): void
    {
        $this->withUserInGroups('dave', ['behandelaars']);
        $schema = $this->schemaWithAuthorization(
            ['roles' => ['handler' => ['vergunningverleners']]]
        );

        $this->assertFalse(
            $this->handler->isTransitionAuthorized([['role' => 'handler']], 'dave', $schema)
        );
    }//end testNamedRoleNonMemberIsDenied()
}//end class
