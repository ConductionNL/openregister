<?php

/**
 * Unit tests for DiscoveryCatalog — collecting enabled apps' discovery
 * declarations, applying the admin switches, and caching.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Discovery
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/apphost-discovery-manifest/specs/apphost-discovery/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Discovery;

use OCA\OpenRegister\AppHost\Discovery\DiscoveryCatalog;
use OCA\OpenRegister\AppHost\Discovery\DiscoveryManifest;
use OCA\OpenRegister\AppHost\Observability\ManifestLoader;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class DiscoveryCatalogTest extends TestCase {

	/** @var array<string, array<string, mixed>> */
	private array $manifests = [];

	/** @var array<string, string> */
	private array $config = [];

	private IAppManager&MockObject $appManager;

	private ICacheFactory&MockObject $cacheFactory;

	private int $loads = 0;

	protected function setUp(): void {
		$this->manifests = [
			'decidiq' => ['discovery' => ['standards' => [['id' => 'ori', 'name' => 'ORI', 'role' => 'provides', 'access' => 'public', 'endpoint' => '/apps/decidiq/api/ori/v1']]]],
			'dossiq' => ['discovery' => [
				'standards' => [['id' => 'zgw-zaken', 'name' => 'ZGW Zaken', 'role' => 'provides', 'access' => 'token']],
				'ocmResourceTypes' => [['name' => 'dossiq-case', 'shareTypes' => ['user'], 'protocols' => ['dossiq' => '/apps/dossiq/api/federation']]],
			]],
			'private' => ['discovery' => ['public' => false, 'standards' => [['id' => 'x', 'name' => 'X', 'role' => 'provides']]]],
			'plain' => ['version' => '1.0.0'],
		];
		$this->appManager = $this->createMock(IAppManager::class);
		$this->appManager->method('getEnabledApps')->willReturnCallback(fn () => array_keys($this->manifests));
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->cacheFactory->method('isLocalCacheAvailable')->willReturn(false);
	}

	private function catalog(): DiscoveryCatalog {
		$loader = $this->createMock(ManifestLoader::class);
		$loader->method('appVersion')->willReturn('1.0.0');
		$loader->method('loadDiscovery')->willReturnCallback(function (string $appId): DiscoveryManifest {
			$this->loads++;
			return DiscoveryManifest::fromManifest(appId: $appId, manifest: $this->manifests[$appId]);
		});

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => $this->config[$key] ?? $default
		);

		return new DiscoveryCatalog(
			manifestLoader: $loader,
			appManager: $this->appManager,
			appConfig: $appConfig,
			cacheFactory: $this->cacheFactory,
			logger: new NullLogger(),
		);
	}

	public function testPublicDocumentsListOnlyOptedInAppsWithDeclarations(): void {
		$documents = $this->catalog()->publicDocuments();

		$this->assertSame(['decidiq', 'dossiq'], array_keys($documents));
		$this->assertSame('/apps/decidiq/api/ori/v1', $documents['decidiq']['standards'][0]['endpoint']);
		$this->assertSame(['dossiq-case'], $documents['dossiq']['ocm']);
	}

	public function testGlobalSwitchTurnsPublicationOff(): void {
		$this->config[DiscoveryCatalog::CONFIG_PUBLIC] = 'no';

		$catalog = $this->catalog();
		$this->assertFalse($catalog->isPublicationEnabled());
		$this->assertSame([], $catalog->publicDocuments());
	}

	public function testHiddenAppsAreNeverListed(): void {
		$this->config[DiscoveryCatalog::CONFIG_HIDDEN_APPS] = ' dossiq , unknown ';

		$this->assertSame(['decidiq'], array_keys($this->catalog()->publicDocuments()));
	}

	public function testOcmTypesAreIndependentOfThePublicationSwitches(): void {
		$this->config[DiscoveryCatalog::CONFIG_PUBLIC] = 'no';
		$this->config[DiscoveryCatalog::CONFIG_HIDDEN_APPS] = 'dossiq';

		$types = $this->catalog()->ocmResourceTypes();
		$this->assertCount(1, $types);
		$this->assertSame('dossiq', $types[0]['appId']);
		$this->assertSame('dossiq-case', $types[0]['name']);
	}

	public function testDuplicateOcmTypeKeepsFirstDeclaration(): void {
		$this->manifests['zzz'] = ['discovery' => ['ocmResourceTypes' => [
			['name' => 'dossiq-case', 'shareTypes' => ['user'], 'protocols' => ['x' => '/apps/zzz/api']],
		]]];

		$types = $this->catalog()->ocmResourceTypes();
		$this->assertCount(1, $types);
		$this->assertSame('dossiq', $types[0]['appId']);
	}

	public function testManifestsAreReadOncePerRequest(): void {
		$catalog = $this->catalog();
		$catalog->publicDocuments();
		$catalog->ocmResourceTypes();
		$catalog->publicDocuments();

		$this->assertSame(count($this->manifests), $this->loads);
	}

	public function testCachedResultIsReusedWithoutReadingManifests(): void {
		$store = [];
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(function (string $key) use (&$store) {
			return $store[$key] ?? null;
		});
		$cache->method('set')->willReturnCallback(function (string $key, $value) use (&$store): bool {
			$store[$key] = $value;
			return true;
		});
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->cacheFactory->method('isLocalCacheAvailable')->willReturn(true);
		$this->cacheFactory->method('createLocal')->willReturn($cache);

		$first = $this->catalog()->publicDocuments();
		$loadsAfterFirst = $this->loads;
		$second = $this->catalog()->publicDocuments();

		$this->assertSame($first, $second);
		$this->assertSame($loadsAfterFirst, $this->loads, 'a fresh catalog served from cache reads no manifest');
	}

	public function testEnablingAnAppChangesTheCacheKey(): void {
		$store = [];
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(function (string $key) use (&$store) {
			return $store[$key] ?? null;
		});
		$cache->method('set')->willReturnCallback(function (string $key, $value) use (&$store): bool {
			$store[$key] = $value;
			return true;
		});
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->cacheFactory->method('isLocalCacheAvailable')->willReturn(true);
		$this->cacheFactory->method('createLocal')->willReturn($cache);

		$this->catalog()->publicDocuments();
		$this->manifests['learniq'] = ['discovery' => ['standards' => [['id' => 'open-badges', 'name' => 'Open Badges', 'role' => 'provides']]]];

		$this->assertArrayHasKey('learniq', $this->catalog()->publicDocuments());
	}
}
