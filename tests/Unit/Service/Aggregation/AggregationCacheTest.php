<?php

declare(strict_types=1);

namespace Unit\Service\Aggregation;

use OCA\OpenRegister\Service\Aggregation\AggregationCache;
use OCA\OpenRegister\Service\Aggregation\AggregationQuery;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Verifies the cache is content-addressed (filter shape + RBAC scope)
 * and that get/set/evict route through the underlying ICache backend.
 *
 * The cache is the linchpin for aggregation correctness in production:
 * incorrect key derivation would cross-contaminate users, missing evicts
 * would surface stale numbers after writes.
 */
class AggregationCacheTest extends TestCase
{
    private ICacheFactory&MockObject $cacheFactory;
    private ICache&MockObject $cache;
    private IUserSession&MockObject $userSession;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->cache        = $this->createMock(ICache::class);
        $this->userSession  = $this->createMock(IUserSession::class);
        $this->logger       = $this->createMock(LoggerInterface::class);

        $this->cacheFactory->method('createDistributed')
            ->with('openregister_aggregations')
            ->willReturn($this->cache);
    }

    public function testGetReturnsNullOnMiss(): void
    {
        $this->cache->method('get')->willReturn(null);
        $cache = $this->makeCache();
        $this->assertNull($cache->get('reg', 'sch', 'totalOpen', []));
    }

    public function testGetReturnsDecodedArrayOnHit(): void
    {
        $this->cache->method('get')->willReturn(json_encode(['value' => 42, 'backend' => 'postgres']));
        $cache = $this->makeCache();
        $result = $cache->get('reg', 'sch', 'totalOpen', []);
        $this->assertSame(['value' => 42, 'backend' => 'postgres'], $result);
    }

    public function testGetReturnsNullOnMalformedBlob(): void
    {
        $this->cache->method('get')->willReturn('not json');
        $cache = $this->makeCache();
        $this->assertNull($cache->get('reg', 'sch', 'totalOpen', []));
    }

    public function testGetReturnsNullOnNonArrayJson(): void
    {
        $this->cache->method('get')->willReturn(json_encode('scalar'));
        $cache = $this->makeCache();
        $this->assertNull($cache->get('reg', 'sch', 'totalOpen', []));
    }

    public function testSetWritesEncodedJsonWithTtl(): void
    {
        $this->cache->expects($this->once())
            ->method('set')
            ->with(
                $this->isType('string'),
                $this->callback(fn($v) => is_string($v) && json_decode($v, true) === ['value' => 5]),
                AggregationCache::TTL
            );

        $cache = $this->makeCache();
        $cache->set('reg', 'sch', 'totalOpen', [], ['value' => 5]);
    }

    public function testEvictForSchemaBumpsVersionCounterNotGlobalClear(): void
    {
        // scope-cache-invalidation: eviction now bumps the (register,schema)
        // version counter instead of wiping the entire cache with clear().
        $this->cache->expects($this->never())->method('clear');
        $this->cache->method('get')->willReturn(3);
        $this->cache->expects($this->once())
            ->method('set')
            ->with(
                $this->stringContains('reg'),
                4,
                $this->anything()
            );
        $cache = $this->makeCache();
        $cache->evictForSchema('reg', 'sch');
    }

    public function testKeyIncludesAllInputs(): void
    {
        // Verify the key is deterministic and includes register, schema, name.
        $captured = [];
        $this->cache->method('set')->willReturnCallback(function (string $key) use (&$captured) {
            $captured[] = $key;
            return true;
        });

        $cache = $this->makeCache();
        $cache->set('reg-A', 'sch-A', 'totalOpen', [], []);
        $cache->set('reg-B', 'sch-A', 'totalOpen', [], []);
        $cache->set('reg-A', 'sch-B', 'totalOpen', [], []);
        $cache->set('reg-A', 'sch-A', 'totalOverdue', [], []);

        // Each call should produce a distinct cache key — none of the four
        // (register, schema, name) combos should collide.
        $this->assertCount(4, $captured);
        $this->assertCount(4, array_unique($captured), 'distinct (register, schema, name) should produce distinct keys');
        foreach ($captured as $key) {
            $this->assertStringStartsWith('agg:', $key);
        }
    }

    public function testKeyIsStableAcrossFilterOrder(): void
    {
        $captured = [];
        $this->cache->method('set')->willReturnCallback(function (string $key) use (&$captured) {
            $captured[] = $key;
            return true;
        });

        $cache = $this->makeCache();
        $cache->set('reg', 'sch', 'agg', ['a' => 1, 'b' => 2], []);
        $cache->set('reg', 'sch', 'agg', ['b' => 2, 'a' => 1], []);

        // Same filter content, different array order → same cache key
        // (the cache ksorts the filter before hashing).
        $this->assertSame($captured[0], $captured[1]);
    }

    public function testDifferentFiltersProduceDifferentKeys(): void
    {
        $captured = [];
        $this->cache->method('set')->willReturnCallback(function (string $key) use (&$captured) {
            $captured[] = $key;
            return true;
        });

        $cache = $this->makeCache();
        $cache->set('reg', 'sch', 'agg', ['status' => 'open'], []);
        $cache->set('reg', 'sch', 'agg', ['status' => 'closed'], []);
        $this->assertNotSame($captured[0], $captured[1]);
    }

    public function testRbacScopeIsolatesUsers(): void
    {
        $captured = [];
        $this->cache->method('set')->willReturnCallback(function (string $key) use (&$captured) {
            $captured[] = $key;
            return true;
        });

        $userA = $this->createMock(IUser::class);
        $userA->method('getUID')->willReturn('alice');
        $userB = $this->createMock(IUser::class);
        $userB->method('getUID')->willReturn('bob');

        // Two distinct users hitting the same aggregation must produce
        // distinct cache entries — otherwise alice could read bob's
        // RBAC-filtered result.
        $this->userSession->method('getUser')->willReturnOnConsecutiveCalls($userA, $userB);

        $cache = $this->makeCache();
        $cache->set('reg', 'sch', 'agg', [], []);
        $cache->set('reg', 'sch', 'agg', [], []);
        $this->assertNotSame($captured[0], $captured[1]);
    }

    public function testAnonymousUserHasStableScope(): void
    {
        $captured = [];
        $this->cache->method('set')->willReturnCallback(function (string $key) use (&$captured) {
            $captured[] = $key;
            return true;
        });

        $this->userSession->method('getUser')->willReturn(null);

        $cache = $this->makeCache();
        $cache->set('reg', 'sch', 'agg', [], []);
        $cache->set('reg', 'sch', 'agg', [], []);
        $this->assertSame($captured[0], $captured[1], 'anonymous calls should hit the same cache key');
    }

    public function testGetSwallowsBackendException(): void
    {
        $this->cache->method('get')->willThrowException(new \RuntimeException('redis down'));
        $cache = $this->makeCache();
        $this->assertNull($cache->get('reg', 'sch', 'agg', []));
    }

    public function testSetSwallowsBackendException(): void
    {
        $this->cache->method('set')->willThrowException(new \RuntimeException('redis down'));
        $cache = $this->makeCache();
        // Must not bubble — a cache write failure shouldn't break the response.
        $cache->set('reg', 'sch', 'agg', [], ['value' => 1]);
        $this->expectNotToPerformAssertions();
    }

    public function testCacheBackendUnavailableFailsClosed(): void
    {
        $factory = $this->createMock(ICacheFactory::class);
        $factory->method('createDistributed')->willThrowException(new \RuntimeException('no cache'));
        $this->logger->expects($this->once())->method('warning');

        $cache = new AggregationCache($factory, $this->userSession, $this->logger, $this->createMock(\OCA\OpenRegister\Service\OrganisationService::class));
        // get/set/evict must all be no-ops when the backend is unavailable.
        $this->assertNull($cache->get('reg', 'sch', 'agg', []));
        $cache->set('reg', 'sch', 'agg', [], ['value' => 1]);
        $cache->evictForSchema('reg', 'sch');
    }

    public function testGetAdhocReturnsNullOnMiss(): void
    {
        $this->cache->method('get')->willReturn(null);
        $cache = $this->makeCache();
        $query = AggregationQuery::create(metric: 'count');
        $this->assertNull($cache->getAdhoc('reg', 'sch', $query));
    }

    public function testSetAdhocRoundTripsThroughGetAdhoc(): void
    {
        $stored = null;
        $key    = null;
        $this->cache->method('set')->willReturnCallback(function (string $k, string $v) use (&$stored, &$key) {
            $key    = $k;
            $stored = $v;
            return true;
        });
        $this->cache->method('get')->willReturnCallback(function (string $k) use (&$stored, &$key) {
            return $k === $key ? $stored : null;
        });

        $cache = $this->makeCache();
        $query = AggregationQuery::create(
            metric: 'count',
            dateBucket: [
                'field' => 'created',
                'start' => '2026-05-01T00:00:00Z',
                'end'   => '2026-05-22T00:00:00Z',
                'gap'   => 'day',
            ]
        );

        $envelope = ['groups' => [['key' => '2026-05-01T00:00:00Z', 'value' => 7]], 'backend' => 'postgres', 'cached' => false];
        $cache->setAdhoc('reg', 'sch', $query, $envelope);
        $result = $cache->getAdhoc('reg', 'sch', $query);
        $this->assertSame($envelope, $result);
    }

    public function testAdhocAndNamedCacheKeysDoNotCollide(): void
    {
        $captured = [];
        $this->cache->method('set')->willReturnCallback(function (string $key) use (&$captured) {
            $captured[] = $key;
            return true;
        });

        $cache = $this->makeCache();
        $query = AggregationQuery::create(metric: 'count', filter: ['status' => 'open']);

        $cache->set('reg', 'sch', 'totalOpen', [], ['value' => 1]);
        $cache->setAdhoc('reg', 'sch', $query, ['value' => 1, 'backend' => 'postgres', 'cached' => false]);

        $this->assertCount(2, $captured);
        $this->assertNotSame(
            $captured[0],
            $captured[1],
            'ad-hoc cache entries MUST NOT collide with named-aggregation entries even when slugs match'
        );

        // The ad-hoc key must contain the literal "adhoc:" marker so it's
        // visually distinct in cache dumps.
        $this->assertStringContainsString('adhoc:', $captured[1]);
        $this->assertStringNotContainsString('adhoc:', $captured[0]);
    }

    public function testAdhocKeyIsStableUnderQueryFilterReordering(): void
    {
        $captured = [];
        $this->cache->method('set')->willReturnCallback(function (string $key) use (&$captured) {
            $captured[] = $key;
            return true;
        });

        $cache = $this->makeCache();
        $first  = AggregationQuery::create(
            metric: 'count',
            filter: ['status' => 'open', 'priority' => 'high']
        );
        $second = AggregationQuery::create(
            metric: 'count',
            filter: ['priority' => 'high', 'status' => 'open']
        );

        $cache->setAdhoc('reg', 'sch', $first, ['value' => 1, 'backend' => 'postgres', 'cached' => false]);
        $cache->setAdhoc('reg', 'sch', $second, ['value' => 1, 'backend' => 'postgres', 'cached' => false]);

        $this->assertSame($captured[0], $captured[1], 'ad-hoc keys MUST be stable under filter key reordering');
    }

    public function testDifferentAdhocQueriesProduceDifferentKeys(): void
    {
        $captured = [];
        $this->cache->method('set')->willReturnCallback(function (string $key) use (&$captured) {
            $captured[] = $key;
            return true;
        });

        $cache  = $this->makeCache();
        $dayQ   = AggregationQuery::create(
            metric: 'count',
            dateBucket: ['field' => 'created', 'start' => '2026-05-01T00:00:00Z', 'end' => '2026-05-22T00:00:00Z', 'gap' => 'day']
        );
        $hourQ  = AggregationQuery::create(
            metric: 'count',
            dateBucket: ['field' => 'created', 'start' => '2026-05-01T00:00:00Z', 'end' => '2026-05-22T00:00:00Z', 'gap' => 'hour']
        );

        $cache->setAdhoc('reg', 'sch', $dayQ, ['value' => 1, 'backend' => 'postgres', 'cached' => false]);
        $cache->setAdhoc('reg', 'sch', $hourQ, ['value' => 1, 'backend' => 'postgres', 'cached' => false]);

        $this->assertNotSame(
            $captured[0],
            $captured[1],
            'different ad-hoc queries (gap=day vs gap=hour) MUST produce different cache keys'
        );
    }

    public function testEvictForSchemaCoversAdhocEntriesViaVersionBump(): void
    {
        // scope-cache-invalidation: both named and ad-hoc aggregation keys embed
        // the (register,schema) version counter, so a single version bump makes
        // BOTH unreachable — without the old global clear() that also wiped every
        // other schema's/user's entries.
        $this->cache->expects($this->never())->method('clear');
        $this->cache->method('get')->willReturn(null);
        $this->cache->expects($this->once())
            ->method('set')
            ->with(
                $this->stringContains('reg'),
                1,
                $this->anything()
            );
        $cache = $this->makeCache();
        $cache->evictForSchema('reg', 'sch');
    }

    private function makeCache(): AggregationCache
    {
        return new AggregationCache($this->cacheFactory, $this->userSession, $this->logger, $this->createMock(\OCA\OpenRegister\Service\OrganisationService::class));
    }
}
