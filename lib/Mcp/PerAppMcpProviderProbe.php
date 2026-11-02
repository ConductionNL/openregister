<?php

/**
 * Finds the MCP tool providers that apps register by container alias.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp;

use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Container\ContainerInterface;

/**
 * Appends the IMcpToolProvider implementations that installed apps register
 * by container alias (the legacy discovery path).
 *
 * Tries up to three candidate keys per app — alias, ucfirst FQCN,
 * and namespace-from-info.xml FQCN — stopping at the first match.
 *
 * PROBE CACHE (#308) — the raw probe is expensive and, for a stock NC
 * instance, almost entirely negative: every installed app costs an
 * info.xml read + XML parse (buildMcpProviderCandidates()) plus a
 * throwing container lookup and an autoloader miss
 * (tryResolveMcpProviderCandidate()). This factory is registered
 * `$shared` so it already runs only once per request — the waste is
 * CROSS-request, once per MCP/chat turn.
 *
 * We therefore cache the discovery RESOLUTION MAP (appId => winning
 * candidate key, or null for "this app has no provider" — the negative
 * result is the whole point) in a distributed cache. Only the small
 * string map is cached: IMcpToolProvider instances are container-resolved
 * and request-scoped, so a warm request still calls $container->get() for
 * the handful of winners, but skips the per-app info.xml + candidate
 * probing entirely.
 *
 * Invalidation is twofold, because neither mechanism suffices alone:
 *  - the cache key embeds a hash of the installed-app list, so
 *    installing/removing an app rebuilds immediately; but
 *  - an app UPGRADE can add a provider class WITHOUT changing the app
 *    list, so a short clamped TTL bounds that staleness.
 *
 * Fail-open: with no distributed cache configured the map is never read
 * or written and behaviour is byte-for-byte the pre-cache path.
 *
 * NOTE: only the DISCOVERY result is cached. McpToolsService::addProvider()
 * remains a live runtime path for apps that self-register from their own
 * boot() when discovery misses them — it mutates the live instance and is
 * deliberately outside this cache.
 *
 * McpProviderDiscovery calls collectPerAppMcpProviders() once per McpToolsService build.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The probe resolves classes from every installed app.
 */
class PerAppMcpProviderProbe {

	/**
	 * The app whose app config holds the discovery-cache TTL.
	 *
	 * @var string
	 */
	private const CONFIG_APP_ID = 'openregister';

	/**
	 * Distributed-cache prefix for the per-app MCP provider discovery map.
	 *
	 * @var string
	 */
	private const MCP_PROBE_CACHE_PREFIX = 'openregister_mcp_provider_probe';

	/**
	 * IAppConfig key holding the MCP provider discovery-cache TTL in seconds.
	 *
	 * @var string
	 */
	private const MCP_PROBE_TTL_CONFIG_KEY = 'mcp.provider_probe_cache_ttl';

	/**
	 * Default MCP provider discovery-cache TTL in seconds.
	 *
	 * @var integer
	 */
	private const MCP_PROBE_TTL_DEFAULT = 60;

	/**
	 * Minimum allowed MCP provider discovery-cache TTL in seconds.
	 *
	 * @var integer
	 */
	private const MCP_PROBE_TTL_MIN = 10;

	/**
	 * Maximum allowed MCP provider discovery-cache TTL in seconds.
	 *
	 * @var integer
	 */
	private const MCP_PROBE_TTL_MAX = 600;

	/**
	 * Collect per-app providers by container alias (legacy path).
	 *
	 * Kept for one release while the fleet migrates to the registration event;
	 * removed once every app announces itself. The class docblock documents
	 * this method's caching behaviour.
	 *
	 * @param ContainerInterface $container The DI container.
	 * @param \Psr\Log\LoggerInterface $logger PSR logger.
	 * @param array<IMcpToolProvider> $providers Providers array (modified in place by reference).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/or-mcp-registration-event/specs/mcp-discovery/spec.md
	 */
	public function collectPerAppMcpProviders(
		ContainerInterface $container,
		\Psr\Log\LoggerInterface $logger,
		array &$providers,
	): void {
		try {
			$appManager = $container->get('OCP\App\IAppManager');
			$appIds = $appManager->getInstalledApps();

			$cache = $this->getMcpDiscoveryCache(container: $container, logger: $logger);
			$cacheKey = $this->buildMcpDiscoveryCacheKey(appIds: $appIds);
			$map = $this->readMcpDiscoveryMap(cache: $cache, cacheKey: $cacheKey);

			if ($map !== null) {
				// WARM — resolve only the known winners; no info.xml reads, no
				// class_exists() misses, no throwing container lookups.
				$this->materialiseMcpProvidersFromMap(
					container: $container,
					logger: $logger,
					map: $map,
					providers: $providers
				);
				return;
			}

			// COLD — run the full probe once and record the outcome per app.
			$map = [];
			foreach ($appIds as $appId) {
				$map[$appId] = null;
				$candidates = $this->buildMcpProviderCandidates(
					appId: $appId,
					appManager: $appManager
				);

				foreach ($candidates as $key) {
					$resolved = $this->tryResolveMcpProviderCandidate(
						container: $container,
						logger: $logger,
						appId: $appId,
						key: $key
					);
					if ($resolved !== null) {
						$providers[] = $resolved;
						$map[$appId] = $key;
						break;
					}
				}//end foreach
			}//end foreach

			$this->writeMcpDiscoveryMap(
				container: $container,
				cache: $cache,
				cacheKey: $cacheKey,
				map: $map
			);

			// One summary line per REBUILD replaces the ~2-3 debug lines per
			// missing app the raw probe used to emit on every single request.
			$logger->debug(
				'[Application] Per-app MCP provider discovery rebuilt',
				[
					'appsProbed' => count($map),
					'discovered' => array_keys(array_filter($map, static fn ($key) => $key !== null)),
					'cached' => ($cache !== null),
				]
			);
		} catch (\Throwable $e) {
			$logger->warning(
				'[Application] Per-app provider enumeration failed: ' . $e->getMessage()
			);
		}//end try
	}//end collectPerAppMcpProviders()

	/**
	 * Append providers for a cached discovery map's winning candidate keys.
	 *
	 * A cached key can go stale within the TTL (e.g. an app disabled mid-window),
	 * so each key is still resolved through the normal fail-soft path and a
	 * failure simply yields no provider for that app.
	 *
	 * @param ContainerInterface $container The DI container.
	 * @param \Psr\Log\LoggerInterface $logger PSR logger.
	 * @param array<string, string|null> $map Cached appId => winning key|null map.
	 * @param array<IMcpToolProvider> $providers Providers array (modified in place by reference).
	 *
	 * @return void
	 */
	private function materialiseMcpProvidersFromMap(
		ContainerInterface $container,
		\Psr\Log\LoggerInterface $logger,
		array $map,
		array &$providers,
	): void {
		foreach ($map as $appId => $key) {
			if ($key === null) {
				// Cached negative result — the app was probed on a previous
				// request and ships no MCP tool provider. Skip silently.
				continue;
			}

			$resolved = $this->tryResolveMcpProviderCandidate(
				container: $container,
				logger: $logger,
				appId: (string)$appId,
				key: $key
			);
			if ($resolved !== null) {
				$providers[] = $resolved;
			}
		}//end foreach
	}//end materialiseMcpProvidersFromMap()

	/**
	 * Obtain the distributed discovery cache, or null when unavailable.
	 *
	 * Only armed when a genuinely DISTRIBUTED memory cache is configured
	 * (ICacheFactory::isAvailable()); createDistributed() otherwise silently
	 * degrades to a node-local backend. Returning null disables the cache and
	 * restores the exact pre-cache behaviour (constraint: fail open).
	 *
	 * @param ContainerInterface $container The DI container.
	 * @param \Psr\Log\LoggerInterface $logger PSR logger.
	 *
	 * @return ICache|null The cache, or null when no distributed backend exists.
	 */
	private function getMcpDiscoveryCache(
		ContainerInterface $container,
		\Psr\Log\LoggerInterface $logger,
	): ?ICache {
		try {
			$cacheFactory = $container->get(ICacheFactory::class);
			if ($cacheFactory instanceof ICacheFactory === false || $cacheFactory->isAvailable() === false) {
				return null;
			}

			return $cacheFactory->createDistributed(prefix: self::MCP_PROBE_CACHE_PREFIX);
		} catch (\Throwable $e) {
			$logger->debug(
				'[Application] MCP discovery cache unavailable: ' . $e->getMessage()
			);
			return null;
		}//end try
	}//end getMcpDiscoveryCache()

	/**
	 * Build the discovery-map cache key for an installed-app list.
	 *
	 * The list is sorted before hashing so a pure ordering change from
	 * IAppManager does not needlessly invalidate the map.
	 *
	 * @param string[] $appIds The installed app ids.
	 *
	 * @return string The cache key.
	 */
	private function buildMcpDiscoveryCacheKey(array $appIds): string {
		$sorted = $appIds;
		sort($sorted);

		return 'map:' . hash('sha256', implode(',', $sorted));
	}//end buildMcpDiscoveryCacheKey()

	/**
	 * Read the cached discovery map, or null on miss / disabled cache.
	 *
	 * @param ICache|null $cache The cache, or null when disabled.
	 * @param string $cacheKey The cache key.
	 *
	 * @return array<string, string|null>|null The cached map, or null on miss.
	 */
	private function readMcpDiscoveryMap(?ICache $cache, string $cacheKey): ?array {
		if ($cache === null) {
			return null;
		}

		$blob = $cache->get(key: $cacheKey);
		if (is_string($blob) === false) {
			return null;
		}

		$data = json_decode($blob, true);
		if (is_array($data) === false) {
			return null;
		}

		$map = [];
		foreach ($data as $appId => $key) {
			if ($key === null) {
				$map[(string)$appId] = null;
				continue;
			}

			if (is_string($key) === false || $key === '') {
				// Corrupt entry — discard the whole blob rather than trust it.
				return null;
			}

			$map[(string)$appId] = $key;
		}//end foreach

		return $map;
	}//end readMcpDiscoveryMap()

	/**
	 * Write the discovery map to the cache with the clamped TTL.
	 *
	 * @param ContainerInterface $container The DI container (for the TTL config read).
	 * @param ICache|null $cache The cache, or null when disabled.
	 * @param string $cacheKey The cache key.
	 * @param array<string, string|null> $map The appId => winning key|null map.
	 *
	 * @return void
	 */
	private function writeMcpDiscoveryMap(
		ContainerInterface $container,
		?ICache $cache,
		string $cacheKey,
		array $map,
	): void {
		if ($cache === null) {
			return;
		}

		$blob = json_encode($map);
		if (is_string($blob) === false) {
			return;
		}

		$cache->set(key: $cacheKey, value: $blob, ttl: $this->resolveMcpProbeCacheTtl(container: $container));
	}//end writeMcpDiscoveryMap()

	/**
	 * Resolve the discovery-cache TTL, clamped to the allowed range.
	 *
	 * The TTL is the ONLY thing that catches a provider class added by an app
	 * UPGRADE (which leaves the installed-app list, and therefore the cache
	 * key, unchanged) — so it is deliberately short and bounded.
	 *
	 * @param ContainerInterface $container The DI container.
	 *
	 * @return int The TTL in seconds.
	 */
	private function resolveMcpProbeCacheTtl(ContainerInterface $container): int {
		try {
			$ttl = $container->get(IAppConfig::class)->getValueInt(
				self::CONFIG_APP_ID,
				self::MCP_PROBE_TTL_CONFIG_KEY,
				self::MCP_PROBE_TTL_DEFAULT
			);
		} catch (\Throwable $e) {
			$ttl = self::MCP_PROBE_TTL_DEFAULT;
		}

		return max(self::MCP_PROBE_TTL_MIN, min(self::MCP_PROBE_TTL_MAX, $ttl));
	}//end resolveMcpProbeCacheTtl()

	/**
	 * Build the list of candidate lookup keys for a given app's MCP tool provider.
	 *
	 * Produces up to three candidates:
	 * 1. The alias key `OCA\OpenRegister\Mcp\IMcpToolProvider::<appId>`.
	 * 2. The canonical FQCN built from ucfirst($appId).
	 * 3. (Optional) A FQCN derived from the `<namespace>` declared in info.xml,
	 *    when the declared namespace differs from ucfirst($appId) (covers camel-cased
	 *    app names like `openbuild` → `OpenBuild`).
	 *
	 * Protected as a test seam (#308): the probe-cache tests override this to
	 * count how often the expensive info.xml read actually runs.
	 *
	 * @param string $appId The Nextcloud app id.
	 * @param mixed $appManager The IAppManager instance.
	 *
	 * @return string[] Ordered candidate key list.
	 */
	protected function buildMcpProviderCandidates(string $appId, $appManager): array {
		$candidates = [
			'OCA\\OpenRegister\\Mcp\\IMcpToolProvider::' . $appId,
			'OCA\\' . ucfirst($appId) . '\\Mcp\\' . ucfirst($appId) . 'ToolProvider',
		];

		// Third candidate: trust <namespace> from info.xml when the app declares
		// one that differs from ucfirst($appId). NC's bootstrap nulls libxml's
		// external-entity resolver for XXE-hardening, which makes
		// simplexml_load_file() return false on well-formed files; read first,
		// then parse the string.
		try {
			$infoPath = $appManager->getAppPath($appId) . '/appinfo/info.xml';
			if (is_file($infoPath) === true) {
				$body = file_get_contents($infoPath);
				if ($body !== false && $body !== '') {
					$useErrors = libxml_use_internal_errors(true);
					$xml = simplexml_load_string($body);
					libxml_use_internal_errors($useErrors);
					if ($xml !== false && isset($xml->namespace) === true) {
						$namespace = (string)$xml->namespace;
						if ($namespace !== '' && $namespace !== ucfirst($appId)) {
							$candidates[] = 'OCA\\' . $namespace . '\\Mcp\\' . $namespace . 'ToolProvider';
						}
					}
				}
			}
		} catch (\Throwable $e) {
			// Path-resolution failures are benign; the other two
			// candidates already cover the common case.
		}

		return $candidates;
	}//end buildMcpProviderCandidates()

	/**
	 * Try to resolve a single MCP provider candidate key from the container.
	 *
	 * Returns the resolved IMcpToolProvider on success, or null when the
	 * candidate does not exist, could not be resolved, or resolved to a
	 * non-IMcpToolProvider object.
	 *
	 * Protected as a test seam (#308): the probe-cache tests override this to
	 * count how many candidate resolutions a request actually performs.
	 *
	 * @param ContainerInterface $container The DI container.
	 * @param \Psr\Log\LoggerInterface $logger PSR logger.
	 * @param string $appId The Nextcloud app id (for logging).
	 * @param string $key The candidate lookup key.
	 *
	 * @return IMcpToolProvider|null The resolved provider or null.
	 *
	 * @spec openspec/specs/chat-ai/spec.md#requirement-mcptoolsservice-provider-discovery-refactor
	 */
	protected function tryResolveMcpProviderCandidate(
		ContainerInterface $container,
		\Psr\Log\LoggerInterface $logger,
		string $appId,
		string $key,
	): ?IMcpToolProvider {
		try {
			if (str_contains($key, '\\') === true && str_contains($key, '::') === false) {
				// FQCN — only try if the class actually exists; calling get() on a
				// non-existent class would throw NotFoundExceptionInterface for every
				// installed app (noisy).
				if (class_exists($key) === false) {
					// Expected for every installed app that ships no MCP tool
					// provider — i.e. almost all of them. Deliberately silent:
					// this fired ~2 lines per installed app per request (#308).
					// The single summary line in collectPerAppMcpProviders()
					// carries the same information at 1/143rd the volume.
					return null;
				}
			}

			$appProvider = $container->get($key);
			if ($appProvider instanceof IMcpToolProvider) {
				$logger->info(
					'[Application] Discovered per-app tool provider',
					['appId' => $appId, 'via' => $key, 'class' => get_class($appProvider)]
				);
				return $appProvider;
			}

			// Get_debug_type() returns the FQCN for objects and the type name for scalars.
			$logger->warning(
				'[Application] Resolved but not IMcpToolProvider',
				['appId' => $appId, 'via' => $key, 'class' => get_debug_type($appProvider)]
			);
		} catch (\Psr\Container\NotFoundExceptionInterface $e) {
			// Alias key not registered — the expected case for every app
			// without an MCP provider, i.e. almost all of them. Deliberately
			// silent; see the class_exists() branch above (#308).
			return null;
		} catch (\Throwable $e) {
			// A genuine misconfiguration (the alias IS registered but its
			// factory blew up) — this one is worth hearing about.
			$logger->warning(
				'[Application] Resolve failed',
				['appId' => $appId, 'key' => $key, 'error' => $e->getMessage()]
			);
		}//end try

		return null;
	}//end tryResolveMcpProviderCandidate()
}//end class
