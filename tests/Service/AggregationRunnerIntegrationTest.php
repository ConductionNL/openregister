<?php

/**
 * Integration tests for AggregationRunner native SQL operator paths.
 *
 * Hits a real Postgres database through the magic table layer to verify
 * the SQL builder correctly translates each operator (in/gt/gte/lt/lte/
 * ne), GROUP BY, and the equality fast path. Unit-level mocking can't
 * cover this — the value is in catching SQL-layer bugs (column quoting,
 * NULL handling, type coercion) that only show up against a real engine.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Aggregation\AggregationQuery;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @group DB
 */
class AggregationRunnerIntegrationTest extends TestCase
{

    private AggregationRunner $runner;

    private MagicMapper $mapper;

    private RegisterMapper $registerMapper;

    private SchemaMapper $schemaMapper;

    private ?string $activeOrgUuid = null;

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
        $this->runner         = \OC::$server->get(AggregationRunner::class);
        $this->mapper         = \OC::$server->get(MagicMapper::class);
        $this->registerMapper = \OC::$server->get(RegisterMapper::class);
        $this->schemaMapper   = \OC::$server->get(SchemaMapper::class);

        // Authenticate as admin and resolve an active organisation so the
        // runner's RBAC list-gate passes and the native path's
        // `_organisation = ?` tenant predicate matches the seeded rows.
        // The low-level insertObjectEntity() seed path does not stamp the
        // tenant column (the production SaveObject path does), so the
        // fixture stamps it explicitly to mirror real inserts — without
        // this, every native-path assertion fails on tenant isolation.
        $userSession = \OC::$server->get(\OCP\IUserSession::class);
        $userManager = \OC::$server->get(\OCP\IUserManager::class);
        $admin       = $userManager->get('admin');
        if ($admin !== null) {
            $userSession->setUser($admin);
        }

        $orgService = \OC::$server->get(\OCA\OpenRegister\Service\OrganisationService::class);
        $activeOrg  = $orgService->getActiveOrganisation();
        if ($activeOrg === null) {
            $defaultUuid = $orgService->getDefaultOrganisationUuid();
            if ($defaultUuid !== null) {
                $orgService->setActiveOrganisation($defaultUuid);
                $activeOrg = $orgService->getActiveOrganisation();
            }
        }

        $this->activeOrgUuid = $activeOrg?->getUuid();
    }//end setUp()

    protected function tearDown(): void
    {
        $db = \OC::$server->get(\OCP\IDBConnection::class);

        foreach ($this->createdTables as $tableName) {
            try {
                $db->prepare("DROP TABLE IF EXISTS $tableName")->execute();
            } catch (\Exception $e) {
                // best effort
            }
        }

        foreach ($this->createdSchemaIds as $id) {
            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_schemas')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            } catch (\Exception $e) {
                // already cleaned
            }
        }

        foreach ($this->createdRegisterIds as $id) {
            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_registers')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            } catch (\Exception $e) {
                // already cleaned
            }
        }

        parent::tearDown();
    }//end tearDown()

    public function testCountAllObjectsRoutesThroughPostgresNative(): void
    {
        $fixture = $this->seedTaskFixture();

        $result = $this->runner->run(
            $fixture['register']->getSlug(),
            $fixture['schema']->getSlug(),
            'totalCount'
        );

        $this->assertSame(5, $result['value']);
        $this->assertSame('postgres', $result['backend'] ?? null);
    }//end testCountAllObjectsRoutesThroughPostgresNative()

    public function testEqualityFilter(): void
    {
        $fixture = $this->seedTaskFixture();

        $result = $this->runner->run(
            $fixture['register']->getSlug(),
            $fixture['schema']->getSlug(),
            'completedCount'
        );

        $this->assertSame(2, $result['value']);
        $this->assertSame('postgres', $result['backend'] ?? null);
    }//end testEqualityFilter()

    public function testInOperatorFilter(): void
    {
        // `taskStatus in [open, in-progress]` should match exactly the
        // 2 open + 1 in-progress objects in the fixture (3 total).
        $fixture = $this->seedTaskFixture();

        $result = $this->runner->run(
            $fixture['register']->getSlug(),
            $fixture['schema']->getSlug(),
            'inProgressOrOpen'
        );

        $this->assertSame(3, $result['value']);
        $this->assertSame('postgres', $result['backend'] ?? null);
    }//end testInOperatorFilter()

    public function testGtOperatorFilter(): void
    {
        // `priority > 5` should match the 2 high-priority objects (priority=10).
        $fixture = $this->seedTaskFixture();

        $result = $this->runner->run(
            $fixture['register']->getSlug(),
            $fixture['schema']->getSlug(),
            'highPriority'
        );

        $this->assertSame(2, $result['value']);
        $this->assertSame('postgres', $result['backend'] ?? null);
    }//end testGtOperatorFilter()

    public function testGteOperatorFilter(): void
    {
        // `priority >= 5` includes the medium (priority=5) one too — total 3.
        $fixture = $this->seedTaskFixture();

        $result = $this->runner->run(
            $fixture['register']->getSlug(),
            $fixture['schema']->getSlug(),
            'priorityAtLeastFive'
        );

        $this->assertSame(3, $result['value']);
        $this->assertSame('postgres', $result['backend'] ?? null);
    }//end testGteOperatorFilter()

    public function testLtOperatorFilter(): void
    {
        // `priority < 5` matches the 2 low-priority objects (priority=1).
        $fixture = $this->seedTaskFixture();

        $result = $this->runner->run(
            $fixture['register']->getSlug(),
            $fixture['schema']->getSlug(),
            'lowPriority'
        );

        $this->assertSame(2, $result['value']);
        $this->assertSame('postgres', $result['backend'] ?? null);
    }//end testLtOperatorFilter()

    public function testLteOperatorFilter(): void
    {
        $fixture = $this->seedTaskFixture();

        $result = $this->runner->run(
            $fixture['register']->getSlug(),
            $fixture['schema']->getSlug(),
            'priorityAtMostFive'
        );

        // 2 low (priority=1) + 1 medium (priority=5) = 3.
        $this->assertSame(3, $result['value']);
        $this->assertSame('postgres', $result['backend'] ?? null);
    }//end testLteOperatorFilter()

    public function testNeOperatorFilter(): void
    {
        // `taskStatus != completed` excludes the 2 completed objects, leaves 3.
        $fixture = $this->seedTaskFixture();

        $result = $this->runner->run(
            $fixture['register']->getSlug(),
            $fixture['schema']->getSlug(),
            'nonCompletedCount'
        );

        $this->assertSame(3, $result['value']);
        $this->assertSame('postgres', $result['backend'] ?? null);
    }//end testNeOperatorFilter()

    public function testGroupByReturnsBuckets(): void
    {
        $fixture = $this->seedTaskFixture();

        $result = $this->runner->run(
            $fixture['register']->getSlug(),
            $fixture['schema']->getSlug(),
            'byStatus'
        );

        $this->assertArrayHasKey('groups', $result);
        $this->assertSame('postgres', $result['backend'] ?? null);

        $byKey = [];
        foreach ($result['groups'] as $bucket) {
            $byKey[(string) $bucket['key']] = (int) $bucket['value'];
        }

        $this->assertSame(2, $byKey['open'] ?? null);
        $this->assertSame(1, $byKey['in-progress'] ?? null);
        $this->assertSame(2, $byKey['completed'] ?? null);
    }//end testGroupByReturnsBuckets()

    public function testSumOnNumericField(): void
    {
        // priority = [1, 1, 5, 10, 10] → sum = 27.
        $fixture = $this->seedTaskFixture();

        $result = $this->runner->run(
            $fixture['register']->getSlug(),
            $fixture['schema']->getSlug(),
            'totalPriority'
        );

        $this->assertSame(27.0, (float) $result['value']);
        $this->assertSame('postgres', $result['backend'] ?? null);
    }//end testSumOnNumericField()

    public function testNotInOperatorFilter(): void
    {
        // `taskStatus notIn [completed, open]` excludes the 2 completed + 2
        // open objects, leaving only the 1 in-progress object.
        $fixture = $this->seedTaskFixture();

        $result = $this->runner->run(
            $fixture['register']->getSlug(),
            $fixture['schema']->getSlug(),
            'notCompletedNorOpen'
        );

        $this->assertSame(1, $result['value']);
        $this->assertSame('postgres', $result['backend'] ?? null);
    }//end testNotInOperatorFilter()

    /**
     * Prove the ad-hoc entry point a consuming app (e.g. pipelinq) uses.
     *
     * This is the contract: DI-resolve AggregationRunner, build an
     * AggregationQuery (metric + field + filter + groupBy), and call
     * runAdhocByRef(registerRef, schemaRef, query) — RBAC + tenant scoped
     * — to get a grouped SUM without fetching-all-and-summing-in-PHP.
     *
     * priority by status: open=[1,10] sum=11; in-progress=[5] sum=5;
     * completed=[1,10] sum=11.
     */
    public function testAdhocGroupedSumByRefIsConsumerReachable(): void
    {
        $fixture = $this->seedTaskFixture();

        $query = AggregationQuery::create(
            metric: 'sum',
            field: 'priority',
            filter: [],
            groupBy: ['field' => 'taskStatus']
        );

        $result = $this->runner->runAdhocByRef(
            $fixture['register']->getSlug(),
            $fixture['schema']->getSlug(),
            $query
        );

        $this->assertArrayHasKey('groups', $result);
        $this->assertSame('postgres', $result['backend'] ?? null);

        $byKey = [];
        foreach ($result['groups'] as $bucket) {
            $byKey[(string) $bucket['key']] = (float) $bucket['value'];
        }

        $this->assertSame(11.0, $byKey['open'] ?? null);
        $this->assertSame(5.0, $byKey['in-progress'] ?? null);
        $this->assertSame(11.0, $byKey['completed'] ?? null);
    }//end testAdhocGroupedSumByRefIsConsumerReachable()

    /**
     * Prove ad-hoc AVG + a notIn filter through the same consumer entry
     * point. priority over rows where taskStatus notIn [completed]:
     * open=[1,10], in-progress=[5] → avg of (1,10,5) = 16/3.
     */
    public function testAdhocAvgWithNotInFilterByRef(): void
    {
        $fixture = $this->seedTaskFixture();

        $query = AggregationQuery::create(
            metric: 'avg',
            field: 'priority',
            filter: ['taskStatus' => ['notIn' => ['completed']]]
        );

        $result = $this->runner->runAdhocByRef(
            $fixture['register']->getSlug(),
            $fixture['schema']->getSlug(),
            $query
        );

        $this->assertSame('postgres', $result['backend'] ?? null);
        $this->assertEqualsWithDelta(16.0 / 3.0, (float) $result['value'], 0.0001);
    }//end testAdhocAvgWithNotInFilterByRef()

    /**
     * Prove ad-hoc MIN and MAX through the same consumer entry point.
     * priority = [1, 10, 5, 1, 10] → min=1, max=10.
     */
    public function testAdhocMinMaxByRef(): void
    {
        $fixture = $this->seedTaskFixture();

        $min = $this->runner->runAdhocByRef(
            $fixture['register']->getSlug(),
            $fixture['schema']->getSlug(),
            AggregationQuery::create(metric: 'min', field: 'priority')
        );
        $max = $this->runner->runAdhocByRef(
            $fixture['register']->getSlug(),
            $fixture['schema']->getSlug(),
            AggregationQuery::create(metric: 'max', field: 'priority')
        );

        $this->assertSame(1.0, (float) $min['value']);
        $this->assertSame(10.0, (float) $max['value']);
    }//end testAdhocMinMaxByRef()

    public function testCacheHitOnSecondCall(): void
    {
        // First call goes to the DB, second hits the 60s distributed cache
        // and returns `cached: true`. Verifies the cache is wired into
        // AggregationRunner::run() — not just the cache class in isolation.
        $fixture = $this->seedTaskFixture();

        $first = $this->runner->run(
            $fixture['register']->getSlug(),
            $fixture['schema']->getSlug(),
            'totalCount'
        );
        $this->assertArrayNotHasKey('cached', $first);

        $second = $this->runner->run(
            $fixture['register']->getSlug(),
            $fixture['schema']->getSlug(),
            'totalCount'
        );
        $this->assertTrue($second['cached'] ?? false);
        $this->assertSame($first['value'], $second['value']);
    }//end testCacheHitOnSecondCall()

    /**
     * Build one Register + Schema with operator-flavoured aggregations and
     * five objects covering the open / in-progress / completed status
     * mix and the low / medium / high priority spread.
     *
     * @return array{register: Register, schema: Schema}
     */
    private function seedTaskFixture(): array
    {
        $register = $this->registerMapper->createFromArray(
                [
                    'title'       => 'Aggregation Integration Register '.uniqid(),
                    'description' => 'Aggregation runner integration tests',
                ]
                );
        $this->createdRegisterIds[] = $register->getId();

        $schema = $this->schemaMapper->createFromArray(
                [
                    'title'         => 'Task '.uniqid(),
                    'description'   => 'Task schema for aggregation integration tests',
                    'properties'    => [
                        'taskStatus' => [
                            'type'  => 'string',
                            'title' => 'Status',
                            'enum'  => ['open', 'in-progress', 'completed'],
                        ],
                        'priority'   => [
                            'type'  => 'integer',
                            'title' => 'Priority',
                        ],
                    ],
                    'configuration' => [
                        'x-openregister-aggregations' => [
                            'totalCount'          => ['metric' => 'count'],
                            'completedCount'      => ['metric' => 'count', 'filter' => ['taskStatus' => 'completed']],
                            'nonCompletedCount'   => ['metric' => 'count', 'filter' => ['taskStatus' => ['ne' => 'completed']]],
                            'inProgressOrOpen'    => ['metric' => 'count', 'filter' => ['taskStatus' => ['in' => ['open', 'in-progress']]]],
                            'notCompletedNorOpen' => ['metric' => 'count', 'filter' => ['taskStatus' => ['notIn' => ['completed', 'open']]]],
                            'highPriority'        => ['metric' => 'count', 'filter' => ['priority' => ['gt' => 5]]],
                            'priorityAtLeastFive' => ['metric' => 'count', 'filter' => ['priority' => ['gte' => 5]]],
                            'lowPriority'         => ['metric' => 'count', 'filter' => ['priority' => ['lt' => 5]]],
                            'priorityAtMostFive'  => ['metric' => 'count', 'filter' => ['priority' => ['lte' => 5]]],
                            'totalPriority'       => ['metric' => 'sum', 'field' => 'priority'],
                            'byStatus'            => ['metric' => 'count', 'groupBy' => ['field' => 'taskStatus']],
                        ],
                    ],
                ]
                );
        $this->createdSchemaIds[] = $schema->getId();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->createdTables[] = 'oc_'.$this->mapper->getTableNameForRegisterSchema($register, $schema);

        // 5 fixture objects: 2 open, 1 in-progress, 2 completed.
        // priority: 1, 10, 5, 1, 10 (sum=27, avg=5.4, low<5: 2, gte5: 3).
        foreach ([
            ['status' => 'open',        'priority' => 1],
            ['status' => 'open',        'priority' => 10],
            ['status' => 'in-progress', 'priority' => 5],
            ['status' => 'completed',   'priority' => 1],
            ['status' => 'completed',   'priority' => 10],
        ] as $row) {
            $entity = new ObjectEntity();
            $entity->setUuid(Uuid::v4()->toRfc4122());
            $entity->setRegister((string) $register->getId());
            $entity->setSchema((string) $schema->getId());
            $entity->setObject(['taskStatus' => $row['status'], 'priority' => $row['priority']]);
            $this->mapper->insertObjectEntity($entity, $register, $schema, false);
        }

        // Stamp the active-organisation tenant column on the seeded rows so
        // the native aggregation path's `_organisation = ?` predicate
        // matches them. The production SaveObject path stamps this column;
        // the low-level insertObjectEntity() seed path used here does not.
        if ($this->activeOrgUuid !== null) {
            $tableName = 'oc_'.$this->mapper->getTableNameForRegisterSchema($register, $schema);
            $db        = \OC::$server->get(\OCP\IDBConnection::class);
            $db->prepare("UPDATE {$tableName} SET _organisation = ?")->execute([$this->activeOrgUuid]);
        }

        return ['register' => $register, 'schema' => $schema];
    }//end seedTaskFixture()
}//end class
