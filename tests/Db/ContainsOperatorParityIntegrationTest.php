<?php

/**
 * Live-database verdict parity for the `$contains` RBAC operator.
 *
 * `$contains` is implemented twice by necessity: once in PHP
 * ({@see \OCA\OpenRegister\Service\OperatorEvaluator}) for the single-object
 * verdict, and once as SQL for the list endpoint. Unit tests pin each side in
 * isolation; only a real database shows that the two AGREE. A disagreement is a
 * silent access-control bug — over-filtering hides an object the user is
 * entitled to, under-filtering exposes one they are not.
 *
 * This is the matrix design D6 calls for. Every fixture runs through BOTH paths,
 * and the verdicts are compared to EACH OTHER (so the test fails whichever side
 * regresses) *and* to the expected verdict (so a case where both paths are wrong
 * in the same direction cannot pass silently).
 *
 * Three things this harness has to get right, each of which produced a false
 * result first:
 *
 *  1. It runs WITH a logged-in session. `applyRbacFilters()` deliberately
 *     bypasses RBAC entirely when there is no user and `PHP_SAPI === 'cli'`
 *     (trusted system context — occ, repair steps, cron). Without a session the
 *     list path returns everything while the object path evaluates the match,
 *     which looks exactly like a fail-open divergence and is not one (design D11).
 *  2. The session user is NOT an admin, because admins bypass RBAC.
 *  3. The fixtures are owned by SOMEONE ELSE. RBAC OR-s an `_owner = <uid>`
 *     condition into the filter, so objects owned by the session user would be
 *     admitted regardless of the share predicate — masking the very thing under
 *     test.
 *
 * Principals are matched from two UNPREFIXED lists (`sharedUsers`,
 * `sharedGroups`) because a match clause resolves whole tokens and cannot
 * concatenate `"user:" + $userId` (design D9).
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
 * @spec openspec/changes/shared-credentials-and-flows/specs/flow-sharing/spec.md#requirement-the-single-object-and-list-access-decisions-agree
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
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @group DB
 */
class ContainsOperatorParityIntegrationTest extends TestCase
{

    /**
     * Owner of every fixture — deliberately NOT the session user, so the
     * owner-always-wins condition cannot mask the share predicate.
     *
     * @var string
     */
    private const FIXTURE_OWNER = 'parity-fixture-owner';

    private MagicMapper $mapper;

    private MagicRbacHandler $rbacHandler;

    private RegisterMapper $registerMapper;

    private SchemaMapper $schemaMapper;

    private IUserSession $userSession;

    private IUserManager $userManager;

    private IGroupManager $groupManager;

    private ?IUser $testUser = null;

    private string $testUid = '';

    private string $testGroup = '';

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

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper         = \OC::$server->get(MagicMapper::class);
        $this->rbacHandler    = \OC::$server->get(MagicRbacHandler::class);
        $this->registerMapper = \OC::$server->get(RegisterMapper::class);
        $this->schemaMapper   = \OC::$server->get(SchemaMapper::class);
        $this->userSession    = \OC::$server->get(IUserSession::class);
        $this->userManager    = \OC::$server->get(IUserManager::class);
        $this->groupManager   = \OC::$server->get(IGroupManager::class);

        $suffix          = substr((string) Uuid::v4(), 0, 8);
        $this->testUid   = 'parity-user-'.$suffix;
        $this->testGroup = 'parity-group-'.$suffix;

        // NC enforces a minimum password length; a short one fails silently.
        $this->testUser = $this->userManager->createUser($this->testUid, 'Parity-Test-Pass-123');
        if ($this->testUser === false || $this->testUser === null) {
            $this->markTestSkipped('could not create a test user');
        }

        $group = $this->groupManager->createGroup($this->testGroup);
        if ($group !== null) {
            $group->addUser($this->testUser);
        }

        // Log the user in: without a session the list path takes its documented
        // CLI bypass and applies no filter at all (design D11).
        $this->userSession->setUser($this->testUser);

        if ($this->rbacHandler->isAdmin() === true) {
            $this->markTestSkipped('the test user resolved as admin, which bypasses RBAC');
        }
    }//end setUp()

    protected function tearDown(): void
    {
        $this->userSession->setUser(null);

        $group = $this->groupManager->get($this->testGroup);
        if ($group !== null) {
            $group->delete();
        }

        if ($this->testUser !== null) {
            $this->testUser->delete();
        }

        $db = \OC::$server->get(\OCP\IDBConnection::class);

        foreach ($this->createdTables as $tableName) {
            try {
                $db->prepare("DROP TABLE IF EXISTS $tableName")->execute();
            } catch (\Exception $e) {
                // Table may not exist.
            }
        }

        foreach ($this->createdSchemaIds as $id) {
            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_schemas')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            } catch (\Exception $e) {
                // Already cleaned up.
            }
        }

        foreach ($this->createdRegisterIds as $id) {
            try {
                $qb = $db->getQueryBuilder();
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
     * The fixture matrix.
     *
     * @return array<string, array{0: array<string, mixed>, 1: bool}>
     */
    private function fixtureMatrix(): array
    {
        return [
            'shared with this user directly' => [
                ['name' => 'direct', 'sharedUsers' => [$this->testUid], 'sharedGroups' => []],
                true,
            ],
            'user among several'             => [
                ['name' => 'several', 'sharedUsers' => ['someone-else', $this->testUid], 'sharedGroups' => []],
                true,
            ],
            'shared with a group of theirs'  => [
                ['name' => 'viagroup', 'sharedUsers' => [], 'sharedGroups' => [$this->testGroup]],
                true,
            ],
            'shared with another user'       => [
                ['name' => 'otheruser', 'sharedUsers' => ['someone-else'], 'sharedGroups' => []],
                false,
            ],
            'shared with another group'      => [
                ['name' => 'othergroup', 'sharedUsers' => [], 'sharedGroups' => ['unrelated-group']],
                false,
            ],
            'both lists empty'              => [
                ['name' => 'empty', 'sharedUsers' => [], 'sharedGroups' => []],
                false,
            ],
            'properties absent'             => [
                ['name' => 'absent'],
                false,
            ],
            // A LIKE/substring implementation would wrongly admit these two;
            // jsonb containment and in_array are exact-member tests.
            'uid is a prefix only'          => [
                ['name' => 'prefix', 'sharedUsers' => [$this->testUid.'-extra'], 'sharedGroups' => []],
                false,
            ],
            'uid differs by case'           => [
                ['name' => 'case', 'sharedUsers' => [strtoupper($this->testUid)], 'sharedGroups' => []],
                false,
            ],
            // Guards against an implementation that stringifies or flattens.
            'uid nested one level deeper'   => [
                ['name' => 'nested', 'sharedUsers' => [['id' => $this->testUid]], 'sharedGroups' => []],
                false,
            ],
        ];
    }//end fixtureMatrix()

    /**
     * Every fixture yields the same verdict from both paths, and it is correct.
     */
    public function testContainsVerdictsAgreeAcrossBothPaths(): void
    {
        $register = $this->createTestRegister();
        $schema   = $this->createShareSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $fixtures = $this->fixtureMatrix();

        // Insert everything first so ONE list query covers the whole matrix — the
        // list path has to be exercised the way it actually runs.
        foreach ($fixtures as $case) {
            $this->insertTestObject($register, $schema, $case[0]);
        }

        $listedNames = $this->listVisibleNames($register, $schema);

        $disagreements = [];
        $wrongVerdicts = [];

        foreach ($fixtures as $label => $case) {
            [$objectData, $expected] = $case;

            $phpVerdict = $this->rbacHandler->hasPermission($schema, 'read', self::FIXTURE_OWNER, $objectData);
            $sqlVerdict = in_array($objectData['name'], $listedNames, true);

            if ($phpVerdict !== $sqlVerdict) {
                $disagreements[] = sprintf(
                    '%s: find=%s list=%s',
                    $label,
                    var_export($phpVerdict, true),
                    var_export($sqlVerdict, true)
                );
            }

            if ($phpVerdict !== $expected) {
                $wrongVerdicts[] = sprintf(
                    '%s: expected %s, find said %s',
                    $label,
                    var_export($expected, true),
                    var_export($phpVerdict, true)
                );
            }
        }//end foreach

        $this->assertSame(
            [],
            $disagreements,
            "The single-object and list paths disagreed:\n  ".implode("\n  ", $disagreements)
        );

        $this->assertSame(
            [],
            $wrongVerdicts,
            "A verdict was wrong on BOTH paths:\n  ".implode("\n  ", $wrongVerdicts)
        );
    }//end testContainsVerdictsAgreeAcrossBothPaths()

    /**
     * The list query must actually filter.
     *
     * Without this, a matrix in which everything is returned would satisfy the
     * parity assertion above while proving nothing at all.
     */
    public function testListQueryActuallyFilters(): void
    {
        $register = $this->createTestRegister();
        $schema   = $this->createShareSchema();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->trackTable($register, $schema);

        $this->insertTestObject(
            $register,
            $schema,
            ['name' => 'granted', 'sharedUsers' => [$this->testUid], 'sharedGroups' => []]
        );
        $this->insertTestObject(
            $register,
            $schema,
            ['name' => 'denied', 'sharedUsers' => ['someone-else'], 'sharedGroups' => []]
        );

        $names = $this->listVisibleNames($register, $schema);

        $this->assertContains('granted', $names, 'the shared object should be listed');
        $this->assertNotContains('denied', $names, 'an unshared object must not be listed');
    }//end testListQueryActuallyFilters()

    /**
     * Run the list path and collect the `name` of every visible object.
     *
     * @param Register $register The register.
     * @param Schema   $schema   The schema.
     *
     * @return string[] Names of the objects the list path returned.
     */
    private function listVisibleNames(Register $register, Schema $schema): array
    {
        $listed = $this->mapper->searchObjectsInRegisterSchemaTable(
            ['_multitenancy' => false],
            $register,
            $schema
        );

        $names = [];
        foreach ($listed as $row) {
            $data = $row instanceof ObjectEntity ? $row->getObject() : (array) $row;
            if (isset($data['name']) === true) {
                $names[] = $data['name'];
            }
        }

        return $names;
    }//end listVisibleNames()

    /**
     * Create a register for the test.
     *
     * @return Register
     */
    private function createTestRegister(): Register
    {
        $register = $this->registerMapper->createFromArray(
            [
                'title'       => 'PHPUnit contains-parity Register '.uniqid(),
                'description' => 'Register for $contains verdict-parity tests',
            ]
        );

        $this->createdRegisterIds[] = $register->getId();

        return $register;
    }//end createTestRegister()

    /**
     * Create a schema whose read rules are the two share checks (design D9).
     *
     * @return Schema
     */
    private function createShareSchema(): Schema
    {
        $schema = $this->schemaMapper->createFromArray(
            [
                'title'         => 'PHPUnit contains-parity Schema '.uniqid(),
                'description'   => 'Schema whose read rules are $contains share checks',
                'properties'    => [
                    'name'         => [
                        'type'      => 'string',
                        'title'     => 'Name',
                        'maxLength' => 255,
                    ],
                    'sharedUsers'  => [
                        'type'  => 'array',
                        'title' => 'Shared users',
                    ],
                    'sharedGroups' => [
                        'type'  => 'array',
                        'title' => 'Shared groups',
                    ],
                ],
                // Two rules, OR'd — which is how multiple rules already combine.
                // `authenticated` qualifies any logged-in user, leaving the match
                // clause as the sole discriminator.
                'authorization' => [
                    'read' => [
                        [
                            'group' => 'authenticated',
                            'match' => ['sharedUsers' => ['$contains' => '$userId']],
                        ],
                        [
                            'group' => 'authenticated',
                            'match' => ['sharedGroups' => ['$contains' => '$user.groups']],
                        ],
                    ],
                ],
            ]
        );

        $this->createdSchemaIds[] = $schema->getId();

        return $schema;
    }//end createShareSchema()

    /**
     * Track the magic table for cleanup.
     *
     * @param Register $register The register.
     * @param Schema   $schema   The schema.
     *
     * @return void
     */
    private function trackTable(Register $register, Schema $schema): void
    {
        $tableName             = $this->mapper->getTableNameForRegisterSchema($register, $schema);
        $this->createdTables[] = 'oc_'.$tableName;
    }//end trackTable()

    /**
     * Insert one fixture, owned by someone other than the session user.
     *
     * @param Register             $register   The register.
     * @param Schema               $schema     The schema.
     * @param array<string, mixed> $objectData The object body.
     *
     * @return ObjectEntity
     */
    private function insertTestObject(Register $register, Schema $schema, array $objectData): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid(Uuid::v4()->toRfc4122());
        $entity->setRegister((string) $register->getId());
        $entity->setSchema((string) $schema->getId());
        $entity->setObject($objectData);
        $entity->setOwner(self::FIXTURE_OWNER);

        return $this->mapper->insertObjectEntity($entity, $register, $schema, false);
    }//end insertTestObject()
}
