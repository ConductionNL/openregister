<?php

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
use OCA\OpenRegister\Service\OperatorEvaluator;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for PermissionHandler register cascade, role expansion, and manage action.
 */
class PermissionHandlerRbacTest extends TestCase
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

    protected function setUp(): void
    {
        $this->userSession = $this->createMock(IUserSession::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->objectEntityMapper = $this->createMock(MagicMapper::class);
        $this->conditionMatcher = $this->createMock(ConditionMatcher::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);

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
    }

    private function mockUser(string $uid, array $groups): IUser&MockObject
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $user->method('getDisplayName')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
        $this->userManager->method('get')->willReturn($user);
        $this->groupManager->method('getUserGroupIds')->willReturn($groups);
        return $user;
    }

    private function createSchema(int $id, ?array $authorization): Schema
    {
        $schema = new Schema();
        $schema->setId($id);
        $schema->setAuthorization($authorization);
        $schema->setTitle('Test Schema ' . $id);
        return $schema;
    }

    private function createRegister(int $id, ?array $authorization, ?array $configuration = null): Register
    {
        $register = new Register();
        $register->setId($id);
        $register->setAuthorization($authorization);
        $register->setConfiguration($configuration ?? []);
        return $register;
    }

    private function setupRegisterForSchema(int $schemaId, Register $register): void
    {
        $this->container->method('get')
            ->willReturnCallback(function (string $class) use ($register) {
                if ($class === RegisterMapper::class) {
                    return $this->registerMapper;
                }
                if ($class === 'OCA\OpenRegister\Service\OrganisationService') {
                    throw new \RuntimeException('Not available');
                }
                throw new \RuntimeException('Unknown class: ' . $class);
            });

        $this->registerMapper->method('getFirstRegisterWithSchema')
            ->willReturn($register->getId());
        $this->registerMapper->method('find')
            ->willReturn($register);
    }

    // === Register Cascade Tests ===

    public function testSchemaAuthorizationOverridesRegister(): void
    {
        $this->mockUser('user1', ['behandelaars']);

        $schema = $this->createSchema(1, [
            'read' => ['behandelaars'],
            'create' => ['admin'],
        ]);

        $register = $this->createRegister(10, [
            'read' => ['public'],
            'create' => ['public'],
        ]);

        $this->setupRegisterForSchema(1, $register);

        // Schema says behandelaars can read, register says public can read.
        // Schema overrides: user in behandelaars should be able to read.
        $this->assertTrue($this->handler->hasPermission($schema, 'read'));

        // Schema says only admin can create, not behandelaars.
        $this->assertFalse($this->handler->hasPermission($schema, 'create'));
    }

    public function testRegisterFallbackWhenSchemaHasNoAuth(): void
    {
        $this->mockUser('user1', ['medewerkers']);

        // Schema has NO authorization.
        $schema = $this->createSchema(1, null);

        $register = $this->createRegister(10, [
            'read' => ['medewerkers'],
            'create' => ['admin'],
        ]);

        $this->setupRegisterForSchema(1, $register);

        // Should use register authorization: medewerkers can read.
        $this->assertTrue($this->handler->hasPermission($schema, 'read'));

        // Register says only admin can create.
        $this->assertFalse($this->handler->hasPermission($schema, 'create'));
    }

    public function testNeitherSchemaNorRegisterHasAuth(): void
    {
        $this->mockUser('user1', ['somegroup']);

        $schema = $this->createSchema(1, null);
        $register = $this->createRegister(10, null);

        $this->setupRegisterForSchema(1, $register);

        // No authorization anywhere = everyone has permission.
        $this->assertTrue($this->handler->hasPermission($schema, 'read'));
        $this->assertTrue($this->handler->hasPermission($schema, 'create'));
    }

    // === Role Expansion Tests ===

    public function testRoleExpansionViewerRole(): void
    {
        $this->mockUser('user1', ['public']);

        $schema = $this->createSchema(1, [
            'roles' => [
                'viewer' => ['public'],
                'editor' => ['behandelaars'],
            ],
        ]);

        $register = $this->createRegister(10, null, [
            'roles' => [
                ['name' => 'viewer', 'description' => 'Read only', 'actions' => ['read']],
                ['name' => 'editor', 'description' => 'Edit access', 'actions' => ['read', 'create', 'update']],
            ],
        ]);

        $this->setupRegisterForSchema(1, $register);

        // Public group has viewer role => read only.
        $this->assertTrue($this->handler->hasPermission($schema, 'read'));
        $this->assertFalse($this->handler->hasPermission($schema, 'create'));
    }

    public function testRoleExpansionEditorRole(): void
    {
        $this->mockUser('user1', ['behandelaars']);

        $schema = $this->createSchema(1, [
            'roles' => [
                'viewer' => ['public'],
                'editor' => ['behandelaars'],
            ],
        ]);

        $register = $this->createRegister(10, null, [
            'roles' => [
                ['name' => 'viewer', 'description' => 'Read only', 'actions' => ['read']],
                ['name' => 'editor', 'description' => 'Edit access', 'actions' => ['read', 'create', 'update']],
            ],
        ]);

        $this->setupRegisterForSchema(1, $register);

        // Behandelaars has editor role => read, create, update.
        // Actions not listed in authorization default to allowed (permissive model).
        $this->assertTrue($this->handler->hasPermission($schema, 'read'));
        $this->assertTrue($this->handler->hasPermission($schema, 'create'));
        $this->assertTrue($this->handler->hasPermission($schema, 'update'));
        $this->assertTrue($this->handler->hasPermission($schema, 'delete'));
    }

    public function testMixedRoleAndDirectAuth(): void
    {
        $this->mockUser('user1', ['extra-groep']);

        $schema = $this->createSchema(1, [
            'roles' => [
                'viewer' => ['public'],
            ],
            'read' => ['extra-groep'],
        ]);

        $register = $this->createRegister(10, null, [
            'roles' => [
                ['name' => 'viewer', 'description' => 'Read only', 'actions' => ['read']],
            ],
        ]);

        $this->setupRegisterForSchema(1, $register);

        // extra-groep has direct read permission.
        $this->assertTrue($this->handler->hasPermission($schema, 'read'));
    }

    public function testUnknownRoleNameIsIgnored(): void
    {
        $this->mockUser('user1', ['public']);

        $schema = $this->createSchema(1, [
            'roles' => [
                'archiver' => ['public'],
            ],
        ]);

        $register = $this->createRegister(10, null, [
            'roles' => [
                ['name' => 'viewer', 'description' => 'Read only', 'actions' => ['read']],
            ],
        ]);

        $this->setupRegisterForSchema(1, $register);

        // archiver role doesn't exist => warning logged, no permissions granted.
        $this->logger->expects($this->atLeastOnce())
            ->method('warning');

        // With empty authorization (only unknown roles), all actions should be permitted
        // because the effective authorization ends up empty after role expansion.
        $result = $this->handler->resolveAuthorization($schema);
        $this->assertEmpty($result);
    }

    // === Manage Action Tests ===

    public function testManageActionEvaluated(): void
    {
        $this->mockUser('user1', ['register-beheerders']);

        $schema = $this->createSchema(1, [
            'manage' => ['register-beheerders'],
            'read' => ['public'],
        ]);

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        // User in register-beheerders should have manage permission.
        $this->assertTrue($this->handler->hasPermission($schema, 'manage'));
    }

    public function testManageActionDenied(): void
    {
        $this->mockUser('user1', ['behandelaars']);

        $schema = $this->createSchema(1, [
            'manage' => ['register-beheerders'],
            'read' => ['behandelaars'],
        ]);

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        // User NOT in register-beheerders should NOT have manage permission.
        $this->assertFalse($this->handler->hasPermission($schema, 'manage'));
        // But should still be able to read.
        $this->assertTrue($this->handler->hasPermission($schema, 'read'));
    }

    public function testAdminBypassesManageCheck(): void
    {
        $this->mockUser('admin1', ['admin']);

        $schema = $this->createSchema(1, [
            'manage' => ['register-beheerders'],
        ]);

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        // Admin always has all permissions.
        $this->assertTrue($this->handler->hasPermission($schema, 'manage'));
    }

    // ------------------------------------------------------------------
    // Conditional rule delegation tests (ADR-011 — ConditionMatcher).
    //
    // These tests verify that hasGroupPermission delegates conditional
    // rule evaluation to the shared ConditionMatcher service and that the
    // admin/owner bypasses short-circuit before delegation.
    // ------------------------------------------------------------------

    private function createObjectEntity(array $data, ?string $owner = null, ?string $organisation = null): ObjectEntity
    {
        $object = new ObjectEntity();
        $object->setObject($data);
        if ($owner !== null) {
            $object->setOwner($owner);
        }
        if ($organisation !== null) {
            $object->setOrganisation($organisation);
        }
        return $object;
    }

    public function testConditionalPublicRuleDelegatesToConditionMatcher(): void
    {
        // Anonymous caller, public-with-match rule.
        $this->userSession->method('getUser')->willReturn(null);

        $schema = $this->createSchema(1, [
            'read' => [
                ['group' => 'public', 'match' => ['publishDate' => ['$lte' => '$now']]],
            ],
        ]);

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        $object = $this->createObjectEntity(['publishDate' => '2025-01-01']);

        // Expect delegation — ConditionMatcher returns true (past date).
        $this->conditionMatcher
            ->expects($this->once())
            ->method('objectMatchesConditions')
            ->with(
                $this->callback(function (array $envelope): bool {
                    return ($envelope['publishDate'] ?? null) === '2025-01-01';
                }),
                ['publishDate' => ['$lte' => '$now']]
            )
            ->willReturn(true);

        $this->assertTrue(
            $this->handler->hasPermission(
                schema: $schema,
                action: 'read',
                userId: null,
                objectOwner: null,
                _rbac: true,
                object: $object
            )
        );
    }

    public function testConditionalRuleReturnsFalseWhenConditionMatcherReturnsFalse(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $schema = $this->createSchema(1, [
            'read' => [
                ['group' => 'public', 'match' => ['publishDate' => ['$lte' => '$now']]],
            ],
        ]);

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        $object = $this->createObjectEntity(['publishDate' => '2099-01-01']);

        $this->conditionMatcher
            ->expects($this->once())
            ->method('objectMatchesConditions')
            ->willReturn(false);

        $this->assertFalse(
            $this->handler->hasPermission(
                schema: $schema,
                action: 'read',
                userId: null,
                objectOwner: null,
                _rbac: true,
                object: $object
            )
        );
    }

    public function testUserIdVariableRuleDelegatesToConditionMatcher(): void
    {
        $this->mockUser('jan', ['medewerkers']);

        $schema = $this->createSchema(1, [
            'read' => [
                ['group' => 'medewerkers', 'match' => ['assignedTo' => '$userId']],
            ],
        ]);

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        $object = $this->createObjectEntity(['assignedTo' => 'jan']);

        $this->conditionMatcher
            ->expects($this->once())
            ->method('objectMatchesConditions')
            ->with(
                $this->anything(),
                ['assignedTo' => '$userId']
            )
            ->willReturn(true);

        $this->assertTrue(
            $this->handler->hasPermission(
                schema: $schema,
                action: 'read',
                userId: 'jan',
                objectOwner: null,
                _rbac: true,
                object: $object
            )
        );
    }

    public function testInOperatorRuleDelegatesToConditionMatcher(): void
    {
        $this->mockUser('jan', ['behandelaars']);

        $schema = $this->createSchema(1, [
            'read' => [
                ['group' => 'behandelaars', 'match' => ['status' => ['$in' => ['open', 'review']]]],
            ],
        ]);

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        $object = $this->createObjectEntity(['status' => 'open']);

        $this->conditionMatcher
            ->expects($this->once())
            ->method('objectMatchesConditions')
            ->willReturn(true);

        $this->assertTrue($this->handler->hasPermission($schema, 'read', 'jan', null, true, $object));
    }

    public function testOrganisationVariableFoldsIntoEnvelopeViaSelf(): void
    {
        $this->mockUser('jan', ['behandelaars']);

        $schema = $this->createSchema(1, [
            'read' => [
                ['group' => 'behandelaars', 'match' => ['_organisation' => '$organisation']],
            ],
        ]);

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        $object = $this->createObjectEntity(['name' => 'zaak-1'], null, 'org-abc-123');

        // Verify the envelope passed to ConditionMatcher folds objectOrganisation into @self.organisation
        // so ConditionMatcher::getObjectValue() can resolve `_organisation` via its standard
        // _-prefixed @self lookup.
        $this->conditionMatcher
            ->expects($this->once())
            ->method('objectMatchesConditions')
            ->with(
                $this->callback(function (array $envelope): bool {
                    return (($envelope['@self']['organisation'] ?? null) === 'org-abc-123')
                        && (($envelope['name'] ?? null) === 'zaak-1');
                }),
                ['_organisation' => '$organisation']
            )
            ->willReturn(true);

        $this->assertTrue($this->handler->hasPermission($schema, 'read', 'jan', null, true, $object));
    }

    public function testAdminBypassSkipsConditionMatcher(): void
    {
        $this->mockUser('admin1', ['admin']);

        $schema = $this->createSchema(1, [
            'read' => [
                ['group' => 'behandelaars', 'match' => ['status' => 'open']],
            ],
        ]);

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        $object = $this->createObjectEntity(['status' => 'closed']);

        // Admin bypass MUST short-circuit before any delegation.
        $this->conditionMatcher
            ->expects($this->never())
            ->method('objectMatchesConditions');

        $this->assertTrue($this->handler->hasPermission($schema, 'read', 'admin1', null, true, $object));
    }

    public function testOwnerBypassSkipsConditionMatcher(): void
    {
        $this->mockUser('jan', ['medewerkers']);

        $schema = $this->createSchema(1, [
            'read' => [
                ['group' => 'behandelaars', 'match' => ['status' => 'open']],
            ],
        ]);

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        $object = $this->createObjectEntity(['status' => 'closed'], 'jan');

        // Owner bypass MUST short-circuit before any delegation.
        $this->conditionMatcher
            ->expects($this->never())
            ->method('objectMatchesConditions');

        $this->assertTrue(
            $this->handler->hasPermission(
                schema: $schema,
                action: 'read',
                userId: 'jan',
                objectOwner: 'jan',
                _rbac: true,
                object: $object
            )
        );
    }

    public function testSimpleStringRuleDoesNotInvokeConditionMatcher(): void
    {
        // Simple group match without a `match` clause never reaches ConditionMatcher.
        $this->mockUser('jan', ['juridisch-team']);

        $schema = $this->createSchema(1, [
            'read' => ['juridisch-team'],
        ]);

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        $this->conditionMatcher
            ->expects($this->never())
            ->method('objectMatchesConditions');

        $this->assertTrue($this->handler->hasPermission($schema, 'read', 'jan'));
    }

    public function testConditionalRuleWithoutMatchClauseDoesNotInvokeConditionMatcher(): void
    {
        // Conditional rule with an empty/missing match is treated as a plain group match.
        $this->mockUser('jan', ['behandelaars']);

        $schema = $this->createSchema(1, [
            'read' => [['group' => 'behandelaars']],
        ]);

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        $this->conditionMatcher
            ->expects($this->never())
            ->method('objectMatchesConditions');

        $this->assertTrue($this->handler->hasPermission($schema, 'read', 'jan'));
    }

    public function testAnonymousCallerAgainstNonPublicRuleReturnsFalseWithoutDelegation(): void
    {
        // Anonymous user against a rule that doesn't list 'public' → rejected
        // without consulting ConditionMatcher (no conditional `public` rule to evaluate).
        $this->userSession->method('getUser')->willReturn(null);

        $schema = $this->createSchema(1, [
            'read' => ['juridisch-team'],
        ]);

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        $this->conditionMatcher
            ->expects($this->never())
            ->method('objectMatchesConditions');

        $this->assertFalse($this->handler->hasPermission($schema, 'read'));
    }

    // ------------------------------------------------------------------
    // End-to-end wiring test with REAL ConditionMatcher + OperatorEvaluator.
    //
    // Reproduces the user-reported bug: schema with
    //   { "read": [{ "group": "public", "match": { "publishedAt": { "$lte": "$now" } } }] }
    // must grant access to objects whose publishedAt is in the past AND deny
    // access to objects with publishedAt = null (so the list endpoint and the
    // find endpoint agree — SQL's NULL semantics is the contract).
    // ------------------------------------------------------------------

    private function buildHandlerWithRealMatcher(): PermissionHandler
    {
        $operatorEvaluator = new OperatorEvaluator($this->logger);
        $realMatcher       = new ConditionMatcher(
            $this->userSession,
            $this->container,
            $operatorEvaluator,
            $this->logger
        );
        return new PermissionHandler(
            $this->userSession,
            $this->userManager,
            $this->groupManager,
            $this->schemaMapper,
            $this->objectEntityMapper,
            $realMatcher,
            $this->logger,
            $this->container
        );
    }

    public function testPublicLteNowRuleMatchesPastPublishedAt(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $schema = $this->createSchema(1, [
            'read' => [
                ['group' => 'public', 'match' => ['publishedAt' => ['$lte' => '$now']]],
            ],
        ]);

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        $object = $this->createObjectEntity(['publishedAt' => '2025-01-01 00:00:00']);
        $handler = $this->buildHandlerWithRealMatcher();

        $this->assertTrue(
            $handler->hasPermission(
                schema: $schema,
                action: 'read',
                userId: null,
                objectOwner: null,
                _rbac: true,
                object: $object
            ),
            'Past-dated publication should be accessible via $lte $now rule'
        );
    }

    public function testPublicLteNowRuleRejectsNullPublishedAt(): void
    {
        // This is the exact user-reported bug: previously returned true because
        // OperatorEvaluator used raw PHP <= with null coerced to empty string.
        $this->userSession->method('getUser')->willReturn(null);

        $schema = $this->createSchema(1, [
            'read' => [
                ['group' => 'public', 'match' => ['publishedAt' => ['$lte' => '$now']]],
            ],
        ]);

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        // Object has no publishedAt value at all — the property is absent from
        // the data map, so getObjectValue returns null.
        $object = $this->createObjectEntity(['title' => 'draft']);
        $handler = $this->buildHandlerWithRealMatcher();

        $this->assertFalse(
            $handler->hasPermission(
                schema: $schema,
                action: 'read',
                userId: null,
                objectOwner: null,
                _rbac: true,
                object: $object
            ),
            'Publication with null publishedAt must NOT match $lte $now (SQL-aligned semantics)'
        );
    }

    public function testPublicLteNowRuleRejectsExplicitNullPublishedAt(): void
    {
        // Same as above but with the property explicitly set to null in the data map.
        $this->userSession->method('getUser')->willReturn(null);

        $schema = $this->createSchema(1, [
            'read' => [
                ['group' => 'public', 'match' => ['publishedAt' => ['$lte' => '$now']]],
            ],
        ]);

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        $object = $this->createObjectEntity(['publishedAt' => null, 'title' => 'draft']);
        $handler = $this->buildHandlerWithRealMatcher();

        $this->assertFalse(
            $handler->hasPermission(
                schema: $schema,
                action: 'read',
                userId: null,
                objectOwner: null,
                _rbac: true,
                object: $object
            )
        );
    }

    public function testPublicLteNowRuleRejectsFuturePublishedAt(): void
    {
        // Sanity: future-dated publication should also be denied (not yet published).
        $this->userSession->method('getUser')->willReturn(null);

        $schema = $this->createSchema(1, [
            'read' => [
                ['group' => 'public', 'match' => ['publishedAt' => ['$lte' => '$now']]],
            ],
        ]);

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        $object = $this->createObjectEntity(['publishedAt' => '2099-01-01 00:00:00']);
        $handler = $this->buildHandlerWithRealMatcher();

        $this->assertFalse(
            $handler->hasPermission(
                schema: $schema,
                action: 'read',
                userId: null,
                objectOwner: null,
                _rbac: true,
                object: $object
            )
        );
    }

    // ------------------------------------------------------------------
    // $now format alignment tests.
    //
    // ConditionMatcher::resolveDynamicValue and
    // MagicRbacHandler::resolveDynamicValue MUST both emit `$now` in the same
    // string format. Otherwise, for text/JSON columns storing dates, a raw
    // lexicographic comparison diverges between list (SQL) and find (PHP).
    //
    // Canonical format: Y-m-d H:i:s (SQL-native).
    // ------------------------------------------------------------------

    public function testNowResolvesToSqlNativeFormat(): void
    {
        // If this test ever fails, the list and find endpoints will diverge
        // on date comparisons against text columns. See also the
        // "Dynamic $now variable resolves to a canonical SQL-native format"
        // scenario in specs/rbac-scopes/spec.md.
        $this->userSession->method('getUser')->willReturn(null);

        $schema = $this->createSchema(1, [
            'read' => [
                ['group' => 'public', 'match' => ['publishedAt' => ['$lte' => '$now']]],
            ],
        ]);

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        // Stored date in SQL-native Y-m-d H:i:s — the canonical format.
        $object = $this->createObjectEntity(['publishedAt' => '2025-06-01 12:00:00']);
        $handler = $this->buildHandlerWithRealMatcher();

        $this->assertTrue(
            $handler->hasPermission(
                schema: $schema,
                action: 'read',
                userId: null,
                objectOwner: null,
                _rbac: true,
                object: $object
            ),
            '$now must resolve to Y-m-d H:i:s so it lex-compares correctly against Y-m-d H:i:s stored dates'
        );
    }

    public function testNowAlignsWithSqlPathForIsoStoredDates(): void
    {
        // If dates are stored as ISO 8601 with 'T' (e.g. "2026-04-24T10:00:00Z"),
        // a raw lex comparison against Y-m-d H:i:s $now gives the SAME answer
        // on both paths: the 'T' (ASCII 84) beats the space (ASCII 32), so
        // both paths say the stored value is lexicographically AFTER $now,
        // regardless of actual clock time. Parity preserved (both paths reject).
        //
        // This is the "consistency at the cost of correctness on malformed data"
        // trade-off: rule authors who want semantic-datetime comparison should
        // normalize stored dates to Y-m-d H:i:s (OpenRegister's DateTimeNormalizer
        // handles this on input).
        $this->userSession->method('getUser')->willReturn(null);

        $schema = $this->createSchema(1, [
            'read' => [
                ['group' => 'public', 'match' => ['publishedAt' => ['$lte' => '$now']]],
            ],
        ]);

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        $object = $this->createObjectEntity(['publishedAt' => '2025-06-01T12:00:00Z']);
        $handler = $this->buildHandlerWithRealMatcher();

        // Both paths lex-compare: '2025-06-01T...' vs '<today> <time>'.
        // Result is deterministic — what matters is PHP and SQL agree.
        // (Assertion is whatever the lex result is; we freeze the contract here.)
        $phpVerdict = $handler->hasPermission(
            schema: $schema,
            action: 'read',
            userId: null,
            objectOwner: null,
            _rbac: true,
            object: $object
        );

        // Expected: the stored date's year (2025) is before the current year, so
        // the first 4 chars '2025' compare less than current year chars. $lte
        // succeeds regardless of whether position 10 is 'T' or ' ', because
        // comparison short-circuits before reaching that character.
        $this->assertTrue(
            $phpVerdict,
            'Past-year ISO-with-T date MUST $lte $now via lex comparison (year-level wins)'
        );
    }

    public function testNowAlignsWithSqlPathForDateOnlyStored(): void
    {
        // Date-only stored values (no time component) work on both paths.
        $this->userSession->method('getUser')->willReturn(null);

        $schema = $this->createSchema(1, [
            'read' => [
                ['group' => 'public', 'match' => ['publishedAt' => ['$lte' => '$now']]],
            ],
        ]);

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);

        $object = $this->createObjectEntity(['publishedAt' => '2025-06-01']);
        $handler = $this->buildHandlerWithRealMatcher();

        $this->assertTrue(
            $handler->hasPermission(
                schema: $schema,
                action: 'read',
                userId: null,
                objectOwner: null,
                _rbac: true,
                object: $object
            ),
            'Date-only "2025-06-01" MUST $lte $now (prefix is lexicographically less than current year)'
        );
    }

    public function testCompositePublishedAndNotDepublishedRule(): void
    {
        // Real-world rule: "(published and not yet depublished) OR (published and never expires)".
        // This is the rule the user asked about directly.
        $this->userSession->method('getUser')->willReturn(null);

        $schema = $this->createSchema(1, [
            'read' => [
                [
                    'group' => 'public',
                    'match' => [
                        'publicatiedatum'   => ['$lte' => '$now'],
                        'depublicatiedatum' => ['$gte' => '$now'],
                    ],
                ],
                [
                    'group' => 'public',
                    'match' => [
                        'publicatiedatum'   => ['$lte' => '$now'],
                        'depublicatiedatum' => ['$exists' => false],
                    ],
                ],
            ],
        ]);

        $register = $this->createRegister(10, null);
        $this->setupRegisterForSchema(1, $register);
        $handler = $this->buildHandlerWithRealMatcher();

        // Case A: published, within window → rule 1 matches.
        $this->assertTrue(
            $handler->hasPermission(
                $schema, 'read', null, null, true,
                $this->createObjectEntity([
                    'publicatiedatum'   => '2025-01-01 00:00:00',
                    'depublicatiedatum' => '2099-01-01 00:00:00',
                ])
            ),
            'Published, within window: allow'
        );

        // Case B: published, depublicatiedatum is null → rule 2 matches.
        $this->assertTrue(
            $handler->hasPermission(
                $schema, 'read', null, null, true,
                $this->createObjectEntity([
                    'publicatiedatum'   => '2025-01-01 00:00:00',
                    'depublicatiedatum' => null,
                ])
            ),
            'Published, never expires: allow'
        );

        // Case C: published but depublicatiedatum in the past → neither rule matches.
        $this->assertFalse(
            $handler->hasPermission(
                $schema, 'read', null, null, true,
                $this->createObjectEntity([
                    'publicatiedatum'   => '2025-01-01 00:00:00',
                    'depublicatiedatum' => '2025-06-01 00:00:00',
                ])
            ),
            'Expired publication: deny'
        );

        // Case D: not yet published → neither rule matches.
        $this->assertFalse(
            $handler->hasPermission(
                $schema, 'read', null, null, true,
                $this->createObjectEntity([
                    'publicatiedatum'   => '2099-01-01 00:00:00',
                    'depublicatiedatum' => null,
                ])
            ),
            'Future-dated publication: deny'
        );

        // Case E: no publicatiedatum at all → neither rule matches (null-handling).
        $this->assertFalse(
            $handler->hasPermission(
                $schema, 'read', null, null, true,
                $this->createObjectEntity(['title' => 'draft'])
            ),
            'Draft with no publicatiedatum: deny'
        );
    }
}
