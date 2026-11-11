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
use Psr\Log\LoggerInterface;

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
	 * @param McpDiscoveryRun $run The discovery: container, logger and the providers collected so far.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/or-mcp-registration-event/specs/mcp-discovery/spec.md
	 */
	public function collectPerAppMcpProviders(McpDiscoveryRun $run): void {
		try {
			$this->collect(run: $run);
		} catch (\Throwable $e) {
			$run->logger->warning('[Application] Per-app provider enumeration failed: ' . $e->getMessage());
		}
	}//end collectPerAppMcpProviders()

	/**
	 * Collect from the cached discovery map when warm, else probe every app and cache the outcome.
	 *
	 * @param McpDiscoveryRun $run The discovery: container, logger and the providers collected so far.
	 *
	 * @return void
	 */
	private function collect(McpDiscoveryRun $run): void {
		$appManager = $run->container->get('OCP\App\IAppManager');
		$appIds     = $appManager->getInstalledApps();
		$cache      = $this->getMcpDiscoveryCache(container: $run->container, logger: $run->logger);
		$cacheKey   = $this->buildMcpDiscoveryCacheKey(appIds: $appIds);

		$map = $this->readMcpDiscoveryMap(cache: $cache, cacheKey: $cacheKey);
		if ($map !== null) {
			// WARM — resolve only the known winners; no info.xml reads, no
			// class_exists() misses, no throwing container lookups.
			$this->materialiseMcpProvidersFromMap(run: $run, map: $map);
			return;
		}

		// COLD — run the full probe once and record the outcome per app.
		$hits = $this->probeApps(run: $run, apps: new InstalledApps(appManager: $appManager, appIds: $appIds));
		$run->addAll(providers: self::providersOf(hits: $hits));
		$entry = new McpDiscoveryCacheEntry(
			cache: $cache,
			key: $cacheKey,
			map: array_map(static fn (array $hit): ?string => array_key_first($hit), $hits)
		);
		$this->writeMcpDiscoveryMap(run: $run, entry: $entry);
		$this->logRebuild(logger: $run->logger, entry: $entry);
	}//end collect()

	/**
	 * Probe every installed app for its first resolving provider.
	 *
	 * @param McpDiscoveryRun $run  The discovery: container and logger.
	 * @param InstalledApps   $apps The app manager and the installed app ids.
	 *
	 * @return array<string, array<string, IMcpToolProvider>> appId => its winning key => provider, or empty.
	 */
	private function probeApps(McpDiscoveryRun $run, InstalledApps $apps): array {
		$hits = [];
		foreach ($apps->appIds as $appId) {
			$hits[$appId] = $this->probeApp(run: $run, candidates: $this->candidatesOf(appId: $appId, apps: $apps));
		}

		return $hits;
	}//end probeApps()

	/**
	 * The candidates of one app, in the order they are tried.
	 *
	 * @param string        $appId The app id.
	 * @param InstalledApps $apps  The app manager and the installed app ids.
	 *
	 * @return list<McpProviderCandidate> The candidates.
	 */
	private function candidatesOf(string $appId, InstalledApps $apps): array {
		return array_map(
			static fn (string $key): McpProviderCandidate => new McpProviderCandidate(appId: $appId, key: $key),
			array_values($this->buildMcpProviderCandidates(appId: $appId, appManager: $apps->appManager))
		);
	}//end candidatesOf()

	/**
	 * The providers of a probe, in app order.
	 *
	 * @param array<string, array<string, IMcpToolProvider>> $hits appId => its winning key => provider, or empty.
	 *
	 * @return array<int, IMcpToolProvider> The providers found.
	 */
	private static function providersOf(array $hits): array {
		$providers = [];
		foreach ($hits as $hit) {
			$providers = [...$providers, ...array_values($hit)];
		}

		return $providers;
	}//end providersOf()

	/**
	 * The first candidate of an app that resolves to a provider.
	 *
	 * @param McpDiscoveryRun            $run        The discovery: container and logger.
	 * @param list<McpProviderCandidate> $candidates The app's candidates, in order.
	 *
	 * @return array<string, IMcpToolProvider> The winning key => provider, or empty.
	 */
	private function probeApp(McpDiscoveryRun $run, array $candidates): array {
		foreach ($candidates as $candidate) {
			$resolved = $this->tryResolveMcpProviderCandidate(run: $run, candidate: $candidate);
			if ($resolved !== null) {
				return [$candidate->key => $resolved];
			}
		}

		return [];
	}//end probeApp()

	/**
	 * One summary line per REBUILD replaces the ~2-3 debug lines per
	 * missing app the raw probe used to emit on every single request.
	 *
	 * @param LoggerInterface        $logger PSR logger.
	 * @param McpDiscoveryCacheEntry $entry  The rebuilt map and whether a cache was written.
	 *
	 * @return void
	 */
	private function logRebuild(LoggerInterface $logger, McpDiscoveryCacheEntry $entry): void {
		$logger->debug(
			'[Application] Per-app MCP provider discovery rebuilt',
			[
				'appsProbed' => count($entry->map),
				'discovered' => array_keys(array_filter($entry->map, static fn ($key) => $key !== null)),
				'cached' => ($entry->cache !== null),
			]
		);
	}//end logRebuild()

	/**
	 * Append providers for a cached discovery map's winning candidate keys.
	 *
	 * A cached key can go stale within the TTL (e.g. an app disabled mid-window),
	 * so each key is still resolved through the normal fail-soft path and a
	 * failure simply yields no provider for that app.
	 *
	 * @param McpDiscoveryRun $run The discovery: container, logger and the providers collected so far.
	 * @param array<string, string|null> $map Cached appId => winning key|null map.
	 *
	 * @return void
	 */
	private function materialiseMcpProvidersFromMap(McpDiscoveryRun $run, array $map): void {
		foreach ($map as $appId => $key) {
			if ($key === null) {
				// Cached negative result — the app was probed on a previous
				// request and ships no MCP tool provider. Skip silently.
				continue;
			}

			$resolved = $this->tryResolveMcpProviderCandidate(
				run: $run,
				candidate: new McpProviderCandidate(appId: (string)$appId, key: $key)
			);
			if ($resolved !== null) {
				$run->add(provider: $resolved);
			}
		}
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
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return ICache|null The cache, or null when no distributed backend exists.
	 */
	private function getMcpDiscoveryCache(ContainerInterface $container, LoggerInterface $logger): ?ICache {
		try {
			$cacheFactory = $container->get(ICacheFactory::class);
			if ($cacheFactory instanceof ICacheFactory === false || $cacheFactory->isAvailable() === false) {
				return null;
			}

			return $cacheFactory->createDistributed(prefix: self::MCP_PROBE_CACHE_PREFIX);
		} catch (\Throwable $e) {
			$logger->debug('[Application] MCP discovery cache unavailable: ' . $e->getMessage());
			return null;
		}
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

		return $this->decodeMcpDiscoveryMap(blob: $blob);
	}//end readMcpDiscoveryMap()

	/**
	 * Decode a cached discovery map; a corrupt entry discards the whole blob
	 * rather than trust it.
	 *
	 * @param string $blob The cached JSON.
	 *
	 * @return array<string, string|null>|null The map, or null when corrupt.
	 */
	private function decodeMcpDiscoveryMap(string $blob): ?array {
		$data = json_decode($blob, true);
		if (is_array($data) === false) {
			return null;
		}

		$map = [];
		foreach ($data as $appId => $key) {
			if ($this->isMapKey(key: $key) === false) {
				return null;
			}

			$map[(string)$appId] = $key;
		}

		return $map;
	}//end decodeMcpDiscoveryMap()

	/**
	 * Whether a cached map value is null (no provider) or a non-empty key.
	 *
	 * @param mixed $key The cached value.
	 *
	 * @return bool
	 */
	private function isMapKey(mixed $key): bool {
		return $key === null || (is_string($key) === true && $key !== '');
	}//end isMapKey()

	/**
	 * Write the discovery map to the cache with the clamped TTL.
	 *
	 * @param McpDiscoveryRun        $run   The discovery (its container serves the TTL config read).
	 * @param McpDiscoveryCacheEntry $entry The map, the cache (null when disabled) and the key.
	 *
	 * @return void
	 */
	private function writeMcpDiscoveryMap(McpDiscoveryRun $run, McpDiscoveryCacheEntry $entry): void {
		$cache = $entry->cache;
		if ($cache === null) {
			return;
		}

		$blob = json_encode($entry->map);
		if (is_string($blob) === false) {
			return;
		}

		$cache->set(key: $entry->key, value: $blob, ttl: $this->resolveMcpProbeCacheTtl(container: $run->container));
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
		// one that differs from ucfirst($appId).
		$namespace = $this->declaredNamespace(appId: $appId, appManager: $appManager);
		if ($namespace !== '' && $namespace !== ucfirst($appId)) {
			$candidates[] = 'OCA\\' . $namespace . '\\Mcp\\' . $namespace . 'ToolProvider';
		}

		return $candidates;
	}//end buildMcpProviderCandidates()

	/**
	 * The `<namespace>` an app declares in its info.xml, or '' when none can be read.
	 *
	 * @param string $appId      The Nextcloud app id.
	 * @param mixed  $appManager The IAppManager instance.
	 *
	 * @return string The declared namespace.
	 */
	private function declaredNamespace(string $appId, $appManager): string {
		try {
			return $this->namespaceIn(infoPath: $appManager->getAppPath($appId) . '/appinfo/info.xml');
		} catch (\Throwable $e) {
			// Path-resolution failures are benign; the other two
			// candidates already cover the common case.
			return '';
		}
	}//end declaredNamespace()

	/**
	 * The `<namespace>` of an info.xml file, or ''.
	 *
	 * NC's bootstrap nulls libxml's external-entity resolver for
	 * XXE-hardening, which makes simplexml_load_file() return false on
	 * well-formed files; read first, then parse the string.
	 *
	 * @param string $infoPath The info.xml path.
	 *
	 * @return string The namespace, or '' when the file is missing, empty, malformed or declares none.
	 */
	private function namespaceIn(string $infoPath): string {
		$body = '';
		if (is_file($infoPath) === true) {
			$body = (string)file_get_contents($infoPath);
		}

		if ($body === '') {
			return '';
		}

		return $this->parseNamespace(body: $body);
	}//end namespaceIn()

	/**
	 * The `<namespace>` of an info.xml body, or '' when it is malformed or declares none.
	 *
	 * @param string $body The info.xml body.
	 *
	 * @return string The namespace.
	 */
	private function parseNamespace(string $body): string {
		$useErrors = libxml_use_internal_errors(true);
		$xml       = simplexml_load_string($body);
		libxml_use_internal_errors($useErrors);

		if ($xml === false || isset($xml->namespace) === false) {
			return '';
		}

		return (string)$xml->namespace;
	}//end parseNamespace()

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
	 * @param McpDiscoveryRun      $run       The discovery: container and logger.
	 * @param McpProviderCandidate $candidate The app id (for logging) and the candidate lookup key.
	 *
	 * @return IMcpToolProvider|null The resolved provider or null.
	 *
	 * @spec openspec/specs/chat-ai/spec.md#requirement-mcptoolsservice-provider-discovery-refactor
	 */
	protected function tryResolveMcpProviderCandidate(McpDiscoveryRun $run, McpProviderCandidate $candidate): ?IMcpToolProvider {
		try {
			return $this->resolveCandidate(run: $run, candidate: $candidate);
		} catch (\Psr\Container\NotFoundExceptionInterface $e) {
			// Alias key not registered — the expected case for every app
			// without an MCP provider, i.e. almost all of them. Deliberately
			// silent; see isMissingClass() (#308).
			return null;
		} catch (\Throwable $e) {
			// A genuine misconfiguration (the alias IS registered but its
			// factory blew up) — this one is worth hearing about.
			$run->logger->warning(
				'[Application] Resolve failed',
				['appId' => $candidate->appId, 'key' => $candidate->key, 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end tryResolveMcpProviderCandidate()

	/**
	 * Whether a candidate is a FQCN whose class does not exist.
	 *
	 * Only a FQCN that exists is tried; calling get() on a non-existent class
	 * would throw NotFoundExceptionInterface for every installed app (noisy).
	 * A miss is expected for every installed app that ships no MCP tool
	 * provider — i.e. almost all of them — and deliberately silent: it fired
	 * ~2 lines per installed app per request (#308). The single summary line
	 * of a rebuild carries the same information at 1/143rd the volume.
	 *
	 * @param string $key The candidate lookup key.
	 *
	 * @return bool
	 */
	private function isMissingClass(string $key): bool {
		return str_contains($key, '\\') === true && str_contains($key, '::') === false && class_exists($key) === false;
	}//end isMissingClass()

	/**
	 * Resolve a candidate and keep it only when it is a provider; a FQCN
	 * whose class does not exist is skipped without a lookup.
	 *
	 * @param McpDiscoveryRun      $run       The discovery: container and logger.
	 * @param McpProviderCandidate $candidate The app id (for logging) and the candidate lookup key.
	 *
	 * @return IMcpToolProvider|null The provider, or null.
	 */
	private function resolveCandidate(McpDiscoveryRun $run, McpProviderCandidate $candidate): ?IMcpToolProvider {
		$appId = $candidate->appId;
		$key   = $candidate->key;
		if ($this->isMissingClass(key: $key) === true) {
			return null;
		}

		$appProvider = $run->container->get($key);
		if ($appProvider instanceof IMcpToolProvider) {
			$run->logger->info('[Application] Discovered per-app tool provider', ['appId' => $appId, 'via' => $key, 'class' => get_class($appProvider)]);
			return $appProvider;
		}

		// Get_debug_type() returns the FQCN for objects and the type name for scalars.
		$run->logger->warning(
			'[Application] Resolved but not IMcpToolProvider',
			['appId' => $appId, 'via' => $key, 'class' => get_debug_type($appProvider)]
		);
		return null;
	}//end resolveCandidate()
}//end class
