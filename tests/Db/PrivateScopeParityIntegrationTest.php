<?php

/**
 * Live-database verdict parity for the `private` object scope.
 *
 * `private` is decided in FOUR places — the single-object verdict
 * ({@see \OCA\OpenRegister\Service\Object\PermissionHandler}), the relation-path
 * verdict ({@see \OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler::hasPermission()}),
 * the QueryBuilder list emitter, and the raw-SQL UNION emitter. Unit tests pin
 * each in isolation; only a real database shows that all four AGREE. A
 * disagreement is a silent access-control bug: over-filtering hides an object
 * the caller is entitled to, under-filtering leaks one they are not.
 *
 * Four things this harness has to get right, each of which produced a FALSE
 * result in the predecessor change before it was fixed:
 *
 *  1. It runs WITH a logged-in session. `applyRbacFilters()` deliberately
 *     bypasses RBAC entirely when there is no user and `PHP_SAPI === 'cli'`
 *     (occ, repair steps, cron). Sessionless, the list path returns everything,
 *     which reads as a fail-open divergence and is not one.
 *  2. The session user is NOT an admin — admins bypass RBAC outright.
 *  3. Most fixtures are owned by SOMEONE ELSE. RBAC OR-s `_owner = <uid>` into
 *     the filter, so a fixture owned by the session user is admitted whatever
 *     the scope says, masking the predicate under test. The two fixtures that
 *     ARE owned by the session user exist precisely to prove that carve-out.
 *  4. The PHP paths are fed objects READ BACK from the database, not objects
 *     built in memory alongside them. A fixture written in the shape the code
 *     expects cannot catch the code reading the wrong shape.
 *
 * The schema grants `read` to `authenticated`, so every logged-in caller is
 * admitted by the rules. That leaves the scope as the ONLY discriminator, which
 * is what makes a wrong verdict attributable.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/private-object-scope/spec.md#requirement-the-private-principal-is-honoured-identically-on-every-enforcement-path
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Db;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Rbac\ObjectGrantResolver;
use OCA\OpenRegister\Service\Rbac\ObjectSharingService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Folder;
use OCP\Constants;
use OCP\Share\IManager;
use OCP\Share\IShare;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @group DB
 */
class PrivateScopeParityIntegrationTest extends TestCase
{

    /**
     * Owner of the fixtures that are deliberately NOT the session user's.
     *
     * @var string
     */
    private const OTHER_OWNER = 'private-scope-other-owner';

    private MagicMapper $mapper;

    private MagicRbacHandler $rbacHandler;

    private PermissionHandler $permissionHandler;

    private RegisterMapper $registerMapper;

    private SchemaMapper $schemaMapper;

    private IUserSession $userSession;

    private IUserManager $userManager;

    private IGroupManager $groupManager;

    private IDBConnection $db;

    private ?IUser $testUser = null;

    private string $testUid = '';

    /**
     * A REAL second Nextcloud user, used as the owner in the grant tests.
     *
     * The scope tests own their fixtures with a bare uid string, which is enough
     * because nothing about a scope needs the owner to exist. A GRANT does: the
     * object's NC folder is created in the storage of whichever session first
     * asks for it, and core will only let a user share a node they can reach. So
     * the grant fixtures need a real owner whose session creates the folder.
     *
     * @var IUser|null
     */
    private ?IUser $ownerUser = null;

    private string $ownerUid = '';

    /**
     * A real group the session user is in, used only by the tenant-edge test.
     *
     * @var string
     */
    private string $tenantGroup = '';

    /**
     * @var int[]
     */
    private array $createdSchemaIds = [];

    /**
     * @var int[]
     */
    private array $createdRegisterIds = [];

    /**
     * @var string[]
     */
    private array $createdTables = [];

    /**
     * A second register/schema pair, needed only to force the UNION path.
     *
     * `searchAcrossMultipleTables()` falls back to the SEQUENTIAL implementation
     * — which uses the QueryBuilder emitter — unless it is given MORE THAN ONE
     * pair. Passing a single pair would have re-tested the QueryBuilder path
     * under the label of the raw-SQL one, and the matrix would have reported
     * perfect agreement between one implementation and itself.
     *
     * @var array{0: Register, 1: Schema}|null
     */
    private ?array $unionPartner = null;


    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper            = \OC::$server->get(MagicMapper::class);
        $this->rbacHandler       = \OC::$server->get(MagicRbacHandler::class);
        $this->permissionHandler = \OC::$server->get(PermissionHandler::class);
        $this->registerMapper    = \OC::$server->get(RegisterMapper::class);
        $this->schemaMapper      = \OC::$server->get(SchemaMapper::class);
        $this->userSession       = \OC::$server->get(IUserSession::class);
        $this->userManager       = \OC::$server->get(IUserManager::class);
        $this->groupManager      = \OC::$server->get(IGroupManager::class);
        $this->db                = \OC::$server->get(IDBConnection::class);

        $suffix        = substr((string) Uuid::v4(), 0, 8);
        $this->testUid = 'scope-user-'.$suffix;

        // NC enforces a minimum password length; a short one fails silently.
        $this->testUser = $this->userManager->createUser($this->testUid, 'Scope-Test-Pass-123');
        if ($this->testUser === false || $this->testUser === null) {
            $this->markTestSkipped('could not create a test user');
        }

        $this->ownerUid  = 'scope-owner-'.$suffix;
        $this->ownerUser = $this->userManager->createUser($this->ownerUid, 'Scope-Test-Pass-123');

        // A REAL group the session user belongs to. A schema whose read rule
        // names it makes hasConditionalRulesBypassingMultitenancy() true, which
        // is the only situation in which the organisation filter would otherwise
        // be SKIPPED — and therefore the only situation where the grant-forcing
        // (design D3c) is what holds the tenant edge.
        $this->tenantGroup = 'scope-group-'.$suffix;
        $group             = $this->groupManager->createGroup($this->tenantGroup);
        if ($group !== null && $this->testUser !== null) {
            $group->addUser($this->testUser);
        }

        $this->userSession->setUser($this->testUser);

        if ($this->rbacHandler->isAdmin() === true) {
            $this->markTestSkipped('the test user resolved as admin, which bypasses RBAC');
        }
    }//end setUp()


    protected function tearDown(): void
    {
        $this->userSession->setUser(null);

        if ($this->testUser !== null) {
            $this->testUser->delete();
        }

        if ($this->ownerUser !== null) {
            $this->ownerUser->delete();
        }

        $group = $this->groupManager->get($this->tenantGroup);
        if ($group !== null) {
            $group->delete();
        }

        foreach ($this->createdTables as $tableName) {
            try {
                $this->db->prepare("DROP TABLE IF EXISTS $tableName")->execute();
            } catch (\Exception $e) {
                // Table may not exist.
            }
        }

        foreach ($this->createdSchemaIds as $id) {
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->delete('openregister_schemas')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            } catch (\Exception $e) {
                // Already cleaned up.
            }
        }

        foreach ($this->createdRegisterIds as $id) {
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->delete('openregister_registers')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            } catch (\Exception $e) {
                // Already cleaned up.
            }
        }

        parent::tearDown();
    }//end tearDown()


    /**
     * The fixture matrix: name => [authorization, ownedBySessionUser, expected].
     *
     * @return array<string, array{0: array<string, mixed>|null, 1: bool, 2: bool}>
     */
    private function fixtureMatrix(): array
    {
        return [
            // The opt-in guarantee: nothing declared, decided as it always was.
            'no authorization at all'         => [null, false, true],
            'block with no scope key'         => [['inheritFromPublic' => true], false, true],
            'explicit organisation'           => [['scope' => 'organisation'], false, true],

            // The capability itself.
            'private, owned by someone else'  => [['scope' => 'private'], false, false],
            'private, owned by the caller'    => [['scope' => 'private'], true, true],

            // Fail-closed on a value nobody recognises — but never at the
            // owner's expense, which is the lock-yourself-out failure mode.
            'unrecognised scope'              => [['scope' => 'typo'], false, false],
            'unrecognised, owned by caller'   => [['scope' => 'typo'], true, true],
            'scope is not even a string'      => [['scope' => true], false, false],

            // An empty declaration is UNSET, not unrecognised — it must fall
            // through rather than fail closed, or clearing a field in a UI
            // would silently hide the object.
            'scope declared empty'            => [['scope' => ''], false, true],
        ];
    }//end fixtureMatrix()


    /**
     * Every fixture yields the same verdict from all four paths, and it is correct.
     */
    public function testPrivateScopeVerdictsAgreeAcrossAllFourPaths(): void
    {
        [$register, $schema] = $this->createFixtureTable();

        $fixtures = $this->fixtureMatrix();
        foreach ($fixtures as $label => $case) {
            [$authorization, $ownedByCaller] = $case;
            $this->insertFixture($register, $schema, $this->keyFor($label), $authorization, $ownedByCaller);
        }

        // Read the rows BACK, so the PHP paths see what the database holds
        // rather than what this test believes it wrote.
        $stored = $this->readBackByKey($register, $schema);

        $queryBuilderVisible = $this->visibleKeys($register, $schema);
        $unionVisible        = $this->visibleKeysViaUnion($register, $schema);

        $this->assertRawEmitterCarriesTheScopePredicate($schema);

        $disagreements = [];
        $wrongVerdicts = [];

        foreach ($fixtures as $label => $case) {
            $key      = $this->keyFor($label);
            $expected = $case[2];

            $this->assertArrayHasKey($key, $stored, "fixture '$label' was not persisted");
            $entity = $stored[$key];

            $verdicts = [
                'PermissionHandler' => $this->permissionHandler->hasPermission(
                    schema: $schema,
                    action: 'read',
                    userId: $this->testUid,
                    objectOwner: $entity->getOwner(),
                    object: $entity
                ),
                'hasPermission'     => $this->rbacHandler->hasPermission(
                    schema: $schema,
                    action: 'read',
                    objectOwner: $entity->getOwner(),
                    objectData: ($entity->getObject() ?? []),
                    objectAuthorization: $entity->getAuthorization()
                ),
                'list (QueryBuilder)' => in_array($key, $queryBuilderVisible, true),
                'list (raw SQL UNION)' => in_array($key, $unionVisible, true),
            ];

            $distinct = array_unique(array_values($verdicts), SORT_REGULAR);
            if (count($distinct) > 1) {
                $rendered = [];
                foreach ($verdicts as $path => $verdict) {
                    $rendered[] = $path.'='.var_export($verdict, true);
                }

                $disagreements[] = $label.': '.implode(' ', $rendered);
            }

            foreach ($verdicts as $path => $verdict) {
                if ($verdict !== $expected) {
                    $wrongVerdicts[] = sprintf(
                        '%s [%s]: expected %s, got %s',
                        $label,
                        $path,
                        var_export($expected, true),
                        var_export($verdict, true)
                    );
                }
            }
        }//end foreach

        $this->assertSame(
            [],
            $disagreements,
            "Enforcement paths disagreed:\n  ".implode("\n  ", $disagreements)
        );

        $this->assertSame(
            [],
            $wrongVerdicts,
            "A verdict was wrong:\n  ".implode("\n  ", $wrongVerdicts)
        );
    }//end testPrivateScopeVerdictsAgreeAcrossAllFourPaths()


    /**
     * A schema whose DEFAULT is private hides its objects, and an object may
     * still put itself back — a default is not a ceiling.
     */
    public function testSchemaDefaultIsPrivateAndAnObjectCanOptBackOut(): void
    {
        [$register, $schema] = $this->createFixtureTable(schemaScope: 'private');

        $this->insertFixture($register, $schema, 'inherits-default', null, false);
        $this->insertFixture($register, $schema, 'opts-back-out', ['scope' => 'organisation'], false);
        $this->insertFixture($register, $schema, 'owned-by-caller', null, true);

        $visible = $this->visibleKeys($register, $schema);
        $union   = $this->visibleKeysViaUnion($register, $schema);

        foreach (['QueryBuilder' => $visible, 'UNION' => $union] as $path => $keys) {
            $this->assertNotContains('inherits-default', $keys, "[$path] the schema default must hide it");
            $this->assertContains('opts-back-out', $keys, "[$path] an explicit organisation scope must win");
            $this->assertContains('owned-by-caller', $keys, "[$path] the owner is admitted whatever the default");
        }
    }//end testSchemaDefaultIsPrivateAndAnObjectCanOptBackOut()


    /**
     * An object may declare itself private on a schema with NO authorization
     * block at all.
     *
     * That path used to return unfiltered — the schema is "open to all" — so
     * without this the scope would be honoured on configured schemas and
     * silently ignored on unconfigured ones, which are the schemas nobody is
     * watching.
     */
    public function testPrivateIsHonouredOnASchemaWithNoAuthorizationBlock(): void
    {
        [$register, $schema] = $this->createFixtureTable(withAuthorization: false);

        $this->insertFixture($register, $schema, 'open', null, false);
        $this->insertFixture($register, $schema, 'hidden', ['scope' => 'private'], false);
        $this->insertFixture($register, $schema, 'mine', ['scope' => 'private'], true);

        foreach (
            [
                'QueryBuilder' => $this->visibleKeys($register, $schema),
                'UNION'        => $this->visibleKeysViaUnion($register, $schema),
            ] as $path => $keys
        ) {
            $this->assertContains('open', $keys, "[$path] an ordinary object stays visible");
            $this->assertNotContains('hidden', $keys, "[$path] a private object must not leak on an open schema");
            $this->assertContains('mine', $keys, "[$path] the owner still reaches their own");
        }
    }//end testPrivateIsHonouredOnASchemaWithNoAuthorizationBlock()


    /**
     * FINDING, not a specification: a per-object ACTION override is honoured by
     * the single-object verdict and ignored by both list emitters.
     *
     * `_authorization` has carried per-object ACTION rules since Wave-12 Fix 5,
     * and `PermissionHandler::resolveAuthorization()` layers them over the
     * schema. Neither list emitter passes an object to that resolver
     * (`resolveSchemaAuthorization()` calls it with no object), and
     * `MagicRbacHandler::hasPermission()` reads `$schema->getAuthorization()`
     * directly — so an object that narrows its own `read` to `["admin"]` is
     * denied on `find` and RETURNED by `list`.
     *
     * This change does not fix that. It adds the `scope` key to the same column
     * and honours THAT key on all four paths; making the action overrides
     * row-level in SQL is a separate piece of work. The behaviour is pinned here
     * so it is visible rather than folklore — when it is fixed, this test fails,
     * which is exactly the signal wanted.
     *
     * @see tasks.md group 4
     */
    public function testPerObjectActionOverrideIsNotYetHonouredByTheListPaths(): void
    {
        [$register, $schema] = $this->createFixtureTable();

        $this->insertFixture($register, $schema, 'narrowed', ['read' => ['admin']], false);

        $entity = $this->readBackByKey($register, $schema)['narrowed'];
        $this->assertSame(['read' => ['admin']], $entity->getAuthorization(), 'fixture did not persist');

        $singleObject = $this->permissionHandler->hasPermission(
            schema: $schema,
            action: 'read',
            userId: $this->testUid,
            objectOwner: $entity->getOwner(),
            object: $entity
        );

        $this->assertFalse($singleObject, 'the single-object verdict honours the per-object override');
        $this->assertContains(
            'narrowed',
            $this->visibleKeys($register, $schema),
            'DIVERGENCE (pre-existing): the list path ignores the per-object action override'
        );
        $this->assertContains(
            'narrowed',
            $this->visibleKeysViaUnion($register, $schema),
            'DIVERGENCE (pre-existing): the UNION path ignores the per-object action override'
        );
    }//end testPerObjectActionOverrideIsNotYetHonouredByTheListPaths()

    /**
     * POSITIVE CONTROL: the harness can actually detect a leak.
     *
     * A parity matrix in which every path returns everything satisfies its own
     * agreement assertion while proving nothing. This asserts that the list
     * queries genuinely discriminate — if the predicate were dropped, this fails
     * even though the matrix above might not.
     */
    public function testTheListQueriesActuallyFilter(): void
    {
        [$register, $schema] = $this->createFixtureTable();

        $this->insertFixture($register, $schema, 'visible', null, false);
        $this->insertFixture($register, $schema, 'private', ['scope' => 'private'], false);

        $queryBuilder = $this->visibleKeys($register, $schema);
        $union        = $this->visibleKeysViaUnion($register, $schema);

        $this->assertContains('visible', $queryBuilder);
        $this->assertNotContains('private', $queryBuilder);

        $this->assertContains('visible', $union);
        $this->assertNotContains('private', $union);

        $this->assertRawEmitterCarriesTheScopePredicate($schema);

        // And the fixture really is on disk in the shape the predicate reads —
        // a NULL column would make the assertion above pass for the wrong reason.
        $this->assertSame(
            ['scope' => 'private'],
            $this->readBackByKey($register, $schema)['private']->getAuthorization()
        );
    }//end testTheListQueriesActuallyFilter()


    /**
     * `_rbac: false` must bypass the private scope, or every trigger stops firing.
     *
     * This is the load-bearing safety property of making flows private by default
     * (task 9.1). The flow engine never runs in the caller's authority: it runs a
     * flow AS ITS OWNER, and triggers, scheduled runs and background jobs have no
     * session at all to evaluate against. So all four OpenRegisterFlowResolver
     * paths — resolveFlow(), resolveSubject(), scheduledFlows() and
     * buildTriggerIndex() — load with `_rbac: false` on purpose.
     *
     * If the scope predicate were applied OUTSIDE that gate, making flows private
     * would silently empty the trigger index: every trigger in the fleet would
     * stop firing, with no error anywhere, because a trigger that matches nothing
     * is indistinguishable from a trigger that matched and did nothing.
     *
     * The bypass half of this test is vacuous on its own — "the object is visible"
     * proves nothing if the object was never private. So the control runs FIRST,
     * on the same row in the same table: with RBAC on it must be absent. Only then
     * does its presence under `_rbac: false` mean the bypass is what admitted it.
     *
     * The QueryBuilder emitter is the correct path to assert here, and
     * deliberately not the UNION one: flows live in exactly one register/schema
     * pair, and shouldUseUnionQuery() needs more than one pair, so the raw-SQL
     * emitter is never the one a flow lookup reaches.
     *
     * @return void
     *
     * @spec openspec/changes/object-level-sharing-and-private-scope/specs/private-object-scope/spec.md#requirement-the-private-principal-is-honoured-identically-on-every-enforcement-path
     */
    public function testRbacFalseBypassesThePrivateScopeSoTheFlowEngineStillResolves(): void
    {
        [$register, $schema] = $this->createFixtureTable();

        $this->insertFixture($register, $schema, 'engine-visible', null, false);
        $this->insertFixture($register, $schema, 'engine-private', ['scope' => 'private'], false);

        // CONTROL: with RBAC on, the private row is genuinely hidden. Without
        // this the bypass assertion below would pass for a row that was never
        // private in the first place.
        $enforced = $this->visibleKeys($register, $schema);
        $this->assertContains('engine-visible', $enforced);
        $this->assertNotContains(
            'engine-private',
            $enforced,
            'Control failed: the row is not actually private, so the bypass assertion would be vacuous.'
        );

        // The engine path: same table, same row, RBAC disabled.
        $asEngine = $this->keysOf(
            $this->mapper->searchObjectsInRegisterSchemaTable(
                ['_multitenancy' => false, '_rbac' => false],
                $register,
                $schema
            )
        );

        $this->assertContains(
            'engine-private',
            $asEngine,
            'A private object is invisible to _rbac:false — triggers and scheduled runs would silently stop.'
        );
        $this->assertContains('engine-visible', $asEngine);
    }//end testRbacFalseBypassesThePrivateScopeSoTheFlowEngineStillResolves()


    /**
     * REGRESSION: an ordinary update must not destroy per-object authorization.
     *
     * `prepareObjectDataForTable()` strips `authorization` from the incoming
     * metadata, because per-object RBAC is deliberately not writable by ordinary
     * create/update calls. But the field was also listed in the metadata-column
     * map, and that loop resolves every listed field as `$metadata[$field] ?? null`
     * — so the stripped key came back as an explicit NULL and the UPDATE wrote it.
     *
     * The effect was that a private object became visible again as soon as
     * ANYTHING saved it. Resolving an object's folder does exactly that, so
     * sharing an object — the operation this whole capability exists for — was
     * enough to un-private it. The same wipe hit the per-object action overrides
     * shipped as Wave-12 Fix 5.
     *
     * This drives the REAL writer rather than asserting on a hand-built payload:
     * a fixture in the shape the code expects cannot catch the code writing the
     * wrong shape.
     */
    public function testAnUpdateDoesNotDestroyPerObjectAuthorization(): void
    {
        [$register, $schema] = $this->createFixtureTable();

        $this->insertFixture($register, $schema, 'survives', ['scope' => 'private'], false, ownedByRealOwner: true);

        $entity = $this->readBackByKey($register, $schema)['survives'];
        $this->assertSame(['scope' => 'private'], $entity->getAuthorization(), 'fixture did not persist');

        // Resolving the folder calls update() — the real production writer.
        $this->objectFolderAsOwner($entity);

        $this->assertSame(
            ['scope' => 'private'],
            $this->readBackByKey($register, $schema)['survives']->getAuthorization(),
            'an update wiped the per-object authorization'
        );

        // And the consequence that made it matter: still invisible to a caller
        // who is neither owner nor invited.
        $this->assertNotContains('survives', $this->visibleKeys($register, $schema));
        $this->assertNotContains('survives', $this->visibleKeysViaUnion($register, $schema));
    }//end testAnUpdateDoesNotDestroyPerObjectAuthorization()


    /**
     * A grant on a private object admits the invited caller — on all four paths.
     *
     * This is the whole point of the capability: the object answers to nobody but
     * its owner, and one named principal is let back in.
     */
    public function testAGrantAdmitsTheInvitedCallerOnEveryPath(): void
    {
        [$register, $schema] = $this->createFixtureTable();

        $this->insertFixture($register, $schema, 'granted', ['scope' => 'private'], false, ownedByRealOwner: true);
        $this->insertFixture($register, $schema, 'not-granted', ['scope' => 'private'], false, ownedByRealOwner: true);

        $entities = $this->readBackByKey($register, $schema);
        $this->grantTo($entities['granted'], $this->testUid);

        $this->assertContains('granted', $this->visibleKeys($register, $schema));
        $this->assertNotContains('not-granted', $this->visibleKeys($register, $schema));

        $this->assertContains('granted', $this->visibleKeysViaUnion($register, $schema));
        $this->assertNotContains('not-granted', $this->visibleKeysViaUnion($register, $schema));

        $this->assertTrue(
            $this->permissionHandler->hasPermission(
                schema: $schema,
                action: 'read',
                userId: $this->testUid,
                objectOwner: $entities['granted']->getOwner(),
                object: $entities['granted']
            ),
            'the single-object verdict must honour the grant'
        );

        $this->assertTrue(
            $this->rbacHandler->hasPermission(
                schema: $schema,
                action: 'read',
                objectOwner: $entities['granted']->getOwner(),
                objectData: ($entities['granted']->getObject() ?? []),
                objectAuthorization: $entities['granted']->getAuthorization(),
                objectUuid: $entities['granted']->getUuid()
            ),
            'the relation-path verdict must honour the grant'
        );
    }//end testAGrantAdmitsTheInvitedCallerOnEveryPath()


    /**
     * Revoking a grant denies on the next request — no cache, no job.
     *
     * `forget()` is what a NEW REQUEST does: the resolver memoises for the
     * lifetime of one request and stores nothing beyond it, which is what makes
     * "denies on the next request" true by construction rather than by a
     * cache-invalidation rule somebody has to remember (design D2). Calling it
     * here simulates the next request inside one PHP process.
     */
    public function testRevokingAGrantDeniesOnTheNextRequest(): void
    {
        [$register, $schema] = $this->createFixtureTable();

        $this->insertFixture($register, $schema, 'revocable', ['scope' => 'private'], false, ownedByRealOwner: true);

        $entity = $this->readBackByKey($register, $schema)['revocable'];
        $share  = $this->grantTo($entity, $this->testUid);

        $this->assertContains('revocable', $this->visibleKeys($register, $schema), 'granted first');

        $this->shareManager()->deleteShare($share);
        $this->grantResolver()->forget();

        $this->assertNotContains(
            'revocable',
            $this->visibleKeys($register, $schema),
            'a revoked grant must deny on the next request'
        );
        $this->assertNotContains('revocable', $this->visibleKeysViaUnion($register, $schema));
    }//end testRevokingAGrantDeniesOnTheNextRequest()


    /**
     * A grant CANNOT widen past the schema — the schema is the ceiling.
     *
     * The spec is explicit: "a schema's rules would refuse a user an action, and
     * a private object of that schema invites them for it — the request is still
     * denied". A grant re-opens a private row WITHIN what the rules allow; it is
     * not an independent admit path (design D3b).
     */
    public function testAGrantCannotWidenPastTheSchema(): void
    {
        // A schema whose read rule names a group the caller is not in, so the
        // ceiling excludes them however they are invited.
        [$register, $schema] = $this->createFixtureTable(readRule: 'a-group-the-caller-is-not-in');

        $this->insertFixture($register, $schema, 'refused', ['scope' => 'private'], false, ownedByRealOwner: true);

        $entity = $this->readBackByKey($register, $schema)['refused'];
        $this->grantTo($entity, $this->testUid);

        $this->assertNotContains(
            'refused',
            $this->visibleKeys($register, $schema),
            'a grant must not admit somebody the schema refuses'
        );
        $this->assertNotContains('refused', $this->visibleKeysViaUnion($register, $schema));

        $this->assertFalse(
            $this->permissionHandler->hasPermission(
                schema: $schema,
                action: 'read',
                userId: $this->testUid,
                objectOwner: $entity->getOwner(),
                object: $entity
            ),
            'the single-object verdict must not let a grant widen past the schema'
        );
    }//end testAGrantCannotWidenPastTheSchema()


    /**
     * A share on a FILE inside the object's folder is NOT an object grant.
     *
     * File shares are a separate, pre-existing concept served by
     * `ShareLinkService`, and they must stay separate — otherwise attaching a
     * document to an object and sharing that document would silently hand over
     * the object's data too.
     */
    public function testAFileShareInsideTheFolderIsNotAnObjectGrant(): void
    {
        [$register, $schema] = $this->createFixtureTable();

        $this->insertFixture($register, $schema, 'hasfile', ['scope' => 'private'], false, ownedByRealOwner: true);
        $entity = $this->readBackByKey($register, $schema)['hasfile'];

        $folder = $this->objectFolderAsOwner($entity);
        if ($folder === null) {
            $this->markTestSkipped('could not resolve the object folder');
        }

        $this->userSession->setUser($this->ownerUser);
        try {
            $file  = $folder->newFile('attachment.txt', 'contents');
            $share = $this->shareManager()->newShare();
            $share->setNode($file);
            $share->setShareType(IShare::TYPE_USER);
            $share->setSharedWith($this->testUid);
            $share->setSharedBy($this->ownerUid);
            $share->setPermissions(1);
            $this->shareManager()->createShare($share);
        } finally {
            $this->userSession->setUser($this->testUser);
        }

        $this->grantResolver()->forget();

        $this->assertSame(
            [],
            $this->grantResolver()->grantedObjectUuids($this->testUid),
            'a file share must not register as an object grant'
        );
        $this->assertNotContains('hasfile', $this->visibleKeys($register, $schema));
    }//end testAFileShareInsideTheFolderIsNotAnObjectGrant()


    /**
     * Grant one object, then assert the caller can still not see a DIFFERENT
     * private object of the same schema.
     *
     * Guards against a predicate that admits every private row as soon as the
     * caller holds any grant at all — which would pass every test above.
     */
    public function testAGrantIsScopedToTheObjectItNames(): void
    {
        [$register, $schema] = $this->createFixtureTable();

        $this->insertFixture($register, $schema, 'mine-by-grant', ['scope' => 'private'], false, ownedByRealOwner: true);
        $this->insertFixture($register, $schema, 'somebody-elses', ['scope' => 'private'], false, ownedByRealOwner: true);

        $entities = $this->readBackByKey($register, $schema);
        $this->grantTo($entities['mine-by-grant'], $this->testUid);

        $visible = $this->visibleKeys($register, $schema);
        $this->assertContains('mine-by-grant', $visible);
        $this->assertNotContains('somebody-elses', $visible);
    }//end testAGrantIsScopedToTheObjectItNames()


    /**
     * Create a real core share of an object's folder, as its real owner.
     *
     * @param ObjectEntity $entity    The object to grant.
     * @param string       $recipient Uid to grant it to.
     *
     * @return IShare The created share.
     */
    private function grantTo(ObjectEntity $entity, string $recipient): IShare
    {
        $folder = $this->objectFolderAsOwner($entity);
        if ($folder === null) {
            $this->markTestSkipped('could not resolve the object folder');
        }

        $this->userSession->setUser($this->ownerUser);
        try {
            $share = $this->shareManager()->newShare();
            $share->setNode($folder);
            $share->setShareType(IShare::TYPE_USER);
            $share->setSharedWith($recipient);
            $share->setSharedBy($this->ownerUid);
            $share->setPermissions(1);
            $created = $this->shareManager()->createShare($share);
        } finally {
            $this->userSession->setUser($this->testUser);
        }

        // The grant was made after this request's resolver had already answered,
        // so drop the memoisation — a real next request starts with a fresh one.
        $this->grantResolver()->forget();

        return $created;
    }//end grantTo()


    /**
     * Resolve an object's NC folder while logged in as its real owner.
     *
     * The folder is created in the storage of whichever session asks for it
     * first, and core only lets a user share a node they can reach — so this
     * must run as the owner, not as the recipient.
     *
     * @param ObjectEntity $entity The object.
     *
     * @return Folder|null The folder, or null when it cannot be resolved.
     */
    private function objectFolderAsOwner(ObjectEntity $entity): ?Folder
    {
        $this->userSession->setUser($this->ownerUser);
        try {
            $folder = \OC::$server->get(FolderManagementHandler::class)->getObjectFolder($entity);
            if (($folder instanceof Folder) === true) {
                return $folder;
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        } finally {
            $this->userSession->setUser($this->testUser);
        }
    }//end objectFolderAsOwner()


    /**
     * Core's share manager.
     *
     * @return IManager
     */
    private function shareManager(): IManager
    {
        return \OC::$server->get(IManager::class);
    }//end shareManager()


    /**
     * The one per-request grant resolver.
     *
     * @return ObjectGrantResolver
     */
    private function grantResolver(): ObjectGrantResolver
    {
        return \OC::$server->get(ObjectGrantResolver::class);
    }//end grantResolver()


    /**
     * The owner-checked write surface.
     *
     * @return ObjectSharingService
     */
    private function sharingService(): ObjectSharingService
    {
        return \OC::$server->get(ObjectSharingService::class);
    }//end sharingService()


    /**
     * An OWNER can make their own object private — the whole point of task 4.0.
     *
     * Without this the capability is admin-only and unreachable for a real user:
     * `stripSelfInjectionFields()` removes `authorization` from every non-admin
     * write, and the object write path omits the column entirely. So the scope
     * needs its own owner-checked entry point, and this proves it works end to
     * end — the write lands AND the consequence follows.
     */
    public function testAnOwnerCanMakeTheirOwnObjectPrivate(): void
    {
        [$register, $schema] = $this->createFixtureTable();

        $this->insertFixture($register, $schema, 'ownerturns', null, false, ownedByRealOwner: true);
        $entity = $this->readBackByKey($register, $schema)['ownerturns'];

        // Visible to the other user first — this is an ordinary object.
        $this->assertContains('ownerturns', $this->visibleKeys($register, $schema));

        $this->asOwner(
            function () use ($register, $schema, $entity): void {
                $this->sharingService()->setScope(
                    register: $register,
                    schema: $schema,
                    object: $entity,
                    scope: 'private'
                );
            }
        );

        $this->assertSame(
            ['scope' => 'private'],
            $this->readBackByKey($register, $schema)['ownerturns']->getAuthorization(),
            'the owner-set scope did not persist'
        );

        $this->assertNotContains(
            'ownerturns',
            $this->visibleKeys($register, $schema),
            'the object should now be hidden from a non-owner'
        );
        $this->assertNotContains('ownerturns', $this->visibleKeysViaUnion($register, $schema));
    }//end testAnOwnerCanMakeTheirOwnObjectPrivate()


    /**
     * Somebody who merely CAN READ an object cannot change its scope.
     *
     * The read guard admits them — that is what makes this worth asserting. The
     * second guard, owner-or-admin, is what refuses.
     */
    public function testANonOwnerCannotChangeTheScope(): void
    {
        [$register, $schema] = $this->createFixtureTable();

        $this->insertFixture($register, $schema, 'notyours', null, false, ownedByRealOwner: true);
        $entity = $this->readBackByKey($register, $schema)['notyours'];

        // The session user can READ it, and still must not be able to re-scope it.
        $this->assertContains('notyours', $this->visibleKeys($register, $schema));

        $this->expectException(NotAuthorizedException::class);
        $this->sharingService()->setScope(
            register: $register,
            schema: $schema,
            object: $entity,
            scope: 'private'
        );
    }//end testANonOwnerCannotChangeTheScope()


    /**
     * Setting the scope preserves an admin-set ACTION override in the same block.
     *
     * `scope` and the action lists share one JSON column, and they have different
     * privilege: an owner may narrow the scope, only an admin may change the
     * action lists. A blind overwrite would let an owner silently drop an
     * admin's `{"read": ["admin"]}` seal by toggling their own scope.
     */
    public function testSettingTheScopePreservesAnAdminSetActionOverride(): void
    {
        [$register, $schema] = $this->createFixtureTable();

        $this->insertFixture(
            $register,
            $schema,
            'sealed',
            ['read' => ['admin']],
            false,
            ownedByRealOwner: true
        );
        $entity = $this->readBackByKey($register, $schema)['sealed'];

        $this->asOwner(
            function () use ($register, $schema, $entity): void {
                $this->sharingService()->setScope(
                    register: $register,
                    schema: $schema,
                    object: $entity,
                    scope: 'private'
                );
            }
        );

        $this->assertSame(
            ['read' => ['admin'], 'scope' => 'private'],
            $this->readBackByKey($register, $schema)['sealed']->getAuthorization(),
            'the admin-set action override was lost when the owner set the scope'
        );
    }//end testSettingTheScopePreservesAnAdminSetActionOverride()


    /**
     * The owner can grant and then revoke through the service, and the verdict
     * follows on both list paths.
     *
     * The earlier grant tests drive core's share manager directly. This drives
     * the SERVICE — the thing a real request actually calls — so a broken
     * owner-check or folder resolve in it cannot pass unnoticed.
     */
    public function testGrantAndRevokeThroughTheService(): void
    {
        [$register, $schema] = $this->createFixtureTable();

        $this->insertFixture($register, $schema, 'viaservice', ['scope' => 'private'], false, ownedByRealOwner: true);
        $entity = $this->readBackByKey($register, $schema)['viaservice'];

        $this->assertNotContains('viaservice', $this->visibleKeys($register, $schema), 'private first');

        $grant = $this->asOwner(
            fn() => $this->sharingService()->grant(
                object: $entity,
                type: 'user',
                shareWith: $this->testUid,
                permissions: 1
            )
        );

        $this->grantResolver()->forget();
        $this->assertContains('viaservice', $this->visibleKeys($register, $schema), 'the grant should admit');
        $this->assertContains('viaservice', $this->visibleKeysViaUnion($register, $schema));

        $this->asOwner(
            function () use ($entity, $grant): void {
                $this->sharingService()->revoke(object: $entity, shareId: (string) $grant['id']);
            }
        );

        $this->grantResolver()->forget();
        $this->assertNotContains(
            'viaservice',
            $this->visibleKeys($register, $schema),
            'a revoked grant must deny on the next request'
        );
    }//end testGrantAndRevokeThroughTheService()


    /**
     * A RECIPIENT cannot add another principal to the object (task 4.4).
     *
     * The spec: "A recipient SHALL NOT be able to widen a grant, add a principal,
     * or re-share onward."
     *
     * The control matters more than the refusal here. A stranger would also be
     * refused, and that would prove nothing about recipients — so the caller is
     * first GRANTED read and confirmed to actually see the object. Only then does
     * the refusal mean "a legitimate recipient cannot re-share", rather than
     * "someone who cannot reach the object at all cannot share it".
     *
     * @return void
     *
     * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md#scenario-a-recipient-cannot-re-share
     */
    public function testARecipientCannotAddAnotherPrincipal(): void
    {
        [$register, $schema] = $this->createFixtureTable();

        $this->insertFixture($register, $schema, 'noreshare', ['scope' => 'private'], false, ownedByRealOwner: true);
        $entity = $this->readBackByKey($register, $schema)['noreshare'];

        // The owner invites this caller, so they are a genuine recipient.
        $this->asOwner(
            fn() => $this->sharingService()->grant(
                object: $entity,
                type: 'user',
                shareWith: $this->testUid,
                permissions: 1
            )
        );
        $this->grantResolver()->forget();

        // CONTROL: they really are a recipient — they can see the object.
        $this->assertContains(
            'noreshare',
            $this->visibleKeys($register, $schema),
            'control: the caller must be an admitted recipient, or the refusal below proves nothing'
        );

        // And still cannot pass it on.
        $this->expectException(NotAuthorizedException::class);
        $this->sharingService()->grant(
            object: $entity,
            type: 'user',
            shareWith: 'somebody-else',
            permissions: 1
        );
    }//end testARecipientCannotAddAnotherPrincipal()


    /**
     * A grant never carries core's re-share bit (task 4.4, second half).
     *
     * `requireOwnerOrAdmin()` stops a recipient using OUR endpoints, but a grant
     * IS a share on the object's folder, and core's Files UI acts on that folder
     * directly. With `PERMISSION_SHARE` set, the recipient could re-share the
     * folder through core — and because the resolver reads grants from exactly
     * those folder shares, the result would be a valid object grant created by
     * someone who was never allowed to create one. The API guard would be intact
     * and the property still false.
     *
     * The control is asserting that the OTHER requested bits survive. Without it,
     * a mask of 0 — or a clamp that zeroed everything — would pass just as well.
     *
     * @return void
     *
     * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md#scenario-a-recipient-cannot-re-share
     */
    public function testAGrantNeverCarriesCoresReshareBit(): void
    {
        [$register, $schema] = $this->createFixtureTable();

        $this->insertFixture($register, $schema, 'nobit', ['scope' => 'private'], false, ownedByRealOwner: true);
        $entity = $this->readBackByKey($register, $schema)['nobit'];

        // Ask for read + update + share. Only the share bit may be dropped.
        $requested = (Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE | Constants::PERMISSION_SHARE);

        $grant = $this->asOwner(
            fn() => $this->sharingService()->grant(
                object: $entity,
                type: 'user',
                shareWith: $this->testUid,
                permissions: $requested
            )
        );

        $granted = (int) $grant['permissions'];

        $this->assertSame(
            0,
            ($granted & Constants::PERMISSION_SHARE),
            'a grant must never delegate re-sharing — the recipient could otherwise pass the object on via core Files'
        );

        // CONTROLS: the clamp is surgical, not a blanket zero.
        $this->assertSame(
            Constants::PERMISSION_READ,
            ($granted & Constants::PERMISSION_READ),
            'control: read must survive the clamp'
        );
        $this->assertSame(
            Constants::PERMISSION_UPDATE,
            ($granted & Constants::PERMISSION_UPDATE),
            'control: update must survive the clamp'
        );

        // And the share on disk carries the clamped mask, not the requested one —
        // asserting only the returned array would miss a clamp applied after the
        // share was already created.
        $stored = $this->shareManager()->getShareById((string) $grant['id']);
        $this->assertSame(
            0,
            ($stored->getPermissions() & Constants::PERMISSION_SHARE),
            'the PERSISTED share must not carry the re-share bit either'
        );
    }//end testAGrantNeverCarriesCoresReshareBit()


    /**
     * A grant's PERMISSION gates the action (task 4.5).
     *
     * The resolver has always carried core's bitmask; until this landed nothing
     * consumed it, so a read-only invitation silently admitted `delete` too. The
     * bit is now required per action, on every path.
     */
    public function testAReadOnlyGrantDoesNotAdmitAWrite(): void
    {
        [$register, $schema] = $this->createFixtureTable();

        $this->insertFixture($register, $schema, 'readonly', ['scope' => 'private'], false, ownedByRealOwner: true);
        $entity = $this->readBackByKey($register, $schema)['readonly'];

        // PERMISSION_READ only.
        $this->grantTo($entity, $this->testUid);

        $this->assertTrue(
            $this->permissionHandler->hasPermission(
                schema: $schema,
                action: 'read',
                userId: $this->testUid,
                objectOwner: $entity->getOwner(),
                object: $entity
            ),
            'a read grant must admit read'
        );

        foreach (['update', 'delete'] as $write) {
            $this->assertFalse(
                $this->permissionHandler->hasPermission(
                    schema: $schema,
                    action: $write,
                    userId: $this->testUid,
                    objectOwner: $entity->getOwner(),
                    object: $entity
                ),
                "a read-only grant must NOT admit $write"
            );
        }

        // And the list path agrees: a read grant still shows the row on a read.
        $this->assertContains('readonly', $this->visibleKeys($register, $schema));
    }//end testAReadOnlyGrantDoesNotAdmitAWrite()


    /**
     * A grant cannot admit a CUSTOM verb, which has no core permission bit.
     *
     * Core's bitmask has five verbs. An OpenRegister action outside that set —
     * ZGW's `besluit_nemen`, say — has no bit, so a grant cannot carry it and the
     * caller fails closed. RBAC grants visibility; an extension verb is enforced
     * at the endpoint that performs it (design Q5).
     */
    public function testAGrantCannotAdmitACustomVerb(): void
    {
        [$register, $schema] = $this->createFixtureTable();

        $this->insertFixture($register, $schema, 'customverb', ['scope' => 'private'], false, ownedByRealOwner: true);
        $entity = $this->readBackByKey($register, $schema)['customverb'];
        $this->grantTo($entity, $this->testUid);

        $this->assertNull(
            $this->grantResolver()->permissionFor('besluit_nemen'),
            'a custom verb must map to no core permission bit'
        );
        $this->assertFalse(
            $this->grantResolver()->isGranted($this->testUid, $entity->getUuid(), 'besluit_nemen'),
            'a grant must not admit a verb it cannot carry'
        );
    }//end testAGrantCannotAdmitACustomVerb()


    /**
     * A GRANT MUST NOT CROSS THE TENANT EDGE (task 4.3).
     *
     * The grant branch is OR-ed into the RBAC filter, so on its own it would
     * admit a row from any organisation. The tenant edge is held by forcing the
     * EXISTING `applyOrganizationFilter()` on whenever the caller holds a grant
     * (design D3c) — deliberately reusing core's one filter rather than putting
     * an `_organisation` term in the grant branch, because a second definition of
     * the tenant edge is what drifts.
     *
     * NOTE the query runs with multitenancy ON. Every other test in this file
     * passes `_multitenancy => false` to isolate the RBAC predicate, which means
     * none of them exercise this at all — the reason this test has to set its own
     * fixture up rather than reuse theirs.
     */
    public function testAGrantDoesNotCrossTheTenantEdge(): void
    {
        [$register, $schema] = $this->createFixtureTable(readRule: $this->tenantGroup);

        $activeOrg = $this->activeOrganisationUuid();
        if ($activeOrg === null) {
            $this->markTestSkipped('the session user has no active organisation, so the org filter denies everything');
        }

        // Two rows, both private and both granted to the caller. They differ ONLY
        // in organisation, so the organisation is the sole discriminator.
        $this->insertFixture($register, $schema, 'sameorg', ['scope' => 'private'], false, ownedByRealOwner: true);
        $this->insertFixture($register, $schema, 'otherorg', ['scope' => 'private'], false, ownedByRealOwner: true);

        $this->setOrganisation($register, $schema, 'sameorg', $activeOrg);
        $this->setOrganisation($register, $schema, 'otherorg', '00000000-0000-4000-8000-00000000dead');

        $stored = $this->readBackByKey($register, $schema);
        $this->grantTo($stored['sameorg'], $this->testUid);
        $this->grantTo($stored['otherorg'], $this->testUid);

        // Deliberately NOT `_multitenancy_explicit`. That flag turns the
        // organisation filter on by itself, which made the first version of this
        // test pass with the grant-forcing DISABLED — it proved the org filter
        // works and said nothing about the thing under test. Left off, the filter
        // can only be enabled by the grant-forcing (design D3c).
        $visible = $this->keysOf(
            $this->mapper->searchObjectsInRegisterSchemaTable(
                ['_multitenancy' => true],
                $register,
                $schema
            )
        );

        // The positive control: without this the assertion below could pass
        // simply because the org filter denied EVERYTHING.
        $this->assertContains(
            'sameorg',
            $visible,
            'a grant inside the caller\'s own organisation must still admit — otherwise this test proves nothing'
        );

        $this->assertNotContains(
            'otherorg',
            $visible,
            'a grant must NOT carry a row across the organisation boundary'
        );
    }//end testAGrantDoesNotCrossTheTenantEdge()


    /**
     * The session user's active organisation UUID, or null when they have none.
     *
     * @return string|null The UUID.
     */
    private function activeOrganisationUuid(): ?string
    {
        try {
            $service = \OC::$server->get(\OCA\OpenRegister\Service\OrganisationService::class);
            return $service->getActiveOrganisation()?->getUuid();
        } catch (\Throwable $e) {
            return null;
        }
    }//end activeOrganisationUuid()


    /**
     * Stamp one fixture's organisation directly.
     *
     * @param Register $register     The register.
     * @param Schema   $schema       The schema.
     * @param string   $key          The fixture key.
     * @param string   $organisation The organisation UUID to stamp.
     *
     * @return void
     */
    private function setOrganisation(Register $register, Schema $schema, string $key, string $organisation): void
    {
        $table = $this->mapper->getTableNameForRegisterSchema($register, $schema);
        $uuid  = $this->readBackByKey($register, $schema)[$key]->getUuid();

        $qb = $this->db->getQueryBuilder();
        $qb->update($table)
            ->set('_organisation', $qb->createNamedParameter($organisation))
            ->where($qb->expr()->eq('_uuid', $qb->createNamedParameter($uuid)));
        $qb->executeStatement();
    }//end setOrganisation()


    /**
     * Run a closure while logged in as the fixture owner, then restore.
     *
     * The write surface is owner-checked, and the object's folder is created in
     * whichever session asks for it first — so both the guard and the folder need
     * the owner's session, not the recipient's.
     *
     * @param callable $work The work to run as the owner.
     *
     * @return mixed Whatever the closure returned.
     */
    private function asOwner(callable $work): mixed
    {
        $this->userSession->setUser($this->ownerUser);
        try {
            return $work();
        } finally {
            $this->userSession->setUser($this->testUser);
        }
    }//end asOwner()


    /**
     * Build the register, schema and magic table for one test.
     *
     * @param string|null $schemaScope       Scope to declare as the schema default, if any.
     * @param bool        $withAuthorization Whether to give the schema an authorization block at all.
     * @param bool        $partner           Whether this is the empty second pair that forces the UNION path.
     * @param string      $readRule          The schema's read rule. Defaults to `authenticated`, which admits
     *                                       every logged-in caller and so leaves the scope as the only
     *                                       discriminator; a group name the caller lacks makes the schema the
     *                                       binding ceiling instead.
     *
     * @return array{0: Register, 1: Schema}
     */
    private function createFixtureTable(
        ?string $schemaScope=null,
        bool $withAuthorization=true,
        bool $partner=false,
        string $readRule='authenticated'
    ): array {
        $label    = ($partner === true ? 'union-partner' : 'private-scope');
        $register = $this->registerMapper->createFromArray(
            [
                'title'       => 'PHPUnit '.$label.' Register '.uniqid(),
                'description' => 'Register for private-scope verdict-parity tests',
            ]
        );
        $this->createdRegisterIds[] = $register->getId();

        $definition = [
            'title'       => 'PHPUnit '.$label.' Schema '.uniqid(),
            'description' => 'Schema whose only read discriminator is the object scope',
            'properties'  => [
                'key' => [
                    'type'      => 'string',
                    'title'     => 'Key',
                    'maxLength' => 255,
                ],
            ],
        ];

        if ($withAuthorization === true) {
            // `authenticated` admits every logged-in caller, so the scope is the
            // only thing left that can decide a verdict.
            $definition['authorization'] = ['read' => [$readRule]];
            if ($schemaScope !== null) {
                $definition['authorization']['scope'] = $schemaScope;
            }
        }

        $schema = $this->schemaMapper->createFromArray($definition);
        $this->createdSchemaIds[] = $schema->getId();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->createdTables[] = 'oc_'.$this->mapper->getTableNameForRegisterSchema($register, $schema);

        return [$register, $schema];
    }//end createFixtureTable()


    /**
     * Insert one fixture and make sure its `_authorization` really landed.
     *
     * The insert path is not the write API this capability will eventually use,
     * so the column is written directly and then READ BACK. A fixture whose
     * authorization silently stayed NULL would make every private case look
     * correctly denied for entirely the wrong reason.
     *
     * @param Register           $register       The register.
     * @param Schema             $schema         The schema.
     * @param string             $key            Identifier used to recognise the row.
     * @param array<mixed>|null  $authorization      The per-object authorization block.
     * @param bool               $ownedByCaller      Whether the session user owns it.
     * @param bool               $ownedByRealOwner   Own it with the REAL second user, which the grant
     *                                               tests need so core will let that user share it.
     *
     * @return void
     */
    private function insertFixture(
        Register $register,
        Schema $schema,
        string $key,
        ?array $authorization,
        bool $ownedByCaller,
        bool $ownedByRealOwner=false
    ): void {
        $owner = self::OTHER_OWNER;
        if ($ownedByCaller === true) {
            $owner = $this->testUid;
        } else if ($ownedByRealOwner === true) {
            $owner = $this->ownerUid;
        }

        $entity = new ObjectEntity();
        $entity->setUuid(Uuid::v4()->toRfc4122());
        $entity->setRegister((string) $register->getId());
        $entity->setSchema((string) $schema->getId());
        $entity->setObject(['key' => $key]);
        $entity->setOwner($owner);
        if ($authorization !== null) {
            $entity->setAuthorization($authorization);
        }

        $stored = $this->mapper->insertObjectEntity($entity, $register, $schema, false);

        if ($authorization === null) {
            return;
        }

        $table = $this->mapper->getTableNameForRegisterSchema($register, $schema);
        $qb    = $this->db->getQueryBuilder();
        $qb->update($table)
            ->set('_authorization', $qb->createNamedParameter(json_encode($authorization)))
            ->where($qb->expr()->eq('_uuid', $qb->createNamedParameter($stored->getUuid())));
        $qb->executeStatement();
    }//end insertFixture()


    /**
     * Read every row back, RBAC disabled, keyed by its `key` property.
     *
     * @param Register $register The register.
     * @param Schema   $schema   The schema.
     *
     * @return array<string, ObjectEntity>
     */
    private function readBackByKey(Register $register, Schema $schema): array
    {
        $rows = $this->mapper->searchObjectsInRegisterSchemaTable(
            ['_multitenancy' => false, '_rbac' => false],
            $register,
            $schema
        );

        $byKey = [];
        foreach ($rows as $row) {
            if ($row instanceof ObjectEntity === false) {
                continue;
            }

            $data = ($row->getObject() ?? []);
            if (isset($data['key']) === true) {
                $byKey[$data['key']] = $row;
            }
        }

        return $byKey;
    }//end readBackByKey()


    /**
     * Keys visible through the QueryBuilder list path.
     *
     * @param Register $register The register.
     * @param Schema   $schema   The schema.
     *
     * @return string[]
     */
    private function visibleKeys(Register $register, Schema $schema): array
    {
        return $this->keysOf(
            $this->mapper->searchObjectsInRegisterSchemaTable(
                ['_multitenancy' => false],
                $register,
                $schema
            )
        );
    }//end visibleKeys()


    /**
     * Keys visible through the raw-SQL UNION search path.
     *
     * @param Register $register The register.
     * @param Schema   $schema   The schema.
     *
     * @return string[]
     */
    /**
     * Keys visible through the UNION path with multitenancy REQUESTED.
     *
     * The default helper passes `_multitenancy => false` because the scope tests
     * want the scope predicate to be the only filter. This one asks for tenancy,
     * so the answer tells us whether the union path honours it at all.
     *
     * @param Register $register The register.
     * @param Schema   $schema   The schema.
     *
     * @return string[]
     */
    private function visibleKeysViaUnionWithTenancy(Register $register, Schema $schema): array
    {
        if ($this->unionPartner === null) {
            $this->unionPartner = $this->createFixtureTable(partner: true);
        }

        $rows = $this->mapper->searchAcrossMultipleTables(
            ['_multitenancy' => true],
            [
                ['register' => $register, 'schema' => $schema],
                ['register' => $this->unionPartner[0], 'schema' => $this->unionPartner[1]],
            ]
        );

        return $this->keysOf($rows);
    }//end visibleKeysViaUnionWithTenancy()


    /**
     * CHARACTERISATION: does the UNION path enforce the tenant edge?
     *
     * `testAGrantDoesNotCrossTheTenantEdge` proves the SINGLE-table path does.
     * That test calls `searchObjectsInRegisterSchemaTable()`, so it says nothing
     * about `searchAcrossMultipleTables()` — and `ObjectsController` carries a
     * standing `TODO(SEC-CTRL-1)` asserting that the cross-table builders apply
     * NO RBAC or multitenancy filter and "must be wired there before cross-table
     * search is exposed to non-admins".
     *
     * This matters concretely: a cross-register "shared with me" read (task 6.3)
     * is exactly such an exposure. Before building one, the posture has to be a
     * measured fact rather than an inference from a comment that may be stale —
     * the scope-and-grant half of that TODO IS stale, because the union emitter
     * does now carry the predicate.
     *
     * The test asserts what is TRUE today so the answer is recorded and any
     * change to it is deliberate. If the union path does filter, the assertion
     * documents the guarantee; if it does not, it documents the gap and fails the
     * moment somebody fixes it — at which point the expectation flips and 6.3
     * becomes safe to build on.
     *
     * @return void
     *
     * @spec openspec/changes/object-level-sharing-and-private-scope/specs/private-object-scope/spec.md#requirement-the-private-principal-is-honoured-identically-on-every-enforcement-path
     */
    public function testUnionPathTenantEdgeIsCharacterised(): void
    {
        [$register, $schema] = $this->createFixtureTable(readRule: $this->tenantGroup);

        $activeOrg = $this->activeOrganisationUuid();
        if ($activeOrg === null) {
            $this->markTestSkipped('the session user has no active organisation, so the org filter denies everything');
        }

        // Same construction as the single-table tenant-edge test: two private
        // rows, both granted to the caller, differing ONLY in organisation.
        $this->insertFixture($register, $schema, 'union-same', ['scope' => 'private'], false, ownedByRealOwner: true);
        $this->insertFixture($register, $schema, 'union-other', ['scope' => 'private'], false, ownedByRealOwner: true);

        $this->setOrganisation($register, $schema, 'union-same', $activeOrg);
        $this->setOrganisation($register, $schema, 'union-other', '00000000-0000-4000-8000-00000000dead');

        $stored = $this->readBackByKey($register, $schema);
        $this->grantTo($stored['union-same'], $this->testUid);
        $this->grantTo($stored['union-other'], $this->testUid);
        $this->grantResolver()->forget();

        $visible = $this->visibleKeysViaUnionWithTenancy($register, $schema);

        // CONTROL: the in-tenant row must be there, or the assertion below would
        // pass simply because the query returned nothing at all.
        $this->assertContains(
            'union-same',
            $visible,
            'control: the in-tenant granted row must be visible, or this test proves nothing'
        );

        // MEASURED, 2026-08-03: the union path returns the other organisation's
        // row. The scope-and-grant predicate IS applied there (the tests above
        // prove that), but the ORGANISATION filter is not — so
        // `TODO(SEC-CTRL-1)` in ObjectsController is accurate for multitenancy
        // and stale for RBAC.
        //
        // This asserts the CURRENT behaviour on purpose. It is a characterisation,
        // not an endorsement: the moment somebody wires tenancy into
        // `searchAcrossMultipleTables()` this test FAILS, which is the signal to
        // flip the expectation to `assertNotContains` and to revisit the
        // cross-register reads that were blocked on it — a `shared-with-me` list
        // (task 6.3) above all, which must not be built over this path until then.
        $this->assertContains(
            'union-other',
            $visible,
            'If this now FAILS, tenancy has been wired into the union path — flip this assertion to '
            .'assertNotContains and unblock the cross-register reads that were waiting on it (task 6.3).'
        );
    }//end testUnionPathTenantEdgeIsCharacterised()


    private function visibleKeysViaUnion(Register $register, Schema $schema): array
    {
        if ($this->unionPartner === null) {
            $this->unionPartner = $this->createFixtureTable(partner: true);
        }

        // TWO pairs, and a query with no aggregations or facets: those are
        // exactly the conditions under which shouldUseUnionQuery() returns true
        // and the raw-SQL emitter is the one that runs.
        $rows = $this->mapper->searchAcrossMultipleTables(
            ['_multitenancy' => false],
            [
                ['register' => $register, 'schema' => $schema],
                ['register' => $this->unionPartner[0], 'schema' => $this->unionPartner[1]],
            ]
        );

        return $this->keysOf($rows);
    }//end visibleKeysViaUnion()


    /**
     * The raw-SQL emitter really emits the scope predicate.
     *
     * The UNION assertions above prove the ROWS come out right; this proves they
     * come out right because of the predicate, and not because the UNION query
     * fell back to some other filter.
     *
     * @param Schema $schema The schema to emit conditions for.
     *
     * @return void
     */
    private function assertRawEmitterCarriesTheScopePredicate(Schema $schema): void
    {
        $result = $this->rbacHandler->buildRbacConditionsSql(schema: $schema, action: 'read');
        $joined = implode(' ', $result['conditions']);

        $this->assertStringContainsString(
            '_authorization',
            $joined,
            'the raw-SQL emitter did not reference the scope column at all'
        );
        $this->assertStringContainsString("'organisation'", $joined);
    }//end assertRawEmitterCarriesTheScopePredicate()


    /**
     * Extract the `key` property from a result set.
     *
     * @param iterable<mixed> $rows Result rows.
     *
     * @return string[]
     */
    private function keysOf(iterable $rows): array
    {
        $keys = [];
        foreach ($rows as $row) {
            $data = $row instanceof ObjectEntity ? ($row->getObject() ?? []) : (array) $row;
            if (isset($data['key']) === true) {
                $keys[] = $data['key'];
            }
        }

        return $keys;
    }//end keysOf()


    /**
     * A stable, column-safe identifier for a fixture label.
     *
     * @param string $label The human-readable matrix label.
     *
     * @return string
     */
    private function keyFor(string $label): string
    {
        return str_replace(' ', '-', $label);
    }//end keyFor()


}//end class
