<?php
/**
 * PermissionHandler inheritFromPublic unit tests
 *
 * Covers PermissionHandler::resolveInheritFromPublic (cascade resolution) and the
 * inheritance gating inside PermissionHandler::hasPermission introduced by the
 * rbac-disable-public-inheritance change.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
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

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\MagicMapper;
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
 * Tests for PermissionHandler::resolveInheritFromPublic and the inheritance gating
 * inside hasPermission introduced by the rbac-disable-public-inheritance change.
 *
 * Covers:
 *   - The four-level cascade (schema → register → IAppConfig → hard-coded true)
 *   - `null = unset` semantics
 *   - The four-state matrix on hasPermission: (anon, auth) × (inheritFromPublic true, false)
 *   - Owner / admin shortcuts unaffected by the flag
 */
class PermissionHandlerInheritFromPublicTest extends TestCase
{

    /**
     * Subject under test.
     *
     * @var PermissionHandler
     */
    private PermissionHandler $handler;

    /**
     * Mock user session.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

    /**
     * Mock user manager.
     *
     * @var IUserManager&MockObject
     */
    private IUserManager&MockObject $userManager;

    /**
     * Mock group manager.
     *
     * @var IGroupManager&MockObject
     */
    private IGroupManager&MockObject $groupManager;

    /**
     * Mock schema mapper.
     *
     * @var SchemaMapper&MockObject
     */
    private SchemaMapper&MockObject $schemaMapper;

    /**
     * Mock object-entity mapper.
     *
     * @var MagicMapper&MockObject
     */
    private MagicMapper&MockObject $objectEntityMapper;

    /**
     * Mock condition matcher.
     *
     * @var ConditionMatcher&MockObject
     */
    private ConditionMatcher&MockObject $conditionMatcher;

    /**
     * Mock app config (provides the tenant default for inheritFromPublic).
     *
     * @var IAppConfig&MockObject
     */
    private IAppConfig&MockObject $appConfig;

    /**
     * Mock logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Mock DI container (used to resolve RegisterMapper lazily).
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Mock register mapper (resolved via the container).
     *
     * @var RegisterMapper&MockObject
     */
    private RegisterMapper&MockObject $registerMapper;

    /**
     * Wire up mocks and build a fresh PermissionHandler for each test case.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->userSession        = $this->createMock(originalClassName: IUserSession::class);
        $this->userManager        = $this->createMock(originalClassName: IUserManager::class);
        $this->groupManager       = $this->createMock(originalClassName: IGroupManager::class);
        $this->schemaMapper       = $this->createMock(originalClassName: SchemaMapper::class);
        $this->objectEntityMapper = $this->createMock(originalClassName: MagicMapper::class);
        $this->conditionMatcher   = $this->createMock(originalClassName: ConditionMatcher::class);
        $this->appConfig          = $this->createMock(originalClassName: IAppConfig::class);
        $this->logger         = $this->createMock(originalClassName: LoggerInterface::class);
        $this->container      = $this->createMock(originalClassName: ContainerInterface::class);
        $this->registerMapper = $this->createMock(originalClassName: RegisterMapper::class);

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

    }//end setUp()

    /**
     * Build a Schema fixture with the given id and authorization block.
     *
     * @param int        $id            The schema id.
     * @param array|null $authorization The authorization block (or null for no authz).
     *
     * @return Schema
     */
    private function createSchema(int $id, ?array $authorization): Schema
    {
        $schema = new Schema();
        $schema->setId($id);
        $schema->setAuthorization($authorization);
        $schema->setTitle('Test Schema '.$id);
        return $schema;

    }//end createSchema()

    /**
     * Build a Register fixture with the given id and authorization block.
     *
     * @param int        $id            The register id.
     * @param array|null $authorization The authorization block (or null for no authz).
     *
     * @return Register
     */
    private function createRegister(int $id, ?array $authorization): Register
    {
        $register = new Register();
        $register->setId($id);
        $register->setAuthorization($authorization);
        $register->setTitle('Test Register '.$id);
        return $register;

    }//end createRegister()

    /**
     * Configure the user session mock to return no current user (anonymous).
     *
     * @return void
     */
    private function mockNoUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

    }//end mockNoUser()

    /**
     * Configure the user session, user manager, and group manager mocks for a logged-in user.
     *
     * @param string $uid    The user id.
     * @param array  $groups The user's group memberships.
     *
     * @return void
     */
    private function mockUser(string $uid, array $groups): void
    {
        $user = $this->createMock(originalClassName: IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
        $this->userManager->method('get')->willReturn($user);
        $this->groupManager->method('getUserGroupIds')->willReturn($groups);

    }//end mockUser()

    /**
     * Wire the container + register mapper so resolveInheritFromPublic can find the parent register.
     *
     * @param int      $registerId The register id to expose.
     * @param Register $register   The register mock to return from find().
     *
     * @return void
     */
    private function wireRegister(int $registerId, Register $register): void
    {
        $this->registerMapper->method('getFirstRegisterWithSchema')->willReturn($registerId);
        $this->registerMapper->method('find')->willReturn($register);
        $this->container->method('get')->willReturnCallback(
            fn (string $class) => $class === RegisterMapper::class ? $this->registerMapper : null
        );

    }//end wireRegister()

    // ---------- Cascade tests ----------

    /**
     * Cascade falls through to the hard-coded `true` default when no value is set
     * at any level (schema, register, tenant).
     *
     * @return void
     */
    public function testCascadeReturnsHardCodedTrueWhenNothingSet(): void
    {
        $schema = $this->createSchema(id: 1, authorization: null);
        $this->appConfig
            ->method('getValueBool')
            ->with('openregister', 'rbac.inherit_from_public_default', true)
            ->willReturn(true);

        $result = $this->handler->resolveInheritFromPublic(schema: $schema);

        $this->assertTrue(condition: $result);

    }//end testCascadeReturnsHardCodedTrueWhenNothingSet()

    /**
     * Schema-level value wins when set, regardless of register / tenant defaults.
     *
     * @return void
     */
    public function testCascadeUsesSchemaValueWhenSet(): void
    {
        $schema = $this->createSchema(id: 1, authorization: ['inheritFromPublic' => false]);

        $result = $this->handler->resolveInheritFromPublic(schema: $schema);

        $this->assertFalse(condition: $result);

    }//end testCascadeUsesSchemaValueWhenSet()

    /**
     * When the schema does not set inheritFromPublic, the cascade falls through to the register's value.
     *
     * @return void
     */
    public function testCascadeFallsBackToRegisterWhenSchemaUnset(): void
    {
        $schema   = $this->createSchema(id: 1, authorization: null);
        $register = $this->createRegister(id: 10, authorization: ['inheritFromPublic' => false]);
        $this->wireRegister(registerId: 10, register: $register);

        $result = $this->handler->resolveInheritFromPublic(schema: $schema);

        $this->assertFalse(condition: $result);

    }//end testCascadeFallsBackToRegisterWhenSchemaUnset()

    /**
     * When neither schema nor register sets the flag, the IAppConfig tenant default is used.
     *
     * @return void
     */
    public function testCascadeFallsBackToTenantDefaultWhenSchemaAndRegisterUnset(): void
    {
        $schema = $this->createSchema(id: 1, authorization: null);
        $this->appConfig
            ->method('getValueBool')
            ->with('openregister', 'rbac.inherit_from_public_default', true)
            ->willReturn(false);

        $result = $this->handler->resolveInheritFromPublic(schema: $schema);

        $this->assertFalse(condition: $result);

    }//end testCascadeFallsBackToTenantDefaultWhenSchemaAndRegisterUnset()

    /**
     * Schema-level explicit value wins over both register-level and tenant-level values.
     *
     * @return void
     */
    public function testCascadeSchemaWinsOverRegisterAndTenant(): void
    {
        $schema   = $this->createSchema(id: 1, authorization: ['inheritFromPublic' => true]);
        $register = $this->createRegister(id: 10, authorization: ['inheritFromPublic' => false]);
        $this->wireRegister(registerId: 10, register: $register);
        $this->appConfig->method('getValueBool')->willReturn(false);

        $result = $this->handler->resolveInheritFromPublic(schema: $schema);

        $this->assertTrue(condition: $result);

    }//end testCascadeSchemaWinsOverRegisterAndTenant()

    /**
     * An explicit `null` at the schema level is treated as "unset" — the cascade falls through.
     *
     * @return void
     */
    public function testCascadeNullIsTreatedAsUnset(): void
    {
        $schema   = $this->createSchema(id: 1, authorization: ['inheritFromPublic' => null]);
        $register = $this->createRegister(id: 10, authorization: ['inheritFromPublic' => false]);
        $this->wireRegister(registerId: 10, register: $register);

        $result = $this->handler->resolveInheritFromPublic(schema: $schema);

        $this->assertFalse(
            condition: $result,
            message: 'Schema-level null should fall through to register-level value.'
        );

    }//end testCascadeNullIsTreatedAsUnset()

    /**
     * Repeated calls return the same cached result (per-request cache).
     *
     * @return void
     */
    public function testCachingReturnsSameResultOnRepeatedCalls(): void
    {
        $schema = $this->createSchema(id: 1, authorization: ['inheritFromPublic' => false]);

        $first  = $this->handler->resolveInheritFromPublic(schema: $schema);
        $second = $this->handler->resolveInheritFromPublic(schema: $schema);

        $this->assertSame(expected: $first, actual: $second);
        $this->assertFalse(condition: $first);

    }//end testCachingReturnsSameResultOnRepeatedCalls()

    // ---------- Four-state matrix on hasPermission ----------

    /**
     * Anonymous user is granted when the public rule matches and inheritFromPublic is true.
     *
     * @return void
     */
    public function testAnonUserGrantedWhenPublicMatchPassesAndInheritIsTrue(): void
    {
        $schema = $this->createSchema(
            id: 1,
            authorization: [
                'read'              => [['group' => 'public']],
                'inheritFromPublic' => true,
            ]
        );
        $this->mockNoUser();

        $result = $this->handler->hasPermission(schema: $schema, action: 'read');

        $this->assertTrue(condition: $result);

    }//end testAnonUserGrantedWhenPublicMatchPassesAndInheritIsTrue()

    /**
     * Anonymous user is granted even when inheritFromPublic is false — anonymous users
     * are unaffected by the flag (they aren't authenticated, so inheritance doesn't apply).
     *
     * @return void
     */
    public function testAnonUserGrantedWhenPublicMatchPassesAndInheritIsFalse(): void
    {
        $schema = $this->createSchema(
            id: 1,
            authorization: [
                'read'              => [['group' => 'public']],
                'inheritFromPublic' => false,
            ]
        );
        $this->mockNoUser();

        $result = $this->handler->hasPermission(schema: $schema, action: 'read');

        $this->assertTrue(condition: $result);

    }//end testAnonUserGrantedWhenPublicMatchPassesAndInheritIsFalse()

    /**
     * Authenticated user with no own-group match is granted via inheritance from public
     * when inheritFromPublic is true (pre-change semantics).
     *
     * @return void
     */
    public function testAuthUserGrantedWhenPublicMatchPassesAndInheritIsTrue(): void
    {
        $schema = $this->createSchema(
            id: 1,
            authorization: [
                'read'              => [['group' => 'public']],
                'inheritFromPublic' => true,
            ]
        );
        $this->mockUser(uid: 'alice', groups: ['users']);

        $result = $this->handler->hasPermission(schema: $schema, action: 'read');

        $this->assertTrue(condition: $result);

    }//end testAuthUserGrantedWhenPublicMatchPassesAndInheritIsTrue()

    /**
     * Authenticated user with no own-group match is denied when inheritFromPublic is false —
     * the flag prevents the public rule from applying to authenticated users.
     *
     * @return void
     */
    public function testAuthUserDeniedWhenPublicMatchPassesAndInheritIsFalse(): void
    {
        $schema = $this->createSchema(
            id: 1,
            authorization: [
                'read'              => [['group' => 'public']],
                'inheritFromPublic' => false,
            ]
        );
        $this->mockUser(uid: 'alice', groups: ['users']);

        $result = $this->handler->hasPermission(schema: $schema, action: 'read');

        $this->assertFalse(condition: $result);

    }//end testAuthUserDeniedWhenPublicMatchPassesAndInheritIsFalse()

    // ---------- Owner / admin shortcuts unaffected ----------

    /**
     * Admin user is always granted, regardless of inheritFromPublic.
     *
     * @return void
     */
    public function testAdminUserGrantedRegardlessOfFlag(): void
    {
        $schema = $this->createSchema(
            id: 1,
            authorization: [
                'read'              => [['group' => 'public']],
                'inheritFromPublic' => false,
            ]
        );
        $this->mockUser(uid: 'admin-user', groups: ['admin']);

        $result = $this->handler->hasPermission(schema: $schema, action: 'read');

        $this->assertTrue(
            condition: $result,
            message: 'Admin must always be granted, regardless of inheritFromPublic.'
        );

    }//end testAdminUserGrantedRegardlessOfFlag()

    /**
     * Object owner is always granted, regardless of inheritFromPublic.
     *
     * @return void
     */
    public function testOwnerGrantedRegardlessOfFlag(): void
    {
        $schema = $this->createSchema(
            id: 1,
            authorization: [
                'read'              => [['group' => 'public']],
                'inheritFromPublic' => false,
            ]
        );
        $this->mockUser(uid: 'carol', groups: ['users']);

        $result = $this->handler->hasPermission(
            schema: $schema,
            action: 'read',
            objectOwner: 'carol'
        );

        $this->assertTrue(
            condition: $result,
            message: 'Object owner must always be granted, regardless of inheritFromPublic.'
        );

    }//end testOwnerGrantedRegardlessOfFlag()

    /**
     * Authenticated user with explicit group access is granted when inheritFromPublic is false —
     * the flag only affects public inheritance, not explicit group memberships.
     *
     * @return void
     */
    public function testAuthUserGrantedViaOwnGroupEvenWhenInheritIsFalse(): void
    {
        $schema = $this->createSchema(
            id: 1,
            authorization: [
                'read'              => [
                    ['group' => 'public'],
                    'editors',
                ],
                'inheritFromPublic' => false,
            ]
        );
        $this->mockUser(uid: 'bob', groups: ['editors']);

        $result = $this->handler->hasPermission(schema: $schema, action: 'read');

        $this->assertTrue(condition: $result);

    }//end testAuthUserGrantedViaOwnGroupEvenWhenInheritIsFalse()
}//end class
