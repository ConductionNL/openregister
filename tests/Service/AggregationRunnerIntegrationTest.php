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

    /** @var int[] */
    private array $createdSchemaIds = [];
    /** @var int[] */
    private array $createdRegisterIds = [];
    /** @var string[] */
    private array $createdTables = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner         = \OC::$server->get(AggregationRunner::class);
        $this->mapper         = \OC::$server->get(MagicMapper::class);
        $this->registerMapper = \OC::$server->get(RegisterMapper::class);
        $this->schemaMapper   = \OC::$server->get(SchemaMapper::class);
    }

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
    }

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
    }

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
    }

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
    }

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
    }

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
    }

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
    }

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
    }

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
    }

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
        $this->assertSame(2, $byKey['open']        ?? null);
        $this->assertSame(1, $byKey['in-progress'] ?? null);
        $this->assertSame(2, $byKey['completed']   ?? null);
    }

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
    }

    public function testCacheHitOnSecondCall(): void
    {
        // First call goes to the DB, second hits the 60s distributed cache
        // and returns `cached: true`. Verifies the cache is wired into
        // AggregationRunner::run() — not just the cache class in isolation.
        $fixture = $this->seedTaskFixture();

        $first  = $this->runner->run(
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
    }

    /**
     * Build one Register + Schema with operator-flavoured aggregations and
     * five objects covering the open / in-progress / completed status
     * mix and the low / medium / high priority spread.
     *
     * @return array{register: Register, schema: Schema}
     */
    private function seedTaskFixture(): array
    {
        $register = $this->registerMapper->createFromArray([
            'title'       => 'Aggregation Integration Register ' . uniqid(),
            'description' => 'Aggregation runner integration tests',
        ]);
        $this->createdRegisterIds[] = $register->getId();

        $schema = $this->schemaMapper->createFromArray([
            'title'       => 'Task ' . uniqid(),
            'description' => 'Task schema for aggregation integration tests',
            'properties'  => [
                'taskStatus' => [
                    'type'  => 'string',
                    'title' => 'Status',
                    'enum'  => ['open', 'in-progress', 'completed'],
                ],
                'priority' => [
                    'type'  => 'integer',
                    'title' => 'Priority',
                ],
            ],
            'configuration' => [
                'x-openregister-aggregations' => [
                    'totalCount'           => ['metric' => 'count'],
                    'completedCount'       => ['metric' => 'count', 'filter' => ['taskStatus' => 'completed']],
                    'nonCompletedCount'    => ['metric' => 'count', 'filter' => ['taskStatus' => ['ne' => 'completed']]],
                    'inProgressOrOpen'     => ['metric' => 'count', 'filter' => ['taskStatus' => ['in' => ['open', 'in-progress']]]],
                    'highPriority'         => ['metric' => 'count', 'filter' => ['priority' => ['gt' => 5]]],
                    'priorityAtLeastFive'  => ['metric' => 'count', 'filter' => ['priority' => ['gte' => 5]]],
                    'lowPriority'          => ['metric' => 'count', 'filter' => ['priority' => ['lt' => 5]]],
                    'priorityAtMostFive'   => ['metric' => 'count', 'filter' => ['priority' => ['lte' => 5]]],
                    'totalPriority'        => ['metric' => 'sum', 'field' => 'priority'],
                    'byStatus'             => ['metric' => 'count', 'groupBy' => ['field' => 'taskStatus']],
                ],
            ],
        ]);
        $this->createdSchemaIds[] = $schema->getId();

        $this->mapper->ensureTableForRegisterSchema($register, $schema);
        $this->createdTables[] = 'oc_' . $this->mapper->getTableNameForRegisterSchema($register, $schema);

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

        return ['register' => $register, 'schema' => $schema];
    }
}
