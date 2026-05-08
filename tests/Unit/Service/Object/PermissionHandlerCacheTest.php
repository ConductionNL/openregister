<?php

/**
 * PermissionHandler request-scoped cache tests.
 *
 * Verifies that hasPermission() memoises verdicts within a single request so
 * hot list endpoints don't re-walk the rule chain N times for N rows.
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
 * @spec openspec/changes/rbac-scopes/specs/rbac-scopes/spec.md#requirement-scope-caching-for-performance
 */

declare(strict_types=1);

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Object\PermissionHandler;
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
class PermissionHandlerCacheTest extends TestCase
{

    private PermissionHandler $handler;

    private IUserSession&MockObject $userSession;

    private IUserManager&MockObject $userManager;

    private IGroupManager&MockObject $groupManager;

    private SchemaMapper&MockObject $schemaMapper;

    private MagicMapper&MockObject $objectEntityMapper;

    private ConditionMatcher&MockObject $conditionMatcher;

    private LoggerInterface&MockObject $logger;

    private ContainerInterface&MockObject $container;

    private RegisterMapper&MockObject $registerMapper;


    /**
     * Wire up a fresh PermissionHandler with mocked collaborators.
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
        $this->conditionMatcher   = $this->createMock(ConditionMatcher::class);
        $this->logger             = $this->createMock(LoggerInterface::class);
        $this->container          = $this->createMock(ContainerInterface::class);
        $this->registerMapper     = $this->createMock(RegisterMapper::class);

        $this->handler = new PermissionHandler(
            $this->userSession,
            $this->userManager,
            $this->groupManager,
            $this->schemaMapper,
            $this->objectEntityMapper,
            $this->conditionMatcher,
            $this->logger,
            $this->container
        );
    }//end setUp()


    /**
     * Helper: stage a logged-in user with the given groups.
     *
     * @param string        $uid    The user UID.
     * @param array<string> $groups The groups the user belongs to.
     *
     * @return IUser&MockObject
     */
    private function mockUser(string $uid, array $groups): IUser&MockObject
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $user->method('getDisplayName')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
        $this->userManager->method('get')->willReturn($user);
        $this->groupManager->method('getUserGroupIds')->willReturn($groups);
        return $user;
    }//end mockUser()


    /**
     * Helper: build a Schema with the given id and authorization block.
     *
     * @param int        $id            Schema ID.
     * @param array|null $authorization Authorization block, or null.
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
     * Helper: build a Register with the given id (no authorization needed for cache tests).
     *
     * @param int $id Register ID.
     *
     * @return Register
     */
    private function createRegister(int $id): Register
    {
        $register = new Register();
        $register->setId($id);
        $register->setAuthorization(null);
        $register->setConfiguration([]);
        return $register;
    }//end createRegister()


    /**
     * Helper: glue the schema's parent-register lookup to the mocked mappers.
     *
     * @param Register $register Register to resolve as the schema's parent.
     *
     * @return void
     */
    private function setupRegisterForSchema(Register $register): void
    {
        $this->container->method('get')
            ->willReturnCallback(
                function (string $class) use ($register) {
                    if ($class === RegisterMapper::class) {
                        return $this->registerMapper;
                    }
                    throw new \RuntimeException('Unknown class: '.$class);
                }
            );
        $this->registerMapper->method('getFirstRegisterWithSchema')->willReturn($register->getId());
        $this->registerMapper->method('find')->willReturn($register);
    }//end setupRegisterForSchema()


    /**
     * Helper: build an ObjectEntity with stable UUID + payload.
     *
     * @param string $uuid Object UUID.
     * @param array  $data Object payload.
     *
     * @return ObjectEntity
     */
    private function createObjectEntity(string $uuid, array $data): ObjectEntity
    {
        $object = new ObjectEntity();
        $object->setUuid($uuid);
        $object->setObject($data);
        return $object;
    }//end createObjectEntity()


    /**
     * Repeated calls with identical inputs must reuse the cached verdict.
     *
     * The hot path on list endpoints is N calls to hasPermission() with the same
     * (schema, action, userId, objectOwner). The user's group memberships should
     * be resolved once via IGroupManager and reused for every subsequent call.
     *
     * @return void
     */
    public function testRepeatedHasPermissionCallsHitCacheAndAvoidGroupLookup(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('jan');
        $user->method('getDisplayName')->willReturn('jan');
        $this->userSession->method('getUser')->willReturn($user);
        $this->userManager->method('get')->willReturn($user);

        // The whole point of caching: getUserGroupIds is called exactly ONCE
        // even though hasPermission is invoked many times.
        $this->groupManager->expects($this->once())
            ->method('getUserGroupIds')
            ->willReturn(['behandelaars']);

        $schema = $this->createSchema(1, ['read' => ['behandelaars']]);
        $this->setupRegisterForSchema($this->createRegister(10));

        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue(
                $this->handler->hasPermission(schema: $schema, action: 'read', userId: 'jan'),
                'Repeated identical hasPermission() calls must return the cached verdict'
            );
        }
    }//end testRepeatedHasPermissionCallsHitCacheAndAvoidGroupLookup()


    /**
     * Different actions on the same schema must not collide in the cache.
     *
     * @return void
     */
    public function testDifferentActionsAreCachedSeparately(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('jan');
        $user->method('getDisplayName')->willReturn('jan');
        $this->userSession->method('getUser')->willReturn($user);
        $this->userManager->method('get')->willReturn($user);

        // Two distinct cache keys (action=read vs action=delete) -> two group lookups.
        $this->groupManager->expects($this->exactly(2))
            ->method('getUserGroupIds')
            ->willReturn(['behandelaars']);

        $schema = $this->createSchema(1, [
            'read'   => ['behandelaars'],
            'delete' => ['admin'],
        ]);
        $this->setupRegisterForSchema($this->createRegister(10));

        $this->assertTrue($this->handler->hasPermission(schema: $schema, action: 'read', userId: 'jan'));
        $this->assertFalse($this->handler->hasPermission(schema: $schema, action: 'delete', userId: 'jan'));

        // Re-running both does NOT trigger more group lookups — verdicts are cached.
        $this->assertTrue($this->handler->hasPermission(schema: $schema, action: 'read', userId: 'jan'));
        $this->assertFalse($this->handler->hasPermission(schema: $schema, action: 'delete', userId: 'jan'));
    }//end testDifferentActionsAreCachedSeparately()


    /**
     * Different users must not share cache entries.
     *
     * @return void
     */
    public function testDifferentUsersAreCachedSeparately(): void
    {
        $jan  = $this->createMock(IUser::class);
        $jan->method('getUID')->willReturn('jan');
        $jan->method('getDisplayName')->willReturn('jan');
        $piet = $this->createMock(IUser::class);
        $piet->method('getUID')->willReturn('piet');
        $piet->method('getDisplayName')->willReturn('piet');

        $this->userManager->method('get')
            ->willReturnCallback(
                function (string $uid) use ($jan, $piet) {
                    return $uid === 'jan' ? $jan : $piet;
                }
            );

        $this->groupManager->expects($this->exactly(2))
            ->method('getUserGroupIds')
            ->willReturnCallback(
                function ($user) {
                    return $user->getUID() === 'jan' ? ['behandelaars'] : ['kcc-team'];
                }
            );

        $schema = $this->createSchema(1, ['read' => ['behandelaars']]);
        $this->setupRegisterForSchema($this->createRegister(10));

        $this->assertTrue($this->handler->hasPermission(schema: $schema, action: 'read', userId: 'jan'));
        $this->assertFalse($this->handler->hasPermission(schema: $schema, action: 'read', userId: 'piet'));

        // Repeat — both verdicts cached, no extra group lookups.
        $this->assertTrue($this->handler->hasPermission(schema: $schema, action: 'read', userId: 'jan'));
        $this->assertFalse($this->handler->hasPermission(schema: $schema, action: 'read', userId: 'piet'));
    }//end testDifferentUsersAreCachedSeparately()


    /**
     * Conditional rules with `match` clauses must still re-evaluate per object.
     *
     * Two different ObjectEntities with different UUIDs must produce two separate
     * delegations to ConditionMatcher — caching by UUID is the only safe reuse
     * window for object-dependent verdicts.
     *
     * @return void
     */
    public function testConditionalRulesEvaluatePerObjectUuid(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $schema = $this->createSchema(1, [
            'read' => [
                ['group' => 'public', 'match' => ['publishDate' => ['$lte' => '$now']]],
            ],
        ]);
        $this->setupRegisterForSchema($this->createRegister(10));

        // Two distinct objects -> two distinct cache keys -> two delegation calls.
        $this->conditionMatcher->expects($this->exactly(2))
            ->method('objectMatchesConditions')
            ->willReturnOnConsecutiveCalls(true, false);

        $objectA = $this->createObjectEntity('uuid-a', ['publishDate' => '2025-01-01']);
        $objectB = $this->createObjectEntity('uuid-b', ['publishDate' => '2099-01-01']);

        $this->assertTrue(
            $this->handler->hasPermission(
                schema: $schema,
                action: 'read',
                userId: null,
                objectOwner: null,
                _rbac: true,
                object: $objectA
            )
        );
        $this->assertFalse(
            $this->handler->hasPermission(
                schema: $schema,
                action: 'read',
                userId: null,
                objectOwner: null,
                _rbac: true,
                object: $objectB
            )
        );

        // Re-running on the same UUIDs must hit the cache — no further delegations.
        $this->assertTrue(
            $this->handler->hasPermission(
                schema: $schema,
                action: 'read',
                userId: null,
                objectOwner: null,
                _rbac: true,
                object: $objectA
            )
        );
        $this->assertFalse(
            $this->handler->hasPermission(
                schema: $schema,
                action: 'read',
                userId: null,
                objectOwner: null,
                _rbac: true,
                object: $objectB
            )
        );
    }//end testConditionalRulesEvaluatePerObjectUuid()


    /**
     * Object owner is part of the cache key — different owners must not collide.
     *
     * @return void
     */
    public function testObjectOwnerIsPartOfCacheKey(): void
    {
        $this->mockUser('jan', ['behandelaars']);

        $schema = $this->createSchema(1, ['update' => ['admin']]);
        $this->setupRegisterForSchema($this->createRegister(10));

        // Owner = 'jan' -> ownership bypass -> true.
        $this->assertTrue(
            $this->handler->hasPermission(
                schema: $schema,
                action: 'update',
                userId: 'jan',
                objectOwner: 'jan'
            )
        );

        // Owner = someone else, jan is not admin and not in update authorization -> false.
        $this->assertFalse(
            $this->handler->hasPermission(
                schema: $schema,
                action: 'update',
                userId: 'jan',
                objectOwner: 'piet'
            )
        );
    }//end testObjectOwnerIsPartOfCacheKey()


    /**
     * clearPermissionCache() must invalidate previously memoised verdicts.
     *
     * @return void
     */
    public function testClearPermissionCacheInvalidatesEntries(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('jan');
        $user->method('getDisplayName')->willReturn('jan');
        $this->userSession->method('getUser')->willReturn($user);
        $this->userManager->method('get')->willReturn($user);

        // After clearing, the second call resolves groups from scratch.
        $this->groupManager->expects($this->exactly(2))
            ->method('getUserGroupIds')
            ->willReturn(['behandelaars']);

        $schema = $this->createSchema(1, ['read' => ['behandelaars']]);
        $this->setupRegisterForSchema($this->createRegister(10));

        $this->assertTrue($this->handler->hasPermission(schema: $schema, action: 'read', userId: 'jan'));
        $this->assertTrue($this->handler->hasPermission(schema: $schema, action: 'read', userId: 'jan'));

        $this->handler->clearPermissionCache();

        $this->assertTrue($this->handler->hasPermission(schema: $schema, action: 'read', userId: 'jan'));
    }//end testClearPermissionCacheInvalidatesEntries()


    /**
     * RBAC bypass (_rbac=false) must short-circuit before the cache so it
     * always returns true regardless of cache contents.
     *
     * @return void
     */
    public function testRbacBypassShortCircuitsBeforeCache(): void
    {
        // No userSession/userManager mocks needed — they must NOT be called.
        $this->userSession->expects($this->never())->method('getUser');
        $this->groupManager->expects($this->never())->method('getUserGroupIds');

        $schema = $this->createSchema(1, ['read' => ['admin']]);

        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue(
                $this->handler->hasPermission(
                    schema: $schema,
                    action: 'read',
                    userId: 'jan',
                    objectOwner: null,
                    _rbac: false
                )
            );
        }
    }//end testRbacBypassShortCircuitsBeforeCache()
}//end class
