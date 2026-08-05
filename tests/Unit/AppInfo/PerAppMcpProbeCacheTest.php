<?php

declare(strict_types=1);

/**
 * Application::collectPerAppMcpProviders() probe-cache tests (#308).
 *
 * The per-app MCP provider discovery loop lives in the DI factory in
 * Application, NOT in McpToolsService (every log line was merely TAGGED
 * '[McpToolsService]', which is what misled the original diagnosis on #308).
 * The factory is registered `$shared`, so it runs once per request — the
 * waste it caused was CROSS-request: on every MCP/chat turn it re-read every
 * installed app's info.xml and re-probed ~2-3 dead candidate keys per app.
 *
 * These tests exercise the discovery-map cache that fixes that, through the
 * REAL method (reflect-invoked, as {@see AttributeMcpDiscoveryTest} does for
 * the sibling chain) with counting overrides on the two protected probe seams:
 *  - a COLD run probes, resolves, and populates the cache;
 *  - a WARM run with the same app-list hash performs ZERO info.xml reads and
 *    resolves ONLY the winners — the negative results are never re-probed;
 *  - a CHANGED app list invalidates (different cache key);
 *  - an UNAVAILABLE cache falls back to the exact pre-cache behaviour;
 *  - a negative result IS cached (the entire point of the issue).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\AppInfo
 * @author   OpenRegister Team
 * @license  EUPL-1.2
 * @link     https://github.com/OpenRegister/OpenRegister
 *
 * @spec openspec/specs/chat-ai/spec.md#requirement-mcptoolsservice-provider-discovery-refactor
 */

namespace OCA\OpenRegister\Tests\Unit\AppInfo;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Mcp\IMcpToolProvider;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * A NotFoundExceptionInterface the fake container can throw, so the
 * "alias key not registered" path under test is the same branch NC's real
 * container takes (a plain RuntimeException would hit the warning branch).
 */
class ProbeNotFoundException extends RuntimeException implements NotFoundExceptionInterface
{
}//end class


/**
 * A minimal IMcpToolProvider for the one app in the fixture set that has one.
 */
class ProbeFixtureToolProvider implements IMcpToolProvider
{
    /**
     * @return string The owning app id.
     */
    public function getAppId(): string
    {
        return 'pipelinq';

    }//end getAppId()

    /**
     * @return array<int, array<string, mixed>> The tool descriptors.
     */
    public function getTools(): array
    {
        return [];

    }//end getTools()

    /**
     * @param string               $toolId    The tool id.
     * @param array<string, mixed> $arguments The tool arguments.
     *
     * @return array<string, mixed> The tool result.
     */
    public function invokeTool(string $toolId, array $arguments): array
    {
        return [];

    }//end invokeTool()
}//end class


/**
 * An array-backed ICache so a COLD write is visible to a subsequent WARM read
 * within a test — i.e. the cross-request behaviour the fix actually targets.
 */
class ArrayProbeCache implements ICache
{

    /**
     * The backing store.
     *
     * @var array<string, mixed>
     */
    public array $store = [];

    /**
     * Every TTL passed to set(), in order — lets tests assert the clamp.
     *
     * @var list<int>
     */
    public array $ttls = [];

    /**
     * @param string $key The cache key.
     *
     * @return mixed The cached value, or null on miss.
     */
    public function get($key)
    {
        return ($this->store[$key] ?? null);

    }//end get()

    /**
     * @param string $key   The cache key.
     * @param mixed  $value The value.
     * @param int    $ttl   The TTL in seconds.
     *
     * @return bool Always true.
     */
    public function set($key, $value, $ttl=0)
    {
        $this->store[$key] = $value;
        $this->ttls[]      = $ttl;
        return true;

    }//end set()

    /**
     * @param string $key The cache key.
     *
     * @return bool Whether the key exists.
     */
    public function hasKey($key)
    {
        return array_key_exists($key, $this->store);

    }//end hasKey()

    /**
     * @param string $key The cache key.
     *
     * @return bool Always true.
     */
    public function remove($key)
    {
        unset($this->store[$key]);
        return true;

    }//end remove()

    /**
     * @param string $prefix The key prefix.
     *
     * @return bool Always true.
     */
    public function clear($prefix='')
    {
        $this->store = [];
        return true;

    }//end clear()

    /**
     * @return bool Always true.
     */
    public static function isAvailable(): bool
    {
        return true;

    }//end isAvailable()
}//end class


/**
 * Application subclass that COUNTS the two protected probe seams so tests can
 * assert that a warm request skips the expensive work rather than merely
 * producing the same result.
 */
class CountingProbeApplication extends Application
{

    /**
     * Every appId passed to buildMcpProviderCandidates(), in order. This seam
     * is the info.xml read + simplexml parse — the real hot spot.
     *
     * @var list<string>
     */
    public array $candidateBuilds = [];

    /**
     * Every [appId, key] pair passed to tryResolveMcpProviderCandidate().
     *
     * @var list<array{0: string, 1: string}>
     */
    public array $resolveAttempts = [];

    /**
     * @param string $appId      The app id.
     * @param mixed  $appManager The IAppManager fake.
     *
     * @return string[] The candidate keys.
     */
    protected function buildMcpProviderCandidates(string $appId, $appManager): array
    {
        $this->candidateBuilds[] = $appId;
        return parent::buildMcpProviderCandidates($appId, $appManager);

    }//end buildMcpProviderCandidates()

    /**
     * @param ContainerInterface $container The DI container.
     * @param LoggerInterface    $logger    PSR logger.
     * @param string             $appId     The app id.
     * @param string             $key       The candidate key.
     *
     * @return IMcpToolProvider|null The resolved provider, or null.
     */
    protected function tryResolveMcpProviderCandidate(
        ContainerInterface $container,
        \Psr\Log\LoggerInterface $logger,
        string $appId,
        string $key
    ): ?IMcpToolProvider {
        $this->resolveAttempts[] = [$appId, $key];
        return parent::tryResolveMcpProviderCandidate($container, $logger, $appId, $key);

    }//end tryResolveMcpProviderCandidate()
}//end class


/**
 * Probe-cache tests for the per-app MCP discovery step.
 */
class PerAppMcpProbeCacheTest extends TestCase
{

    /**
     * The alias key the fixture app's provider is bound to.
     *
     * @var string
     */
    private const PIPELINQ_ALIAS = 'OCA\\OpenRegister\\Mcp\\IMcpToolProvider::pipelinq';

    /**
     * Installed apps for the default fixture: one app WITH a provider and two
     * stock apps without — the 143-log-lines case in miniature.
     *
     * @var list<string>
     */
    private const DEFAULT_APPS = ['pipelinq', 'activity', 'systemtags'];

    /**
     * An IAppManager fake exposing only the two methods discovery uses.
     *
     * @param list<string> $appIds The installed app ids.
     *
     * @return object The fake app manager.
     */
    private function appManager(array $appIds): object
    {
        return new class ($appIds) {
            /**
             * @param list<string> $appIds The installed app ids.
             */
            public function __construct(private readonly array $appIds)
            {
            }//end __construct()

            /**
             * @return list<string> The installed app ids.
             */
            public function getInstalledApps(): array
            {
                return $this->appIds;
            }//end getInstalledApps()

            /**
             * A path that does not exist — buildMcpProviderCandidates() then
             * skips the third (info.xml-derived) candidate, exactly as it does
             * for a real app without a <namespace> declaration.
             *
             * @param string $appId The app id.
             *
             * @return string The app path.
             */
            public function getAppPath(string $appId): string
            {
                return '/nonexistent/apps/'.$appId;
            }//end getAppPath()
        };

    }//end appManager()

    /**
     * A ContainerInterface fake bound to the given map, throwing a proper
     * NotFoundExceptionInterface (as NC's container does) for unbound keys.
     *
     * @param array<string, mixed> $bindings Key => value map.
     *
     * @return ContainerInterface The fake container.
     */
    private function container(array $bindings): ContainerInterface
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(
            static fn (string $id): bool => array_key_exists($id, $bindings)
        );
        $container->method('get')->willReturnCallback(
            static function (string $id) use ($bindings) {
                if (array_key_exists($id, $bindings) === true) {
                    return $bindings[$id];
                }

                throw new ProbeNotFoundException('No binding for '.$id);
            }
        );

        return $container;

    }//end container()

    /**
     * Build the standard container binding map.
     *
     * @param list<string>       $appIds       The installed app ids.
     * @param ICacheFactory|null $cacheFactory The cache factory, or null to omit the binding.
     *
     * @return array<string, mixed> The binding map.
     */
    private function bindings(array $appIds, ?ICacheFactory $cacheFactory): array
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueInt')->willReturnCallback(
            static fn (string $app, string $key, int $default=0): int => $default
        );

        $bindings = [
            'OCP\App\IAppManager' => $this->appManager($appIds),
            IAppConfig::class     => $appConfig,
            self::PIPELINQ_ALIAS  => new ProbeFixtureToolProvider(),
        ];

        if ($cacheFactory !== null) {
            $bindings[ICacheFactory::class] = $cacheFactory;
        }

        return $bindings;

    }//end bindings()

    /**
     * An ICacheFactory returning the given cache, or reporting unavailable.
     *
     * @param ICache|null $cache The cache to hand out, or null for "unavailable".
     *
     * @return ICacheFactory The fake factory.
     */
    private function cacheFactory(?ICache $cache): ICacheFactory
    {
        $factory = $this->createMock(ICacheFactory::class);
        $factory->method('isAvailable')->willReturn($cache !== null);
        if ($cache !== null) {
            $factory->method('createDistributed')->willReturn($cache);
        }

        return $factory;

    }//end cacheFactory()

    /**
     * Reflect-invoke the REAL private collectPerAppMcpProviders() on a
     * counting subclass.
     *
     * @param ContainerInterface      $container The DI container.
     * @param array<IMcpToolProvider> $providers Providers collected so far (mutated by reference).
     *
     * @return CountingProbeApplication The app instance, for asserting counts.
     */
    private function invokeCollect(ContainerInterface $container, array &$providers): CountingProbeApplication
    {
        // newInstanceWithoutConstructor() — Application::__construct() reaches
        // \OC::$server, which is out of scope for this local unit harness; the
        // method under test reads only its parameters and its own seams.
        $app    = (new ReflectionClass(CountingProbeApplication::class))->newInstanceWithoutConstructor();
        $logger = $this->createMock(LoggerInterface::class);

        $method = new ReflectionMethod(Application::class, 'collectPerAppMcpProviders');
        $method->setAccessible(true);
        $method->invokeArgs($app, [$container, $logger, &$providers]);

        return $app;

    }//end invokeCollect()

    // ── (a) cold run resolves + populates the cache ───────────────────
    public function testColdRunProbesEveryAppResolvesProviderAndPopulatesCache(): void
    {
        $cache     = new ArrayProbeCache();
        $container = $this->container($this->bindings(self::DEFAULT_APPS, $this->cacheFactory($cache)));

        $providers = [];
        $app       = $this->invokeCollect($container, $providers);

        // The one app with a provider is discovered.
        $this->assertCount(1, $providers);
        $this->assertInstanceOf(ProbeFixtureToolProvider::class, $providers[0]);

        // A cold run probes EVERY installed app.
        $this->assertSame(self::DEFAULT_APPS, $app->candidateBuilds);

        // And it wrote exactly one map blob.
        $this->assertCount(1, $cache->store);

    }//end testColdRunProbesEveryAppResolvesProviderAndPopulatesCache()

    // ── (e) the negative result is cached — the point of #308 ─────────
    public function testNegativeResultsAreCachedAsExplicitNulls(): void
    {
        $cache     = new ArrayProbeCache();
        $container = $this->container($this->bindings(self::DEFAULT_APPS, $this->cacheFactory($cache)));

        $providers = [];
        $this->invokeCollect($container, $providers);

        $map = json_decode((string) array_values($cache->store)[0], true);

        // Every installed app is represented — the misses explicitly, so they
        // are never re-probed while the entry lives.
        $this->assertSame(self::DEFAULT_APPS, array_keys($map));
        $this->assertSame(self::PIPELINQ_ALIAS, $map['pipelinq']);
        $this->assertNull($map['activity']);
        $this->assertNull($map['systemtags']);

    }//end testNegativeResultsAreCachedAsExplicitNulls()

    public function testCachedMapIsWrittenWithTheClampedTtl(): void
    {
        $cache     = new ArrayProbeCache();
        $container = $this->container($this->bindings(self::DEFAULT_APPS, $this->cacheFactory($cache)));

        $providers = [];
        $this->invokeCollect($container, $providers);

        // IAppConfig fake returns the default; the clamp must keep it intact.
        $this->assertSame([60], $cache->ttls);

    }//end testCachedMapIsWrittenWithTheClampedTtl()

    // ── (b) warm run does NOT re-probe ────────────────────────────────
    public function testWarmRunSkipsAllProbingAndResolvesOnlyWinners(): void
    {
        $cache = new ArrayProbeCache();

        // COLD.
        $coldProviders = [];
        $this->invokeCollect(
            $this->container($this->bindings(self::DEFAULT_APPS, $this->cacheFactory($cache))),
            $coldProviders
        );

        // WARM — same app list, same cache.
        $warmProviders = [];
        $warmApp       = $this->invokeCollect(
            $this->container($this->bindings(self::DEFAULT_APPS, $this->cacheFactory($cache))),
            $warmProviders
        );

        // Same outcome...
        $this->assertCount(1, $warmProviders);
        $this->assertInstanceOf(ProbeFixtureToolProvider::class, $warmProviders[0]);

        // ...but ZERO info.xml reads / candidate builds.
        $this->assertSame([], $warmApp->candidateBuilds);

        // And exactly ONE resolve — the known winner. The two stock apps are
        // not touched at all: no class_exists(), no throwing container lookup.
        $this->assertSame([['pipelinq', self::PIPELINQ_ALIAS]], $warmApp->resolveAttempts);

    }//end testWarmRunSkipsAllProbingAndResolvesOnlyWinners()

    public function testWarmRunDoesNotRewriteTheCache(): void
    {
        $cache = new ArrayProbeCache();

        $coldProviders = [];
        $this->invokeCollect(
            $this->container($this->bindings(self::DEFAULT_APPS, $this->cacheFactory($cache))),
            $coldProviders
        );
        $this->assertCount(1, $cache->ttls);

        $warmProviders = [];
        $this->invokeCollect(
            $this->container($this->bindings(self::DEFAULT_APPS, $this->cacheFactory($cache))),
            $warmProviders
        );

        // Still one write — the warm path never touches set().
        $this->assertCount(1, $cache->ttls);

    }//end testWarmRunDoesNotRewriteTheCache()

    // ── (c) a changed app list invalidates ────────────────────────────
    public function testChangedAppListRebuildsUnderANewCacheKey(): void
    {
        $cache = new ArrayProbeCache();

        $coldProviders = [];
        $this->invokeCollect(
            $this->container($this->bindings(self::DEFAULT_APPS, $this->cacheFactory($cache))),
            $coldProviders
        );

        // A newly installed app changes the hash → full re-probe.
        $changed      = [...self::DEFAULT_APPS, 'newlyinstalled'];
        $newProviders = [];
        $newApp       = $this->invokeCollect(
            $this->container($this->bindings($changed, $this->cacheFactory($cache))),
            $newProviders
        );

        $this->assertSame($changed, $newApp->candidateBuilds);

        // Two distinct keys now coexist; the old one simply ages out on TTL.
        $this->assertCount(2, $cache->store);

    }//end testChangedAppListRebuildsUnderANewCacheKey()

    public function testAppListOrderDoesNotInvalidateTheCache(): void
    {
        $cache = new ArrayProbeCache();

        $coldProviders = [];
        $this->invokeCollect(
            $this->container($this->bindings(self::DEFAULT_APPS, $this->cacheFactory($cache))),
            $coldProviders
        );

        // IAppManager returning the same set in a different order must NOT
        // count as a change — the key hashes a sorted copy.
        $reordered     = array_reverse(self::DEFAULT_APPS);
        $warmProviders = [];
        $warmApp       = $this->invokeCollect(
            $this->container($this->bindings($reordered, $this->cacheFactory($cache))),
            $warmProviders
        );

        $this->assertSame([], $warmApp->candidateBuilds);
        $this->assertCount(1, $cache->store);

    }//end testAppListOrderDoesNotInvalidateTheCache()

    // ── (d) cache unavailable → pre-cache behaviour ───────────────────
    public function testUnavailableCacheFallsBackToProbingEveryRequest(): void
    {
        $factory = $this->cacheFactory(null);

        $firstProviders = [];
        $firstApp       = $this->invokeCollect(
            $this->container($this->bindings(self::DEFAULT_APPS, $factory)),
            $firstProviders
        );

        $secondProviders = [];
        $secondApp       = $this->invokeCollect(
            $this->container($this->bindings(self::DEFAULT_APPS, $factory)),
            $secondProviders
        );

        // Both requests probe everything and both produce the provider —
        // byte-for-byte the pre-cache behaviour (fail open).
        $this->assertSame(self::DEFAULT_APPS, $firstApp->candidateBuilds);
        $this->assertSame(self::DEFAULT_APPS, $secondApp->candidateBuilds);
        $this->assertCount(1, $firstProviders);
        $this->assertCount(1, $secondProviders);

    }//end testUnavailableCacheFallsBackToProbingEveryRequest()

    public function testMissingCacheFactoryBindingFallsBackToProbing(): void
    {
        // No ICacheFactory bound at all — getMcpDiscoveryCache() must swallow
        // the container miss and disable the cache rather than blow up.
        $providers = [];
        $app       = $this->invokeCollect(
            $this->container($this->bindings(self::DEFAULT_APPS, null)),
            $providers
        );

        $this->assertSame(self::DEFAULT_APPS, $app->candidateBuilds);
        $this->assertCount(1, $providers);

    }//end testMissingCacheFactoryBindingFallsBackToProbing()

    // ── robustness ───────────────────────────────────────────────────
    public function testCorruptCacheBlobIsDiscardedAndRebuilt(): void
    {
        $cache     = new ArrayProbeCache();
        $container = $this->container($this->bindings(self::DEFAULT_APPS, $this->cacheFactory($cache)));

        $coldProviders = [];
        $this->invokeCollect($container, $coldProviders);

        // Corrupt the stored blob.
        $key = array_keys($cache->store)[0];
        $cache->store[$key] = 'not-json';

        $providers = [];
        $app       = $this->invokeCollect(
            $this->container($this->bindings(self::DEFAULT_APPS, $this->cacheFactory($cache))),
            $providers
        );

        // Rebuilt from scratch rather than trusted.
        $this->assertSame(self::DEFAULT_APPS, $app->candidateBuilds);
        $this->assertCount(1, $providers);

    }//end testCorruptCacheBlobIsDiscardedAndRebuilt()

    public function testStaleCachedKeyThatNoLongerResolvesYieldsNoProvider(): void
    {
        $cache = new ArrayProbeCache();

        $coldProviders = [];
        $this->invokeCollect(
            $this->container($this->bindings(self::DEFAULT_APPS, $this->cacheFactory($cache))),
            $coldProviders
        );

        // WARM, but the app's alias has since disappeared (app disabled
        // mid-TTL). The cached key must fail soft, not fatal.
        $bindings = $this->bindings(self::DEFAULT_APPS, $this->cacheFactory($cache));
        unset($bindings[self::PIPELINQ_ALIAS]);

        $providers = [];
        $this->invokeCollect($this->container($bindings), $providers);

        $this->assertSame([], $providers);

    }//end testStaleCachedKeyThatNoLongerResolvesYieldsNoProvider()

    public function testEnumerationFailureIsFailSoftAndLeavesProvidersUntouched(): void
    {
        // No IAppManager binding → the whole method must fail soft.
        $container = $this->container([]);

        $sentinel  = new ProbeFixtureToolProvider();
        $providers = [$sentinel];
        $this->invokeCollect($container, $providers);

        $this->assertSame([$sentinel], $providers);

    }//end testEnumerationFailureIsFailSoftAndLeavesProvidersUntouched()
}//end class
