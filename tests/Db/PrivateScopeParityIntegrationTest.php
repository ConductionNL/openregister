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
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\DB\QueryBuilder\IQueryBuilder;
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
     * Build the register, schema and magic table for one test.
     *
     * @param string|null $schemaScope       Scope to declare as the schema default, if any.
     * @param bool        $withAuthorization Whether to give the schema an authorization block at all.
     * @param bool        $partner           Whether this is the empty second pair that forces the UNION path.
     *
     * @return array{0: Register, 1: Schema}
     */
    private function createFixtureTable(?string $schemaScope=null, bool $withAuthorization=true, bool $partner=false): array
    {
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
            $definition['authorization'] = ['read' => ['authenticated']];
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
     * @param array<mixed>|null  $authorization  The per-object authorization block.
     * @param bool               $ownedByCaller  Whether the session user owns it.
     *
     * @return void
     */
    private function insertFixture(
        Register $register,
        Schema $schema,
        string $key,
        ?array $authorization,
        bool $ownedByCaller
    ): void {
        $entity = new ObjectEntity();
        $entity->setUuid(Uuid::v4()->toRfc4122());
        $entity->setRegister((string) $register->getId());
        $entity->setSchema((string) $schema->getId());
        $entity->setObject(['key' => $key]);
        $entity->setOwner($ownedByCaller === true ? $this->testUid : self::OTHER_OWNER);
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
