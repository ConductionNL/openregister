<?php

/**
 * Unit tests for the facet response cache: fallbacks, keys, get and put.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\FacetCacheVersion;
use OCA\OpenRegister\Service\Object\FacetResponseCache;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The cache-backend fallbacks, the RBAC-aware key and the failure tolerance of get and put.
 *
 * @covers \OCA\OpenRegister\Service\Object\FacetResponseCache
 */
class FacetResponseCacheBehaviourTest extends TestCase {

	/**
	 * The cache factory, mocked.
	 *
	 * @var ICacheFactory&MockObject
	 */
	private ICacheFactory&MockObject $factory;

	/**
	 * The user session, mocked.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * The freshness counters, mocked.
	 *
	 * @var FacetCacheVersion&MockObject
	 */
	private FacetCacheVersion&MockObject $versions;

	/**
	 * The logger, mocked.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->factory  = $this->createMock(ICacheFactory::class);
		$this->session  = $this->createMock(IUserSession::class);
		$this->versions = $this->createMock(FacetCacheVersion::class);
		$this->versions->method('tokenForScope')->willReturn('t0');
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * The cache under test.
	 *
	 * @return FacetResponseCache
	 */
	private function cache(): FacetResponseCache {
		return new FacetResponseCache($this->factory, $this->session, $this->versions, $this->logger);

	}//end cache()

	/**
	 * Without a distributed cache the local one serves the hits.
	 *
	 * @return void
	 */
	public function testFallsBackToTheLocalCache(): void {
		$local = $this->createMock(ICache::class);
		$local->method('get')->with('k')->willReturn(['facets' => ['a' => 1]]);
		$this->factory->method('createDistributed')->willThrowException(new RuntimeException('no redis'));
		$this->factory->expects($this->once())->method('createLocal')->with('openregister_facets')->willReturn($local);
		$this->logger->expects($this->never())->method('warning');

		$hit = $this->cache()->get('k');

		$this->assertSame(['a' => 1], $hit['facets']);
		$this->assertTrue($hit['performance_metadata']['cache_hit']);

	}//end testFallsBackToTheLocalCache()

	/**
	 * Without any cache the miss is logged once and get and put do nothing.
	 *
	 * @return void
	 */
	public function testWithoutAnyCacheGetMissesAndPutIsSkipped(): void {
		$this->factory->method('createDistributed')->willThrowException(new RuntimeException('no redis'));
		$this->factory->method('createLocal')->willThrowException(new RuntimeException('no apcu'));
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'[FacetResponseCache] Facet caching unavailable',
				$this->callback(static fn (array $context): bool => $context['error'] === 'no apcu')
			);
		$this->logger->expects($this->never())->method('debug');

		$cache = $this->cache();
		$this->assertNull($cache->get('k'));
		$cache->put('k', ['facets' => []]);

	}//end testWithoutAnyCacheGetMissesAndPutIsSkipped()

	/**
	 * A failing cache read is a miss, not an error.
	 *
	 * @return void
	 */
	public function testAFailingReadIsAMiss(): void {
		$backend = $this->createMock(ICache::class);
		$backend->method('get')->willThrowException(new RuntimeException('timeout'));
		$this->factory->method('createDistributed')->willReturn($backend);

		$this->assertNull($this->cache()->get('k'));

	}//end testAFailingReadIsAMiss()

	/**
	 * A stored null is a miss.
	 *
	 * @return void
	 */
	public function testANullEntryIsAMiss(): void {
		$backend = $this->createMock(ICache::class);
		$backend->method('get')->willReturn(null);
		$this->factory->method('createDistributed')->willReturn($backend);
		$this->logger->expects($this->never())->method('debug');

		$this->assertNull($this->cache()->get('k'));

	}//end testANullEntryIsAMiss()

	/**
	 * Put stores the result for an hour and logs its strategy.
	 *
	 * @return void
	 */
	public function testPutStoresWithTheTtlAndLogsTheStrategy(): void {
		$result  = ['performance_metadata' => ['fallback_used' => true, 'strategy' => 'collection']];
		$backend = $this->createMock(ICache::class);
		$backend->expects($this->once())->method('set')->with('k', $result, 3600);
		$this->factory->method('createDistributed')->willReturn($backend);
		$this->logger->expects($this->once())
			->method('debug')
			->with(
				'[FacetResponseCache] Facet response cached',
				$this->callback(static fn (array $c): bool => $c['ttl'] === 3600 && $c['strategy'] === 'collection')
			);

		$this->cache()->put('k', $result);

	}//end testPutStoresWithTheTtlAndLogsTheStrategy()

	/**
	 * A result without strategy metadata logs it as unknown.
	 *
	 * @return void
	 */
	public function testPutWithoutMetadataLogsUnknownStrategy(): void {
		$backend = $this->createMock(ICache::class);
		$backend->expects($this->once())->method('set')->with('k', ['facets' => []], 3600);
		$this->factory->method('createDistributed')->willReturn($backend);
		$this->logger->expects($this->once())
			->method('debug')
			->with($this->anything(), $this->callback(static fn (array $c): bool => $c['strategy'] === 'unknown'));

		$this->cache()->put('k', ['facets' => []]);

	}//end testPutWithoutMetadataLogsUnknownStrategy()

	/**
	 * A failing cache write is swallowed and not logged as cached.
	 *
	 * @return void
	 */
	public function testAFailingWriteIsSwallowed(): void {
		$backend = $this->createMock(ICache::class);
		$backend->expects($this->once())->method('set')->willThrowException(new RuntimeException('full'));
		$this->factory->method('createDistributed')->willReturn($backend);
		$this->logger->expects($this->never())->method('debug');

		$this->cache()->put('k', ['facets' => []]);

	}//end testAFailingWriteIsSwallowed()

	/**
	 * The key differs per user, with anonymous as its own identity.
	 *
	 * @return void
	 */
	public function testTheKeyDependsOnTheUser(): void {
		$alice = $this->createMock(IUser::class);
		$alice->method('getUID')->willReturn('alice');
		$bob = $this->createMock(IUser::class);
		$bob->method('getUID')->willReturn('bob');
		$this->session->method('getUser')->willReturnOnConsecutiveCalls($alice, $bob, null, $alice);

		$cache = $this->cache();
		$keys  = [];
		for ($i = 0; $i < 4; $i++) {
			$keys[] = $cache->keyFor(['status' => 'open'], ['status' => []]);
		}

		$this->assertStringStartsWith('facet_rbac_', $keys[0]);
		$this->assertCount(3, array_unique(array_slice($keys, 0, 3)));
		$this->assertSame($keys[0], $keys[3]);

	}//end testTheKeyDependsOnTheUser()

	/**
	 * The organisation scopes the key; the `_facets` request itself does not.
	 *
	 * @return void
	 */
	public function testTheKeyScopesOrganisationButIgnoresTheFacetsParameter(): void {
		$cache = $this->cache();
		$plain = $cache->keyFor(['status' => 'open'], ['status' => []]);

		$this->assertSame($plain, $cache->keyFor(['status' => 'open', '_facets' => 'extend'], ['status' => []]));
		$this->assertNotSame($plain, $cache->keyFor(['status' => 'open', '@self' => ['organisation' => 'org-1']], ['status' => []]));
		$this->assertNotSame($plain, $cache->keyFor(['status' => 'open'], ['type' => []]));

	}//end testTheKeyScopesOrganisationButIgnoresTheFacetsParameter()

	/**
	 * The freshness token is asked for the scope ids of every spelling, de-duplicated, invalid ids dropped.
	 *
	 * @return void
	 */
	public function testTheFreshnessScopeCollectsEverySpelling(): void {
		$versions = $this->createMock(FacetCacheVersion::class);
		$versions->expects($this->once())
			->method('tokenForScope')
			->with(['1', '2', '3'], ['7'])
			->willReturn('t1');
		$this->versions = $versions;

		$this->cache()->keyFor(
			[
				'@self'      => ['registers' => [1, '2', ''], 'register' => 2, 'schema' => ['x' => 7.5, 'y' => '7']],
				'_registers' => '3',
			],
			[]
		);

	}//end testTheFreshnessScopeCollectsEverySpelling()

	/**
	 * A changed freshness token changes the key.
	 *
	 * @return void
	 */
	public function testABumpedFreshnessTokenChangesTheKey(): void {
		$versions = $this->createMock(FacetCacheVersion::class);
		$versions->method('tokenForScope')->willReturnOnConsecutiveCalls('t1', 't2');
		$this->versions = $versions;

		$cache = $this->cache();
		$this->assertNotSame($cache->keyFor(['_schemas' => [7]], []), $cache->keyFor(['_schemas' => [7]], []));

	}//end testABumpedFreshnessTokenChangesTheKey()

	/**
	 * The key is the md5 digest of the RBAC scope, so it stays a cryptographic hash of user, org and filters.
	 *
	 * @return void
	 */
	public function testTheKeyIsTheMd5DigestOfTheRbacScope(): void {
		$scope = [
			'facets'    => ['status' => []],
			'filters'   => ['status' => 'open'],
			'user'      => 'anonymous',
			'org'       => null,
			'version'   => '2.0',
			'freshness' => 't0',
		];

		$this->assertSame(
			'facet_rbac_' . md5(json_encode($scope)),
			$this->cache()->keyFor(['status' => 'open', '_facets' => 'extend'], ['status' => []])
		);

	}//end testTheKeyIsTheMd5DigestOfTheRbacScope()
}//end class
