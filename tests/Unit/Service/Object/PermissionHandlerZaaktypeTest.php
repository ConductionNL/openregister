<?php

/**
 * Zaaktype-scoped authorization tests for PermissionHandler.
 *
 * Exercises the user-level override (delegation) primitive added by the
 * rbac-zaaktype change: a `user:<uid>` (or `{user, match}`) entry in a schema's
 * authorization grants the named user an action independent of group membership,
 * is fail-closed for everyone else, and is purely additive (never widens or
 * removes group access).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
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
 * Allow/deny matrix for zaaktype-scoped user-level overrides.
 */
class PermissionHandlerZaaktypeTest extends TestCase
{
    private PermissionHandler $handler;
    private IUserSession&MockObject $userSession;
    private IUserManager&MockObject $userManager;
    private IGroupManager&MockObject $groupManager;
    private ConditionMatcher&MockObject $conditionMatcher;

    protected function setUp(): void
    {
        $this->userSession      = $this->createMock(IUserSession::class);
        $this->userManager      = $this->createMock(IUserManager::class);
        $this->groupManager     = $this->createMock(IGroupManager::class);
        $this->conditionMatcher = $this->createMock(ConditionMatcher::class);

        $schemaMapper = $this->createMock(SchemaMapper::class);
        $objectMapper = $this->createMock(MagicMapper::class);
        $logger       = $this->createMock(LoggerInterface::class);
        $container    = $this->createMock(ContainerInterface::class);
        $appConfig    = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueBool')->willReturn(true);

        // No register cascade is needed: every schema in these tests carries its
        // own authorization block. The container only needs to refuse the
        // OrganisationService lookup gracefully.
        $container->method('get')->willReturnCallback(
            static function (string $class) {
                throw new \RuntimeException('Not available: '.$class);
            }
        );

        $this->handler = new PermissionHandler(
            $this->userSession,
            $this->userManager,
            $this->groupManager,
            $schemaMapper,
            $objectMapper,
            $this->conditionMatcher,
            $appConfig,
            $logger,
            $container
        );
    }

    private function mockUser(string $uid, array $groups): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $user->method('getDisplayName')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
        $this->userManager->method('get')->willReturn($user);
        $this->groupManager->method('getUserGroupIds')->willReturn($groups);
    }

    private function mockAnonymous(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
    }

    private function schema(array $authorization): Schema
    {
        $schema = new Schema();
        $schema->setId(1);
        $schema->setAuthorization($authorization);
        $schema->setTitle('Personeelszaken');
        return $schema;
    }

    // === Delegated access for a single user (bare string form) ===

    public function testUserOverrideGrantsDelegatedRead(): void
    {
        // Schema restricted to hr-team; extern-adviseur delegated read only.
        $this->mockUser('extern-adviseur', ['externals']);
        $schema = $this->schema([
            'read'   => ['hr-team', 'user:extern-adviseur'],
            'create' => ['hr-team'],
            'update' => ['hr-team'],
        ]);

        // Delegated read is granted even though user is not in hr-team.
        $this->assertTrue($this->handler->hasPermission($schema, 'read', 'extern-adviseur'));
        // Only the explicitly granted action applies: no write/create.
        $this->assertFalse($this->handler->hasPermission($schema, 'create', 'extern-adviseur'));
        $this->assertFalse($this->handler->hasPermission($schema, 'update', 'extern-adviseur'));
    }

    public function testUserOverrideDoesNotLeakToOtherUsers(): void
    {
        // A different user (not the delegate, not in hr-team) is denied.
        $this->mockUser('random', ['externals']);
        $schema = $this->schema([
            'read' => ['hr-team', 'user:extern-adviseur'],
        ]);

        $this->assertFalse($this->handler->hasPermission($schema, 'read', 'random'));
    }

    public function testUserOverrideWorksWithZeroGroups(): void
    {
        // A delegate with NO groups still gets the grant (override is evaluated
        // independently of the group loop).
        $this->mockUser('extern-adviseur', []);
        $schema = $this->schema([
            'read' => ['hr-team', 'user:extern-adviseur'],
        ]);

        $this->assertTrue($this->handler->hasPermission($schema, 'read', 'extern-adviseur'));
    }

    public function testUserOverrideNeverGrantsAnonymous(): void
    {
        // Fail closed: an anonymous principal can never match a user override,
        // even when the uid portion is empty-ish.
        $this->mockAnonymous();
        $schema = $this->schema([
            'read' => ['user:extern-adviseur'],
        ]);

        // Anonymous read against a restricted schema → denied.
        $this->assertFalse($this->handler->hasPermission($schema, 'read'));
    }

    public function testGroupAccessUnaffectedByOverride(): void
    {
        // jan is in kcc-team (group read) AND individually delegated update.
        $this->mockUser('jan', ['kcc-team']);
        $schema = $this->schema([
            'read'   => ['kcc-team'],
            'update' => ['user:jan'],
            'delete' => ['admin-only-group'],
        ]);

        // Group read still works.
        $this->assertTrue($this->handler->hasPermission($schema, 'read', 'jan'));
        // Delegated update works.
        $this->assertTrue($this->handler->hasPermission($schema, 'update', 'jan'));
        // Delete remains denied — the override did not widen anything.
        $this->assertFalse($this->handler->hasPermission($schema, 'delete', 'jan'));
    }

    // === Complex form with match clause (expiry / conditional delegation) ===

    public function testUserOverrideWithMatchClauseGrantsWhenConditionPasses(): void
    {
        $this->mockUser('vervanger-1', []);
        $schema = $this->schema([
            'read' => [
                ['user' => 'vervanger-1', 'match' => ['_expires' => ['$gt' => '$now']]],
            ],
        ]);

        // Object-data based conditional → delegated to ConditionMatcher.
        $this->conditionMatcher->expects($this->once())
            ->method('objectMatchesConditions')
            ->willReturn(true);

        $object = new \OCA\OpenRegister\Db\ObjectEntity();
        $object->setUuid('zaak-1');
        $object->setObject(['_expires' => '2099-01-01']);

        $this->assertTrue($this->handler->hasPermission($schema, 'read', 'vervanger-1', null, true, $object));
    }

    public function testUserOverrideWithMatchClauseDeniesWhenConditionFails(): void
    {
        $this->mockUser('vervanger-1', []);
        $schema = $this->schema([
            'read' => [
                ['user' => 'vervanger-1', 'match' => ['_expires' => ['$gt' => '$now']]],
            ],
        ]);

        $this->conditionMatcher->method('objectMatchesConditions')->willReturn(false);

        $object = new \OCA\OpenRegister\Db\ObjectEntity();
        $object->setUuid('zaak-1');
        $object->setObject(['_expires' => '2000-01-01']);

        // Expired delegation → denied (fail closed).
        $this->assertFalse($this->handler->hasPermission($schema, 'read', 'vervanger-1', null, true, $object));
    }

    public function testUserOverrideComplexFormWrongUserDenied(): void
    {
        $this->mockUser('someone-else', []);
        $schema = $this->schema([
            'read' => [
                ['user' => 'vervanger-1', 'match' => ['_expires' => ['$gt' => '$now']]],
            ],
        ]);

        // Wrong user never reaches ConditionMatcher.
        $this->conditionMatcher->expects($this->never())->method('objectMatchesConditions');

        $this->assertFalse($this->handler->hasPermission($schema, 'read', 'someone-else'));
    }

    // === checkPermission throws 403 (no widening) ===

    public function testCheckPermissionThrowsForUnauthorizedUserDespiteOverrideForAnother(): void
    {
        $this->mockUser('random', ['externals']);
        $schema = $this->schema([
            'read' => ['user:extern-adviseur'],
        ]);

        $this->expectException(NotAuthorizedException::class);
        $this->expectExceptionMessageMatches('/does not have permission/');
        $this->handler->checkPermission($schema, 'read', 'random');
    }

    // === Malformed override entries fail closed ===

    public function testMalformedUserPrefixWithBlankUidDenied(): void
    {
        $this->mockUser('', ['externals']);
        $schema = $this->schema([
            'read' => ['user:'],
        ]);

        // Blank uid must never match (fail closed).
        $this->assertFalse($this->handler->hasPermission($schema, 'read', ''));
    }
}
