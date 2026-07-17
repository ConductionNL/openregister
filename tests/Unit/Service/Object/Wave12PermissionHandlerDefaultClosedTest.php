<?php
/**
 * Regression tests for the opt-in default-closed write policy and for
 * per-object `_authorization` overrides.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://openregister.app
 */

declare(strict_types=1);

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
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
 * Two capabilities that share a call path:
 *
 *  - `rbac.enforce_default_closed`: a schema with NO authorization block grants
 *    every authenticated caller every write. That default-open stays (it is BC)
 *    but becomes opt-out-able.
 *  - per-object `_authorization`: the column was hydrated and serialized but
 *    consulted by nothing — dead storage. These tests pin that it is now
 *    CONSUMED on the live permission path, which is the only thing that makes
 *    the column real.
 */
class Wave12PermissionHandlerDefaultClosedTest extends TestCase
{
    private IUserSession&MockObject $userSession;
    private IUserManager&MockObject $userManager;
    private IGroupManager&MockObject $groupManager;
    private SchemaMapper&MockObject $schemaMapper;
    private MagicMapper&MockObject $objectEntityMapper;
    private ConditionMatcher&MockObject $conditionMatcher;
    private LoggerInterface&MockObject $logger;
    private ContainerInterface&MockObject $container;
    private RegisterMapper&MockObject $registerMapper;

    protected function setUp(): void
    {
        $this->userSession       = $this->createMock(IUserSession::class);
        $this->userManager       = $this->createMock(IUserManager::class);
        $this->groupManager      = $this->createMock(IGroupManager::class);
        $this->schemaMapper      = $this->createMock(SchemaMapper::class);
        $this->objectEntityMapper = $this->createMock(MagicMapper::class);
        $this->conditionMatcher  = $this->createMock(ConditionMatcher::class);
        $this->logger            = $this->createMock(LoggerInterface::class);
        $this->container         = $this->createMock(ContainerInterface::class);
        $this->registerMapper    = $this->createMock(RegisterMapper::class);

        // Wire the register cascade. Without it getRegisterForSchema() throws
        // AuthorizationUnresolvableException and the (correct) fail-closed
        // contract denies everything — which would make every assertion below
        // pass or fail for the wrong reason.
        $register = new Register();
        $register->setId(10);
        $register->setAuthorization(null);
        $register->setConfiguration([]);

        $this->container->method('get')->willReturnCallback(
            function (string $class) {
                if ($class === RegisterMapper::class) {
                    return $this->registerMapper;
                }

                throw new \RuntimeException('Not available: '.$class);
            }
        );

        $this->registerMapper->method('getFirstRegisterWithSchema')->willReturn(10);
        $this->registerMapper->method('find')->willReturn($register);
    }

    /**
     * Build a handler whose `rbac.enforce_default_closed` returns $enforce.
     * Every other bool flag resolves to its declared default.
     */
    private function handler(bool $enforce, ?LoggerInterface $logger=null): PermissionHandler
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueBool')->willReturnCallback(
            static function (string $app, string $key, bool $default=false, bool $lazy=false) use ($enforce): bool {
                if ($key === 'rbac.enforce_default_closed') {
                    return $enforce;
                }

                if ($key === 'rbac.inherit_from_public_default') {
                    return true;
                }

                return $default;
            }
        );

        return new PermissionHandler(
            $this->userSession,
            $this->userManager,
            $this->groupManager,
            $this->schemaMapper,
            $this->objectEntityMapper,
            $this->conditionMatcher,
            $appConfig,
            ($logger ?? $this->logger),
            $this->container
        );
    }

    /**
     * Sign in $uid as a member of $groups.
     */
    private function signIn(string $uid, array $groups): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);

        $this->userSession->method('getUser')->willReturn($user);
        $this->userManager->method('get')->with($uid)->willReturn($user);
        $this->groupManager->method('getUserGroupIds')->with($user)->willReturn($groups);
    }

    /**
     * A schema that declares no authorization block at all.
     */
    private function unauthorizedSchema(): Schema
    {
        $schema = new Schema();
        // The id is load-bearing: getRegisterForSchema() cannot resolve a
        // schema without one and (correctly) throws, which fails closed and
        // would make every assertion here pass for the wrong reason.
        $schema->setId(1);
        $schema->setUuid('11111111-1111-1111-1111-111111111111');
        $schema->setSlug('open-schema');
        $schema->setAuthorization([]);
        return $schema;
    }

    // --- Fix 2: default-closed flag ------------------------------------

    public function testFlagOffKeepsWritesOpen(): void
    {
        // The BC default. Turning this red means the upgrade broke the fleet.
        $this->signIn('alice', ['users']);

        $this->assertTrue(
            $this->handler(false)->hasPermission(schema: $this->unauthorizedSchema(), action: 'create', userId: 'alice')
        );
    }

    public function testFlagOnDeniesCreate(): void
    {
        $this->signIn('alice', ['users']);

        $this->assertFalse(
            $this->handler(true)->hasPermission(schema: $this->unauthorizedSchema(), action: 'create', userId: 'alice')
        );
    }

    public function testFlagOnDeniesUpdate(): void
    {
        $this->signIn('alice', ['users']);

        $this->assertFalse(
            $this->handler(true)->hasPermission(schema: $this->unauthorizedSchema(), action: 'update', userId: 'alice')
        );
    }

    public function testFlagOnDeniesDelete(): void
    {
        $this->signIn('alice', ['users']);

        $this->assertFalse(
            $this->handler(true)->hasPermission(schema: $this->unauthorizedSchema(), action: 'delete', userId: 'alice')
        );
    }

    public function testFlagOnKeepsReadsOpen(): void
    {
        // Reads are deliberately out of scope: @PublicPage is the OR-wide read
        // model and closing reads is a separate decision.
        $this->signIn('alice', ['users']);

        $this->assertTrue(
            $this->handler(true)->hasPermission(schema: $this->unauthorizedSchema(), action: 'read', userId: 'alice')
        );
    }

    public function testFlagOnDoesNotDenyAdmins(): void
    {
        $this->signIn('root', ['admin']);

        $this->assertTrue(
            $this->handler(true)->hasPermission(schema: $this->unauthorizedSchema(), action: 'create', userId: 'root')
        );
    }

    public function testFlagOnDoesNotDenyTheObjectOwner(): void
    {
        // An owner keeps write access to their own object. Without this an
        // operator enabling the flag would lock users out of their own data.
        $this->signIn('alice', ['users']);

        $this->assertTrue(
            $this->handler(true)->hasPermission(
                schema: $this->unauthorizedSchema(),
                action: 'update',
                userId: 'alice',
                objectOwner: 'alice'
            )
        );
    }

    public function testFlagOnDoesNotAffectSchemasThatDeclareAuthorization(): void
    {
        // The flag governs ONLY the "no block at all" case. A schema that opts
        // into authorization is already fail-closed per action.
        $this->signIn('alice', ['users']);

        $schema = new Schema();
        $schema->setId(2);
        $schema->setUuid('22222222-2222-2222-2222-222222222222');
        $schema->setSlug('declared-schema');
        $schema->setAuthorization(['create' => ['users']]);

        $this->assertTrue(
            $this->handler(true)->hasPermission(schema: $schema, action: 'create', userId: 'alice')
        );
    }

    public function testFlagOffEmitsADeprecationWarning(): void
    {
        // The warning is the migration signal. Without it operators have no way
        // to find the schemas that would break under a future default flip.
        $this->signIn('alice', ['users']);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('no authorization block'));

        $this->handler(false, $logger)->hasPermission(
            schema: $this->unauthorizedSchema(),
            action: 'create',
            userId: 'alice'
        );
    }

    public function testDeprecationWarningIsEmittedOncePerSchemaPerAction(): void
    {
        // A bulk write of 10k rows must not emit 10k log lines.
        $this->signIn('alice', ['users']);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $handler = $this->handler(false, $logger);
        $schema  = $this->unauthorizedSchema();

        for ($i = 0; $i < 5; $i++) {
            $handler->hasPermission(schema: $schema, action: 'create', userId: 'alice');
        }
    }

    // --- Fix 5: per-object _authorization ------------------------------

    public function testPerObjectAuthorizationSealsAnIndividualObject(): void
    {
        // The point of the whole fix: this column used to be dead storage. A
        // seal declared here must actually deny.
        $this->signIn('alice', ['users']);

        $object = new ObjectEntity();
        $object->setUuid('33333333-3333-3333-3333-333333333333');
        $object->setAuthorization(['update' => ['admin']]);

        $this->assertFalse(
            $this->handler(false)->hasPermission(
                schema: $this->unauthorizedSchema(),
                action: 'update',
                userId: 'alice',
                object: $object
            )
        );
    }

    public function testPerObjectAuthorizationOnlyOverridesTheNamedAction(): void
    {
        // The merge is action-by-action, not a block replacement: sealing
        // `update` must not disturb `create`.
        $this->signIn('alice', ['users']);

        $object = new ObjectEntity();
        $object->setUuid('44444444-4444-4444-4444-444444444444');
        $object->setAuthorization(['update' => ['admin']]);

        $this->assertTrue(
            $this->handler(false)->hasPermission(
                schema: $this->unauthorizedSchema(),
                action: 'create',
                userId: 'alice',
                object: $object
            )
        );
    }

    public function testEmptyPerObjectAuthorizationChangesNothing(): void
    {
        // The BC default — every existing row has an empty block.
        $this->signIn('alice', ['users']);

        $object = new ObjectEntity();
        $object->setUuid('55555555-5555-5555-5555-555555555555');
        $object->setAuthorization([]);

        $this->assertTrue(
            $this->handler(false)->hasPermission(
                schema: $this->unauthorizedSchema(),
                action: 'update',
                userId: 'alice',
                object: $object
            )
        );
    }

    public function testPerObjectAuthorizationCanUnlockAgainstASealedSchema(): void
    {
        // Overrides go both ways: an object may be unlocked relative to a
        // restrictive schema baseline.
        $this->signIn('alice', ['users']);

        $schema = new Schema();
        $schema->setId(6);
        $schema->setUuid('66666666-6666-6666-6666-666666666666');
        $schema->setSlug('sealed-schema');
        $schema->setAuthorization(['update' => ['admin']]);

        $object = new ObjectEntity();
        $object->setUuid('77777777-7777-7777-7777-777777777777');
        $object->setAuthorization(['update' => ['users']]);

        $this->assertTrue(
            $this->handler(false)->hasPermission(
                schema: $schema,
                action: 'update',
                userId: 'alice',
                object: $object
            )
        );
    }

    public function testPerObjectReadOverrideIsIgnoredAndLogged(): void
    {
        // Read overrides are refused on purpose. The SQL list path builds its
        // WHERE clause from the schema before any row exists and cannot honour
        // a per-object read seal — enforcing it here would seal `find` while
        // `list` still returned the row. Half-enforced is worse than refused.
        $this->signIn('alice', ['users']);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with($this->stringContains('non-write'));

        $object = new ObjectEntity();
        $object->setUuid('88888888-8888-8888-8888-888888888888');
        $object->setAuthorization(['read' => ['admin']]);

        $this->assertTrue(
            $this->handler(false, $logger)->hasPermission(
                schema: $this->unauthorizedSchema(),
                action: 'read',
                userId: 'alice',
                object: $object
            )
        );
    }

    public function testPerObjectAuthorizationDoesNotOverrideTheAdminBypass(): void
    {
        $this->signIn('root', ['admin']);

        $object = new ObjectEntity();
        $object->setUuid('99999999-9999-9999-9999-999999999999');
        $object->setAuthorization(['update' => ['nobody']]);

        $this->assertTrue(
            $this->handler(false)->hasPermission(
                schema: $this->unauthorizedSchema(),
                action: 'update',
                userId: 'root',
                object: $object
            )
        );
    }
}
