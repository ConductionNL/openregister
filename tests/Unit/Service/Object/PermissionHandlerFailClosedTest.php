<?php

/**
 * PermissionHandler fail-closed authorization tests (CWE-863).
 *
 * Regression guard for the silent fail-open in getRegisterAuthorization():
 * the resolver caught \Throwable, returned null WITHOUT logging, and cached
 * that null. The caller's `empty($authorization)` treatment then read the
 * null as "no rules configured" and granted FULL permissions — permanently,
 * because the failure was cached as if it were an answer.
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
 * @spec openspec/specs/authorization-rbac/spec.md#requirement-authorization-resolution-fails-closed
 */

declare(strict_types=1);

namespace Unit\Service\Object;

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
 * @coversDefaultClass \OCA\OpenRegister\Service\Object\PermissionHandler
 */
class PermissionHandlerFailClosedTest extends TestCase
{

    private PermissionHandler $handler;

    private IUserSession&MockObject $userSession;

    private IUserManager&MockObject $userManager;

    private IGroupManager&MockObject $groupManager;

    private SchemaMapper&MockObject $schemaMapper;

    private MagicMapper&MockObject $objectEntityMapper;

    private LoggerInterface&MockObject $logger;

    private ContainerInterface&MockObject $container;

    private RegisterMapper&MockObject $registerMapper;


    /**
     * Wire a PermissionHandler whose RegisterMapper::find() always blows up.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->userSession        = $this->createMock(IUserSession::class);
        $this->userManager        = $this->createMock(IUserManager::class);
        $this->groupManager       = $this->createMock(IGroupManager::class);
        $this->schemaMapper       = $this->createMock(SchemaMapper::class);
        $this->objectEntityMapper = $this->createMock(MagicMapper::class);
        $this->logger             = $this->createMock(LoggerInterface::class);
        $this->container          = $this->createMock(ContainerInterface::class);
        $this->registerMapper     = $this->createMock(RegisterMapper::class);

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueBool')->willReturn(true);

        $this->handler = new PermissionHandler(
            $this->userSession,
            $this->userManager,
            $this->groupManager,
            $this->schemaMapper,
            $this->objectEntityMapper,
            $this->createMock(ConditionMatcher::class),
            $appConfig,
            $this->logger,
            $this->container
        );
    }//end setUp()


    /**
     * Stage a non-admin logged-in user.
     *
     * @return void
     */
    private function stageUser(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);
        $this->userManager->method('get')->with('alice')->willReturn($user);
        $this->groupManager->method('getUserGroupIds')->willReturn(['users']);
    }//end stageUser()


    /**
     * Build a schema with NO own authorization, so resolution must fall back to
     * the register cascade — the path that used to fail open.
     *
     * @return Schema The schema under test.
     */
    private function schemaWithoutOwnAuth(): Schema
    {
        $schema = new Schema();
        $schema->setId(42);
        $schema->setAuthorization([]);

        return $schema;
    }//end schemaWithoutOwnAuth()


    /**
     * Make the register lookup throw, simulating a mapper/DB outage.
     *
     * @return void
     */
    private function breakRegisterLookup(): void
    {
        // The schema DOES belong to a register — so this is unambiguously an
        // error, not the legitimate "no register configured" answer.
        $this->registerMapper->method('getFirstRegisterWithSchema')->willReturn(7);
        $this->registerMapper->method('find')
            ->willThrowException(new \RuntimeException('database is on fire'));
        $this->container->method('get')
            ->with(RegisterMapper::class)
            ->willReturn($this->registerMapper);
    }//end breakRegisterLookup()


    /**
     * An unresolvable authorization must DENY, not grant.
     *
     * Pre-fix this returned true for every action: the swallowed Throwable
     * produced null, and `empty(null)` short-circuited the rule chain into a
     * blanket allow.
     *
     * @return void
     *
     * @covers ::hasPermission
     */
    public function testUnresolvableAuthorizationDeniesEveryAction(): void
    {
        $this->stageUser();
        $this->breakRegisterLookup();
        $schema = $this->schemaWithoutOwnAuth();

        foreach (['read', 'create', 'update', 'delete', 'list'] as $action) {
            $this->assertFalse(
                $this->handler->hasPermission($schema, $action),
                sprintf('Action "%s" must be DENIED when authorization cannot be resolved', $action)
            );
        }
    }//end testUnresolvableAuthorizationDeniesEveryAction()


    /**
     * The failure must be LOGGED. The original resolver swallowed it silently,
     * so a live authorization outage left no trace anywhere while handing out
     * full permissions. A sibling resolver logged on the same shape; this one
     * did not.
     *
     * @return void
     *
     * @covers ::hasPermission
     */
    public function testUnresolvableAuthorizationIsLogged(): void
    {
        $this->stageUser();
        $this->breakRegisterLookup();

        $logged = [];
        $this->logger->method('error')
            ->willReturnCallback(
                function ($message) use (&$logged) {
                    $logged[] = (string) $message;
                }
            );

        $this->handler->hasPermission($this->schemaWithoutOwnAuth(), 'read');

        $this->assertNotEmpty($logged, 'Authorization resolution failure MUST be logged, not swallowed');
        $this->assertStringContainsString(
            'fail-closed',
            implode(' | ', $logged),
            'The log line should name the fail-closed denial'
        );
    }//end testUnresolvableAuthorizationIsLogged()


    /**
     * A failure must NOT be cached as if it were an answer.
     *
     * The original code did `$this->cachedRegisterAuth[$registerId] = null;` in
     * the catch, so one transient blip poisoned the cache for the whole request
     * and every later check read the poisoned null as "open". Here the register
     * lookup recovers on the second call, and the handler must re-resolve and
     * honour the register's real (restrictive) rules rather than replay the
     * cached failure.
     *
     * @return void
     *
     * @covers ::hasPermission
     */
    public function testResolutionFailureIsNotCachedAsAnAnswer(): void
    {
        $this->stageUser();

        $register = new \OCA\OpenRegister\Db\Register();
        $register->setId(7);
        // Real, restrictive rules: only `editors` may read. Our user is in `users`.
        $register->setAuthorization(['read' => ['editors']]);
        $register->setConfiguration([]);

        $this->registerMapper->method('getFirstRegisterWithSchema')->willReturn(7);

        $calls = 0;
        $this->registerMapper->method('find')
            ->willReturnCallback(
                function () use (&$calls, $register) {
                    $calls++;
                    if ($calls === 1) {
                        throw new \RuntimeException('transient blip');
                    }

                    return $register;
                }
            );
        $this->container->method('get')
            ->with(RegisterMapper::class)
            ->willReturn($this->registerMapper);

        // First call: unresolvable -> denied.
        $this->assertFalse(
            $this->handler->hasPermission($this->schemaWithoutOwnAuth(), 'read'),
            'First call must fail closed'
        );

        // Second call on a DIFFERENT schema id so the hasPermission verdict cache
        // (keyed on schema/action/user) cannot mask a poisoned register-auth cache.
        $schema = $this->schemaWithoutOwnAuth();
        $schema->setId(43);

        $this->handler->hasPermission($schema, 'read');

        $this->assertGreaterThan(
            1,
            $calls,
            'The register lookup MUST be retried — a cached failure would have skipped it, '
            .'freezing a transient error into a permanent verdict.'
        );
    }//end testResolutionFailureIsNotCachedAsAnAnswer()


}//end class
