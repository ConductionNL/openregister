<?php

declare(strict_types=1);

namespace Unit\Service\Aggregation;

use OCA\OpenRegister\Service\Aggregation\AggregationCache;
use OCA\OpenRegister\Service\Aggregation\AggregationResult;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for AggregationCache.
 *
 * @covers \OCA\OpenRegister\Service\Aggregation\AggregationCache
 */
class AggregationCacheTest extends TestCase
{

    private AggregationCache $cache;
    private ICache&MockObject $iCache;
    private ICacheFactory&MockObject $factory;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->iCache  = $this->createMock(ICache::class);
        $this->factory = $this->createMock(ICacheFactory::class);
        $this->logger  = $this->createMock(LoggerInterface::class);

        $this->factory
            ->method('createDistributed')
            ->willReturn($this->iCache);

        $this->cache = new AggregationCache(
            cacheFactory: $this->factory,
            logger: $this->logger
        );
    }

    public function testGetReturnsCachedResult(): void
    {
        $this->iCache
            ->method('get')
            ->willReturn(['value' => 42.0, 'backend' => 'postgres', 'groups' => null]);

        $result = $this->cache->get(key: 'some-key');

        $this->assertInstanceOf(expected: AggregationResult::class, actual: $result);
        $this->assertSame(expected: 42.0, actual: $result->value);
        $this->assertTrue(condition: $result->cached);
    }

    public function testGetReturnNullOnMiss(): void
    {
        $this->iCache->method('get')->willReturn(null);

        $result = $this->cache->get(key: 'miss-key');

        $this->assertNull(actual: $result);
    }

    public function testSetCallsCacheWithTtl(): void
    {
        $result = new AggregationResult(value: 5, groups: null, backend: 'postgres');

        $this->iCache
            ->expects($this->once())
            ->method('set')
            ->with($this->anything(), $this->isType('array'), AggregationCache::TTL);

        $this->cache->set(key: 'k', result: $result);
    }

    public function testEvictCallsClear(): void
    {
        $this->iCache->expects($this->once())->method('clear');

        $this->cache->evict();
    }

    public function testBuildKeyIsStableUnderFilterReorder(): void
    {
        $k1 = $this->cache->buildKey(
            registerSlug: 'reg',
            schemaSlug: 'sch',
            name: 'n',
            filters: ['b' => 1, 'a' => 2],
            rbacScope: 'user1'
        );
        $k2 = $this->cache->buildKey(
            registerSlug: 'reg',
            schemaSlug: 'sch',
            name: 'n',
            filters: ['a' => 2, 'b' => 1],
            rbacScope: 'user1'
        );

        $this->assertSame(expected: $k1, actual: $k2);
    }

    public function testBuildKeyDiffersForDifferentUsers(): void
    {
        $k1 = $this->cache->buildKey(
            registerSlug: 'r',
            schemaSlug: 's',
            name: 'n',
            filters: [],
            rbacScope: 'alice'
        );
        $k2 = $this->cache->buildKey(
            registerSlug: 'r',
            schemaSlug: 's',
            name: 'n',
            filters: [],
            rbacScope: 'bob'
        );

        $this->assertNotSame(expected: $k1, actual: $k2);
    }

    public function testGetReturnsNullOnException(): void
    {
        $this->iCache->method('get')->willThrowException(new \RuntimeException('cache down'));

        $result = $this->cache->get(key: 'any');

        $this->assertNull(actual: $result);
    }

    public function testSetSwallowsException(): void
    {
        $this->iCache->method('set')->willThrowException(new \RuntimeException('cache down'));

        $result = new AggregationResult(value: 0, groups: null, backend: 'postgres');

        // No exception thrown.
        $this->cache->set(key: 'k', result: $result);
        $this->addToAssertionCount(1);
    }

    public function testEvictSwallowsException(): void
    {
        $this->iCache->method('clear')->willThrowException(new \RuntimeException('cache down'));

        $this->cache->evict();
        $this->addToAssertionCount(1);
    }

    public function testAnonymousUserHasIsolatedScope(): void
    {
        $k1 = $this->cache->buildKey(
            registerSlug: 'r',
            schemaSlug: 's',
            name: 'n',
            filters: [],
            rbacScope: 'anonymous'
        );
        $k2 = $this->cache->buildKey(
            registerSlug: 'r',
            schemaSlug: 's',
            name: 'n',
            filters: [],
            rbacScope: 'alice'
        );

        $this->assertNotSame(expected: $k1, actual: $k2);
    }

    public function testBuildKeyHasExpectedPrefix(): void
    {
        $k = $this->cache->buildKey(
            registerSlug: 'myreg',
            schemaSlug: 'myschema',
            name: 'byStatus',
            filters: [],
            rbacScope: 'user'
        );

        $this->assertStringStartsWith(prefix: 'agg:myreg:myschema:byStatus:', string: $k);
    }

    public function testTtlIs60(): void
    {
        $this->assertSame(expected: 60, actual: AggregationCache::TTL);
    }

    public function testSetResultWithGroups(): void
    {
        $captured = null;
        $this->iCache
            ->method('set')
            ->willReturnCallback(function ($key, $value, $ttl) use (&$captured) {
                $captured = $value;
            });

        $result = new AggregationResult(
            value: 10,
            groups: [['group' => 'open', 'value' => 10]],
            backend: 'postgres'
        );

        $this->cache->set(key: 'k', result: $result);

        $this->assertArrayHasKey(key: 'groups', array: $captured ?? []);
    }

}//end class
