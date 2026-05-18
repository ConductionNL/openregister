<?php

declare(strict_types=1);

namespace Unit\Service\Aggregation;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Aggregation\AggregationCache;
use OCA\OpenRegister\Service\Aggregation\AggregationQuery;
use OCA\OpenRegister\Service\Aggregation\AggregationResult;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\IndexService;
use OCA\OpenRegister\Service\Index\SearchBackendInterface;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for AggregationRunner.
 *
 * @covers \OCA\OpenRegister\Service\Aggregation\AggregationRunner
 */
class AggregationRunnerTest extends TestCase
{

    private AggregationRunner $runner;
    private IDBConnection&MockObject $db;
    private MagicMapper&MockObject $magicMapper;
    private IndexService&MockObject $indexService;
    private AggregationCache&MockObject $cache;
    private LoggerInterface&MockObject $logger;
    private Register $register;
    private Schema $schema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db           = $this->createMock(IDBConnection::class);
        $this->magicMapper  = $this->createMock(MagicMapper::class);
        $this->indexService = $this->createMock(IndexService::class);
        $this->cache        = $this->createMock(AggregationCache::class);
        $this->logger       = $this->createMock(LoggerInterface::class);

        $this->runner = new AggregationRunner(
            db: $this->db,
            magicMapper: $this->magicMapper,
            indexService: $this->indexService,
            cache: $this->cache,
            logger: $this->logger
        );

        $this->register = new Register();
        $this->register->setSlug('test-register');
        $this->register->setId(1);

        $this->schema = new Schema();
        $this->schema->setSlug('test-schema');
        $this->schema->setId(1);
    }

    public function testRunReturnsCachedResultOnHit(): void
    {
        $cached = new AggregationResult(value: 42, groups: null, backend: 'postgres', cached: true);

        $this->cache->method('buildKey')->willReturn('some-key');
        $this->cache->method('get')->willReturn($cached);
        $this->cache->expects($this->never())->method('set');

        $q      = AggregationQuery::create(metric: 'count');
        $result = $this->runner->run(
            query: $q,
            register: $this->register,
            schema: $this->schema,
            name: 'byStatus',
            uid: 'user1'
        );

        $this->assertTrue(condition: $result->cached);
        $this->assertSame(expected: 42, actual: $result->value);
    }

    public function testRunFallsToPhpWhenNativeFailsAndNoIndexBackend(): void
    {
        $this->cache->method('buildKey')->willReturn('k');
        $this->cache->method('get')->willReturn(null);

        $backend = $this->createMock(SearchBackendInterface::class);
        $backend->method('isAvailable')->willReturn(false);
        $this->indexService->method('getBackend')->willReturn($backend);

        // db->prepare should throw to force PHP fallback.
        $this->db->method('prepare')->willThrowException(new \RuntimeException('pg down'));

        $object = $this->createMock(ObjectEntity::class);
        $object->method('getObject')->willReturn([]);
        $this->magicMapper->method('findAllInRegisterSchemaTable')->willReturn([$object, $object, $object]);

        $q      = AggregationQuery::create(metric: 'count');
        $result = $this->runner->run(
            query: $q,
            register: $this->register,
            schema: $this->schema,
            name: 'n',
            uid: 'u'
        );

        $this->assertSame(expected: 'php-fallback', actual: $result->backend);
        $this->assertSame(expected: 3.0, actual: (float) $result->value);
    }

    public function testTryNativeAggregationBuildsScalarCount(): void
    {
        $stmt = $this->createMock(\OCP\DB\IPreparedStatement::class);
        $stmt->method('execute')->willReturn($this->createMock(\OCP\DB\IResult::class));
        $stmt->method('fetch')->willReturn(['agg_value' => '7']);

        $this->db->method('prepare')->willReturn($stmt);

        $q      = AggregationQuery::create(metric: 'count');
        $result = $this->runner->tryNativeAggregation(query: $q, tableName: 'oc_openregister_table_1_1');

        $this->assertNotNull(actual: $result);
        $this->assertSame(expected: 'postgres', actual: $result->backend);
        $this->assertSame(expected: 7.0, actual: $result->value);
    }

}//end class
