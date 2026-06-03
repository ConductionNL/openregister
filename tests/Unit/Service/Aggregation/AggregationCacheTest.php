<?php

namespace Unit\Service\Aggregation;

use OCA\OpenRegister\Service\Aggregation\AggregationCache;
use OCA\OpenRegister\Service\Aggregation\AggregationQuery;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for AggregationCache ad-hoc and named cache operations.
 *
 * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-3.2
 */
class AggregationCacheTest extends TestCase
{

    private ICache $memcache;
    private ICacheFactory $cacheFactory;
    private IUserSession $userSession;
    private LoggerInterface $logger;
    private AggregationCache $sut;

    protected function setUp(): void
    {
        $this->memcache   = $this->createMock(ICache::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->logger     = $this->createMock(LoggerInterface::class);

        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->cacheFactory
            ->method('createDistributed')
            ->willReturn($this->memcache);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');
        $this->userSession->method('getUser')->willReturn($user);

        $this->sut = new AggregationCache(
            cacheFactory: $this->cacheFactory,
            userSession: $this->userSession,
            logger: $this->logger
        );
    }

    /**
     * getAdhoc() returns null on cache miss.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-3.2
     */
    public function testGetAdhocReturnNullOnMiss(): void
    {
        $this->memcache->method('get')->willReturn(null);

        $query = new AggregationQuery(
            metric: 'count',
            field: null,
            filter: [],
            groupBy: null,
            dateBucket: null
        );

        $result = $this->sut->getAdhoc(
            registerSlug: 'testreg',
            schemaSlug: 'testschema',
            query: $query
        );

        $this->assertNull($result);
    }

    /**
     * setAdhoc() then getAdhoc() round-trips the envelope.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-3.2
     */
    public function testSetAdhocThenGetAdhocRoundTrips(): void
    {
        $envelope = ['groups' => [['key' => '2026-01-01T00:00:00Z', 'value' => 5]], 'backend' => 'php-fallback', 'cached' => false];
        $stored   = null;

        $this->memcache
            ->expects($this->once())
            ->method('set')
            ->willReturnCallback(function ($key, $value, $ttl) use (&$stored) {
                $stored = ['key' => $key, 'value' => $value];
            });

        $this->memcache
            ->method('get')
            ->willReturnCallback(function ($key) use (&$stored) {
                if ($stored !== null && $stored['key'] === $key) {
                    return $stored['value'];
                }

                return null;
            });

        $query = new AggregationQuery(
            metric: 'count',
            field: '_created',
            filter: [],
            groupBy: null,
            dateBucket: 'day'
        );

        $this->sut->setAdhoc(
            registerSlug: 'testreg',
            schemaSlug: 'testschema',
            query: $query,
            envelope: $envelope
        );

        $result = $this->sut->getAdhoc(
            registerSlug: 'testreg',
            schemaSlug: 'testschema',
            query: $query
        );

        $this->assertSame($envelope, $result);
    }

    /**
     * Ad-hoc and named entries don't collide: set() with name='foo' and setAdhoc()
     * with a query whose hash equals 'foo' are independent because of the 'adhoc:' prefix.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-3.2
     */
    public function testAdhocAndNamedEntriesDoNotCollide(): void
    {
        $capturedKeys = [];

        $this->memcache
            ->method('set')
            ->willReturnCallback(function ($key) use (&$capturedKeys) {
                $capturedKeys[] = $key;
            });

        $query = new AggregationQuery(
            metric: 'count',
            field: null,
            filter: [],
            groupBy: null,
            dateBucket: null
        );

        $this->sut->set(
            registerSlug: 'reg',
            schemaSlug: 'schema',
            name: 'myAnnotation',
            filter: [],
            envelope: ['backend' => 'postgres']
        );

        $this->sut->setAdhoc(
            registerSlug: 'reg',
            schemaSlug: 'schema',
            query: $query,
            envelope: ['backend' => 'mysql']
        );

        $this->assertCount(2, $capturedKeys);
        // Named key must not contain 'adhoc:'.
        $this->assertStringNotContainsString('adhoc:', $capturedKeys[0]);
        // Ad-hoc key must contain 'adhoc:'.
        $this->assertStringContainsString('adhoc:', $capturedKeys[1]);
        // The two keys must be different.
        $this->assertNotSame($capturedKeys[0], $capturedKeys[1]);
    }

    /**
     * evictForSchema() calls ICache::clear() which covers both kinds of entries.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-3.2
     */
    public function testEvictForSchemaClearsCacheNamespace(): void
    {
        $this->memcache
            ->expects($this->once())
            ->method('clear');

        $this->sut->evictForSchema(registerSlug: 'reg', schemaSlug: 'schema');
    }

    /**
     * When cache factory throws, getAdhoc() gracefully returns null.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-3.2
     */
    public function testGracefulDegradeWhenCacheUnavailable(): void
    {
        $brokenFactory = $this->createMock(ICacheFactory::class);
        $brokenFactory
            ->method('createDistributed')
            ->willThrowException(new \RuntimeException(message: 'Cache unavailable'));

        $sut = new AggregationCache(
            cacheFactory: $brokenFactory,
            userSession: $this->userSession,
            logger: $this->logger
        );

        $query = new AggregationQuery(
            metric: 'count',
            field: null,
            filter: [],
            groupBy: null,
            dateBucket: null
        );

        $result = $sut->getAdhoc(
            registerSlug: 'reg',
            schemaSlug: 'schema',
            query: $query
        );

        $this->assertNull($result);
    }
}//end class
