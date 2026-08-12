<?php

declare(strict_types=1);

namespace Unit\Service\Aggregation;

use OCA\OpenRegister\Service\Aggregation\AggregationCache;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * In-memory fake ICache backend. Backs the cache with a plain array so
 * tests can observe exactly what survives an evictForSchema() call,
 * instead of stubbing return values per-call as createMock() would
 * require.
 */
class InMemoryFakeCache implements ICache {
	/** @var array<string, mixed> */
	private array $store = [];

	public function get($key) {
		return ($this->store[$key] ?? null);
	}//end get()

	public function set($key, $value, $ttl = 0) {
		$this->store[$key] = $value;
		return true;
	}//end set()

	public function hasKey($key) {
		return array_key_exists($key, $this->store);
	}//end hasKey()

	public function remove($key) {
		unset($this->store[$key]);
		return true;
	}//end remove()

	public function clear($prefix = '') {
		$this->store = [];
		return true;
	}//end clear()

	public static function isAvailable(): bool {
		return true;
	}//end isAvailable()
}//end class

/**
 * Verifies that evictForSchema() performs a SCOPED eviction (per
 * (register, schema) version-counter bump) rather than the old
 * cache->clear() which wiped every schema's cached aggregations.
 *
 * scope-cache-invalidation: evicting schema A must not disturb schema
 * B's cached entries, and must invalidate A's own cached entries.
 */
class AggregationCacheScopeTest extends TestCase {
	private ICacheFactory&MockObject $cacheFactory;
	private InMemoryFakeCache $cache;
	private IUserSession&MockObject $userSession;
	private LoggerInterface&MockObject $logger;
	private OrganisationService&MockObject $organisationService;

	protected function setUp(): void {
		parent::setUp();
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->cache = new InMemoryFakeCache();
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->organisationService = $this->createMock(OrganisationService::class);

		$this->cacheFactory->method('createDistributed')
			->with('openregister_aggregations')
			->willReturn($this->cache);
	}

	private function makeCache(): AggregationCache {
		return new AggregationCache(
			$this->cacheFactory,
			$this->userSession,
			$this->logger,
			$this->organisationService
		);
	}

	public function testEvictingSchemaADoesNotClearSchemaBsCachedAggregate(): void {
		$cache = $this->makeCache();

		$cache->set('reg', 'schema-a', 'totalOpen', [], ['value' => 1]);
		$cache->set('reg', 'schema-b', 'totalOpen', [], ['value' => 2]);

		// Sanity: both are cached before any eviction.
		$this->assertSame(['value' => 1], $cache->get('reg', 'schema-a', 'totalOpen', []));
		$this->assertSame(['value' => 2], $cache->get('reg', 'schema-b', 'totalOpen', []));

		$cache->evictForSchema('reg', 'schema-a');

		// Schema B's cached aggregate must survive schema A's eviction —
		// the old cache->clear() implementation would have wiped it too.
		$this->assertSame(
			['value' => 2],
			$cache->get('reg', 'schema-b', 'totalOpen', []),
			'evicting schema A must not clear schema B\'s cached aggregate'
		);
	}

	public function testEvictForSchemaInvalidatesTheEvictedSchema(): void {
		$cache = $this->makeCache();

		$cache->set('reg', 'schema-a', 'totalOpen', [], ['value' => 1]);
		$this->assertSame(['value' => 1], $cache->get('reg', 'schema-a', 'totalOpen', []));

		$cache->evictForSchema('reg', 'schema-a');

		// The evicted schema's previously-cached entry must now be
		// unreachable: the version bump means get() computes a new key
		// that was never set(), so it's a miss.
		$this->assertNull(
			$cache->get('reg', 'schema-a', 'totalOpen', []),
			'evictForSchema(A) must invalidate A\'s own cached aggregate'
		);
	}

	public function testEvictionIsScopedPerRegisterToo(): void {
		$cache = $this->makeCache();

		// Same schema slug under two different registers must be
		// independent eviction scopes.
		$cache->set('reg-1', 'schema-a', 'totalOpen', [], ['value' => 10]);
		$cache->set('reg-2', 'schema-a', 'totalOpen', [], ['value' => 20]);

		$cache->evictForSchema('reg-1', 'schema-a');

		$this->assertNull($cache->get('reg-1', 'schema-a', 'totalOpen', []));
		$this->assertSame(
			['value' => 20],
			$cache->get('reg-2', 'schema-a', 'totalOpen', []),
			'evicting (reg-1, schema-a) must not clear (reg-2, schema-a)'
		);
	}

	public function testRepeatedEvictionsKeepBumpingTheVersionCounter(): void {
		$cache = $this->makeCache();

		$cache->set('reg', 'schema-a', 'totalOpen', [], ['value' => 1]);
		$cache->evictForSchema('reg', 'schema-a');

		// Re-populate after the first eviction, then evict again — the
		// second eviction must also invalidate the fresh entry (the
		// version counter must keep incrementing, not toggle/reset).
		$cache->set('reg', 'schema-a', 'totalOpen', [], ['value' => 2]);
		$this->assertSame(['value' => 2], $cache->get('reg', 'schema-a', 'totalOpen', []));

		$cache->evictForSchema('reg', 'schema-a');
		$this->assertNull($cache->get('reg', 'schema-a', 'totalOpen', []));
	}
}//end class
