<?php

/**
 * GenericStoreService keeps a remote registry's search answer for SEARCH_TTL seconds.
 *
 * Apps list store cards on paths every page load reads; uncached, each load
 * waited on the registry (up to the 10 s timeout when it is slow or down).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Service;

use OCA\OpenRegister\AppHost\Service\GenericStoreService;
use OCA\OpenRegister\AppHost\Service\StoreDescriptor;
use OCA\OpenRegister\AppHost\Service\StoreListing;
use OCA\OpenRegister\AppHost\Service\StoreRegistryAccess;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Remote search answers are kept; a local registry is always read live.
 */
final class GenericStoreSearchCacheTest extends TestCase {

	/**
	 * Registry requests sent.
	 *
	 * @var int
	 */
	private int $requests = 0;

	/**
	 * TTLs the cache was given.
	 *
	 * @var array<int, int>
	 */
	private array $ttls = [];

	/**
	 * One in-memory cache shared by the factories of a test.
	 *
	 * @var array<string, mixed>
	 */
	private array $kept = [];

	/**
	 * A service over a registry at the given url, answering with the given body or failing.
	 *
	 * @param string      $url   The configured registry url.
	 * @param string|null $body  The 200 body, or null to throw on every request.
	 * @param bool        $cache Whether a cache factory is passed.
	 *
	 * @return GenericStoreService
	 */
	private function service(string $url, ?string $body, bool $cache=true): GenericStoreService {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'registry_url' => $url,
				default => $default,
			}
		);

		$response = $this->createMock(originalClassName: IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn((string)$body);

		$client = $this->createMock(originalClassName: IClient::class);
		$client->method('get')->willReturnCallback(
			function () use ($response, $body): IResponse {
				$this->requests++;
				if ($body === null) {
					throw new RuntimeException('registry down');
				}

				return $response;
			}
		);
		$clientService = $this->createMock(originalClassName: IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$caches = null;
		if ($cache === true) {
			$caches = $this->caches();
		}

		return new GenericStoreService(
			registry: new StoreRegistryAccess(clientService: $clientService, appConfig: $appConfig),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			cacheFactory: $caches
		);
	}//end service()

	/**
	 * A cache factory over this test's in-memory cache.
	 *
	 * @return ICacheFactory
	 */
	private function caches(): ICacheFactory {
		$cache = $this->createMock(originalClassName: ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key) => $this->kept[$key] ?? null);
		$cache->method('set')->willReturnCallback(
			function (string $key, mixed $value, int $ttl=0): bool {
				$this->kept[$key] = $value;
				$this->ttls[]     = $ttl;
				return true;
			}
		);

		$factory = $this->createMock(originalClassName: ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->method('createDistributed')->willReturn($cache);

		return $factory;
	}//end caches()

	/**
	 * The store descriptor of one app.
	 *
	 * @param string $appId The app.
	 *
	 * @return StoreDescriptor
	 */
	private static function store(string $appId='humaniq'): StoreDescriptor {
		return new StoreDescriptor(listing: new StoreListing(appId: $appId, schema: 'configuration', defaultRegister: $appId));
	}//end store()

	/**
	 * A second search with the same terms is answered from the cache.
	 *
	 * @return void
	 */
	public function testASecondSearchIsAnsweredFromTheCache(): void {
		$body  = '{"results":[{"slug":"leave-policy","title":"Leave policy"}]}';
		$first = $this->service(url: 'https://93.184.216.34/', body: $body)->search(descriptor: self::store());
		$again = $this->service(url: 'https://93.184.216.34/', body: $body)->search(descriptor: self::store());

		$this->assertSame(expected: 'ok', actual: $first['outcome']);
		$this->assertSame(expected: $first, actual: $again);
		$this->assertSame(expected: 1, actual: $this->requests);
		$this->assertSame(expected: [GenericStoreService::SEARCH_TTL], actual: $this->ttls);
	}//end testASecondSearchIsAnsweredFromTheCache()

	/**
	 * A failing registry is kept too, so the next page does not wait on it again.
	 *
	 * @return void
	 */
	public function testAFailingRegistryIsKeptToo(): void {
		$first = $this->service(url: 'https://93.184.216.34/', body: null)->search(descriptor: self::store());
		$again = $this->service(url: 'https://93.184.216.34/', body: null)->search(descriptor: self::store());

		$this->assertNotSame(expected: 'ok', actual: $first['outcome']);
		$this->assertSame(expected: $first, actual: $again);
		$this->assertSame(expected: 1, actual: $this->requests);
	}//end testAFailingRegistryIsKeptToo()

	/**
	 * Other terms, another app or another registry are separate entries.
	 *
	 * @return void
	 */
	public function testTermsAppAndRegistryEachGetTheirOwnEntry(): void {
		$body = '{"results":[]}';
		$this->service(url: 'https://93.184.216.34/', body: $body)->search(descriptor: self::store());
		$this->service(url: 'https://93.184.216.34/', body: $body)->search(descriptor: self::store(), query: 'leave');
		$this->service(url: 'https://93.184.216.34/', body: $body)->search(descriptor: self::store(appId: 'pipelinq'));
		$this->service(url: 'https://93.184.216.35/', body: $body)->search(descriptor: self::store());

		$this->assertSame(expected: 4, actual: $this->requests);
	}//end testTermsAppAndRegistryEachGetTheirOwnEntry()

	/**
	 * Without a cache factory every search asks the registry, as before.
	 *
	 * @return void
	 */
	public function testWithoutACacheEverySearchAsksTheRegistry(): void {
		$service = $this->service(url: 'https://93.184.216.34/', body: '{"results":[]}', cache: false);
		$service->search(descriptor: self::store());
		$service->search(descriptor: self::store());

		$this->assertSame(expected: 2, actual: $this->requests);
	}//end testWithoutACacheEverySearchAsksTheRegistry()
}//end class
