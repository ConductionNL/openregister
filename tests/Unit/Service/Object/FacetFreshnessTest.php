<?php

/**
 * OpenRegister FacetFreshnessTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Listener\FacetCacheInvalidationListener;
use OCA\OpenRegister\Service\Object\FacetCacheVersion;
use OCA\OpenRegister\Service\Object\FacetHandler;
use OCA\OpenRegister\Tests\Unit\Support\FakeMemcache;
use OCP\ICacheFactory;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A write must be visible in the next facet read.
 *
 * openregister#3560: `FacetHandler::getFacetsForObjects()` cached the whole
 * facet response for an hour under a key that no object write moved, so a
 * response could carry a live `results` array beside an hour-old `facets`
 * block. `CnFolderSidebar` builds a folder pane from that block, so an
 * administrator who gave a case type a new category got no folder for it
 * until the hour was out, and was still offered the category they had emptied.
 *
 * ⚠️ THIS FILE ASSERTS FRESHNESS, NOT CLEARABILITY. A test that only proved
 * the cache CAN be cleared would pass against the broken build, because the
 * broken build could already be cleared: `DELETE /api/settings/cache?type=facet`
 * did exactly that, and a schema change did it too. The failure was that
 * nothing did it on a write. So every assertion here reads a facet through the
 * public entry point after a write and requires the NEW bucket list, and the
 * counterpart assertions require an unrelated write to leave the cache HITTING
 * (`testAWriteToAnotherSchemaLeavesThisSchemaServingFromCache`), because a fix
 * that invalidates everything turns a caching layer into a cost centre.
 */
final class FacetFreshnessTest extends TestCase {
	/**
	 * Register id used by the query and by the written object.
	 *
	 * @var string
	 */
	private const REGISTER = '7';

	/**
	 * Schema id used by the query and by the written object.
	 *
	 * @var string
	 */
	private const SCHEMA = '42';

	/**
	 * The bucket list before the write.
	 *
	 * @var array<int, string>
	 */
	private const BEFORE = ['E2EZAAK-mttundof-1644 Vergunningen'];

	/**
	 * The bucket list after the write.
	 *
	 * @var array<int, string>
	 */
	private const AFTER = ['E2EZAAK-mttundof-1644 Vergunningen', 'ZZTEMP Probe Two'];

	private FakeMemcache $facetCache;

	private FakeMemcache $versionCache;

	private MagicMapper&MockObject $mapper;

	private FacetCacheVersion $versions;

	private FacetHandler $handler;

	/**
	 * Build a FacetHandler over two in-memory caches and a mapper whose facet
	 * result changes once, standing in for the row that was written.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->facetCache = new FakeMemcache();
		$this->versionCache = new FakeMemcache();

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturnCallback(
			function (string $namespace) {
				if ($namespace === FacetCacheVersion::CACHE_NAMESPACE) {
					return $this->versionCache;
				}

				return $this->facetCache;
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$logger = $this->createMock(LoggerInterface::class);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willThrowException(new \Exception('no schema in this unit test'));
		$schemaMapper->method('findMultiple')->willReturn([]);
		$schemaMapper->method('findAll')->willReturn([]);

		$this->mapper = $this->createMock(MagicMapper::class);

		$this->versions = new FacetCacheVersion($cacheFactory, $logger);

		$this->handler = new FacetHandler(
			$this->mapper,
			$schemaMapper,
			$cacheFactory,
			$userSession,
			$logger,
			$this->versions
		);
	}//end setUp()

	/**
	 * A category created now must have a bucket now, not in an hour.
	 *
	 * @return void
	 */
	public function testAnObjectWriteIsVisibleInTheNextFacetRead(): void {
		$this->mapperReturns(self::BEFORE, self::AFTER);

		// The read that warms the cache.
		$this->assertSame(
			self::BEFORE,
			$this->readCategoryBuckets(),
			'sanity: the first read reports the buckets the mapper computed'
		);

		// No write yet, so this read MUST come from cache: if it recomputed,
		// the test below could pass without any invalidation at all.
		$this->assertSame(
			self::BEFORE,
			$this->readCategoryBuckets(),
			'a repeated read with no write in between must still be served from cache'
		);
		$this->assertSame(
			1,
			$this->mapperCalls,
			'the cache must have absorbed the second read, or this test proves nothing'
		);

		// The write an administrator makes when they give a case type a new category.
		$this->writeObject(register: self::REGISTER, schema: self::SCHEMA);

		$this->assertSame(
			self::AFTER,
			$this->readCategoryBuckets(),
			'the next facet read after a write must carry the bucket that write created'
		);
	}//end testAnObjectWriteIsVisibleInTheNextFacetRead()

	/**
	 * The other half of the bargain: a write elsewhere must not cost a recompute.
	 *
	 * Facets exist because computing them is expensive. An invalidation that
	 * fires on every write anywhere would make every index page in the fleet
	 * recompute on every write in the fleet.
	 *
	 * @return void
	 */
	public function testAWriteToAnotherSchemaLeavesThisSchemaServingFromCache(): void {
		$this->mapperReturns(self::BEFORE, self::AFTER);

		$this->assertSame(self::BEFORE, $this->readCategoryBuckets());

		// A different schema in the same register, and a different register.
		$this->writeObject(register: self::REGISTER, schema: '43');
		$this->writeObject(register: '8', schema: '99');

		$this->assertSame(
			self::BEFORE,
			$this->readCategoryBuckets(),
			'a write to a schema this query does not read must leave the cached facet hitting'
		);
		$this->assertSame(
			1,
			$this->mapperCalls,
			'an unrelated write must not have triggered a recompute'
		);
	}//end testAWriteToAnotherSchemaLeavesThisSchemaServingFromCache()

	/**
	 * A facet computed across every schema cannot survive any write.
	 *
	 * A query naming no register and no schema is answered from the whole
	 * instance, so there is no write that provably cannot change it. It reads
	 * the global counter, which every write bumps.
	 *
	 * @return void
	 */
	public function testAnUnscopedFacetIsInvalidatedByAWriteAnywhere(): void {
		$this->mapperReturns(self::BEFORE, self::AFTER);

		$this->assertSame(self::BEFORE, $this->readCategoryBuckets(query: ['_facets' => 'extend']));

		$this->writeObject(register: '8', schema: '99');

		$this->assertSame(
			self::AFTER,
			$this->readCategoryBuckets(query: ['_facets' => 'extend']),
			'an unscoped facet must be recomputed after a write to any schema'
		);
	}//end testAnUnscopedFacetIsInvalidatedByAWriteAnywhere()

	/**
	 * The reported reproduction: an added sort parameter was a free cache-buster.
	 *
	 * Two requests differing only in `_order` returned different bucket lists
	 * at the same instant against the same rows, because the sort changed the
	 * cache key and nothing else. After the fix both requests agree, because
	 * both keys carry the same freshness token.
	 *
	 * @return void
	 */
	public function testAddingASortParameterNoLongerChangesTheBucketList(): void {
		$this->mapperReturns(self::BEFORE, self::AFTER);

		$this->readCategoryBuckets();
		$this->writeObject(register: self::REGISTER, schema: self::SCHEMA);

		$plain = $this->readCategoryBuckets();
		$sorted = $this->readCategoryBuckets(
			query: [
				'@self' => ['register' => (int)self::REGISTER, 'schema' => (int)self::SCHEMA],
				'_facets' => 'extend',
				'_order' => ['title' => 'asc'],
			]
		);

		$this->assertSame(
			$plain,
			$sorted,
			'two reads of the same rows at the same instant must agree, sorted or not'
		);
	}//end testAddingASortParameterNoLongerChangesTheBucketList()

	/**
	 * Number of times the mapper actually computed a facet.
	 *
	 * @var int
	 */
	private int $mapperCalls = 0;

	/**
	 * Program the mapper to report $before until a write lands, then $after.
	 *
	 * The switch is driven by the counter the listener bumps, so the mapper
	 * behaves like a database: it reports what is there now, and it is only
	 * ever asked when the cache misses.
	 *
	 * @param array<int, string> $before Bucket values before the write.
	 * @param array<int, string> $after  Bucket values after the write.
	 *
	 * @return void
	 */
	private function mapperReturns(array $before, array $after): void {
		$this->mapper->method('getSimpleFacets')->willReturnCallback(
			function () use ($before, $after) {
				++$this->mapperCalls;
				$values = $before;
				if ($this->writes > 0) {
					$values = $after;
				}

				$buckets = [];
				foreach ($values as $value) {
					$buckets[] = ['key' => $value, 'results' => 1];
				}

				return ['category' => ['type' => 'terms', 'buckets' => $buckets]];
			}
		);
	}//end mapperReturns()

	/**
	 * Number of object writes dispatched so far.
	 *
	 * @var int
	 */
	private int $writes = 0;

	/**
	 * Dispatch an object write the way the app does, through the real listener.
	 *
	 * Going through FacetCacheInvalidationListener rather than calling
	 * FacetCacheVersion directly is deliberate: the wiring between the event
	 * and the counter is exactly the part that can silently not exist.
	 *
	 * @param string $register Register id of the written object.
	 * @param string $schema   Schema id of the written object.
	 *
	 * @return void
	 */
	private function writeObject(string $register, string $schema): void {
		++$this->writes;

		$object = new \OCA\OpenRegister\Db\ObjectEntity();
		$object->setRegister($register);
		$object->setSchema($schema);

		$listener = new FacetCacheInvalidationListener($this->versions);
		$listener->handle(new ObjectCreatedEvent($object));
	}//end writeObject()

	/**
	 * Read the `category` bucket values through the public facet entry point.
	 *
	 * @param array<string, mixed>|null $query Query to face, defaults to the scoped index query.
	 *
	 * @return array<int, string> Bucket values in response order.
	 */
	private function readCategoryBuckets(?array $query = null): array {
		if ($query === null) {
			$query = [
				'@self' => ['register' => (int)self::REGISTER, 'schema' => (int)self::SCHEMA],
				'_facets' => 'extend',
			];
		}

		$result = $this->handler->getFacetsForObjects($query);
		$buckets = ($result['facets']['category']['data']['buckets'] ?? []);

		return array_map(static fn (array $b) => (string)$b['value'], $buckets);
	}//end readCategoryBuckets()
}//end class
