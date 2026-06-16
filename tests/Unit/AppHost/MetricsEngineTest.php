<?php
/**
 * AppHost MetricsEngine tests — implicit info/up, cacheTtl memoisation,
 * app-without-observability-block unaffected.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\Observability\ManifestLoader;
use OCA\OpenRegister\AppHost\Observability\MetricDescriptor;
use OCA\OpenRegister\AppHost\Observability\MetricSample;
use OCA\OpenRegister\AppHost\Observability\MetricsEngine;
use OCA\OpenRegister\AppHost\Observability\ObservabilityManifest;
use OCA\OpenRegister\AppHost\Observability\PrometheusRenderer;
use OCA\OpenRegister\AppHost\Observability\Source\AppConfigMetricSource;
use OCA\OpenRegister\AppHost\Observability\Source\ObjectMetricSource;
use OCA\OpenRegister\AppHost\Observability\Source\ProviderMetricSource;
use OCA\OpenRegister\AppHost\Observability\Source\TableMetricSource;
use OCP\App\IAppManager;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Engine-level coverage.
 */
class MetricsEngineTest extends TestCase
{
    private ObjectMetricSource $objectSource;
    private TableMetricSource $tableSource;
    private AppConfigMetricSource $appConfigSource;
    private ProviderMetricSource $providerSource;
    private ManifestLoader $manifestLoader;
    private ICacheFactory $cacheFactory;

    protected function setUp(): void
    {
        $this->objectSource    = $this->createMock(ObjectMetricSource::class);
        $this->tableSource     = $this->createMock(TableMetricSource::class);
        $this->appConfigSource = $this->createMock(AppConfigMetricSource::class);
        $this->providerSource  = $this->createMock(ProviderMetricSource::class);
        $this->manifestLoader  = $this->createMock(ManifestLoader::class);
        $this->cacheFactory    = $this->createMock(ICacheFactory::class);

        $this->manifestLoader->method('appVersion')->willReturn('1.2.3');
    }

    private function engine(): MetricsEngine
    {
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueString')->willReturn('33.0.0');

        return new MetricsEngine(
            $this->objectSource,
            $this->tableSource,
            $this->appConfigSource,
            $this->providerSource,
            new PrometheusRenderer(),
            $this->manifestLoader,
            $this->cacheFactory,
            $this->createMock(IAppManager::class),
            $config,
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testImplicitInfoAndUpAlwaysEmitted(): void
    {
        $this->cacheFactory->method('isAvailable')->willReturn(false);
        $manifest = new ObservabilityManifest('myapp', [], []);

        $out = $this->engine()->render($manifest);

        $this->assertStringContainsString('# TYPE myapp_info gauge', $out);
        $this->assertStringContainsString('version="1.2.3"', $out);
        $this->assertStringContainsString('nextcloud_version="33.0.0"', $out);
        $this->assertStringContainsString('myapp_up 1', $out);
    }

    public function testAppWithoutBlockServesImplicitOnly(): void
    {
        $this->cacheFactory->method('isAvailable')->willReturn(false);
        // defaults() => no metrics.
        $manifest = ObservabilityManifest::defaults('myapp', []);

        $out = $this->engine()->render($manifest);
        $this->assertStringContainsString('myapp_info', $out);
        $this->assertStringContainsString('myapp_up', $out);
        // No declarative metric families beyond implicit.
        $this->assertSame(2, substr_count($out, '# TYPE'));
    }

    public function testDeclarativeMetricRouted(): void
    {
        $this->cacheFactory->method('isAvailable')->willReturn(false);
        $descriptor = new MetricDescriptor('cases_total', 'gauge', 'objectCount', ['kind' => 'objectCount', 'schema' => 'zaak']);

        $this->objectSource->expects($this->once())
            ->method('collect')
            ->willReturn([MetricSample::single('cases_total', 'gauge', 'Cases', 9)]);

        $manifest = new ObservabilityManifest('procest', [], [$descriptor]);
        $out      = $this->engine()->render($manifest);
        $this->assertStringContainsString('procest_cases_total 9', $out);
    }

    public function testCacheTtlMemoisesSourceCall(): void
    {
        // Concrete in-memory cache fake — PHPUnit-callback mocks are unreliable
        // for stateful get/set, so use a real implementation that proves the
        // engine memoises a source call across renders within the TTL.
        $cache = new class implements ICache {
            /** @var array<string, mixed> */
            public array $store = [];

            public function get($key)
            {
                return $this->store[$key] ?? null;
            }

            public function set($key, $value, $ttl = 0)
            {
                $this->store[$key] = $value;
                return true;
            }

            public function hasKey($key)
            {
                return isset($this->store[$key]);
            }

            public function remove($key)
            {
                unset($this->store[$key]);
                return true;
            }

            public function clear($prefix = '')
            {
                $this->store = [];
                return true;
            }

            public static function isAvailable(): bool
            {
                return true;
            }
        };

        $this->cacheFactory->method('isAvailable')->willReturn(true);
        $this->cacheFactory->method('createDistributed')->willReturn($cache);

        $descriptor = new MetricDescriptor('cached', 'gauge', 'tableCount', ['kind' => 'tableCount', 'table' => 't'], null, 60);

        // Source must be hit exactly once across two renders within the TTL.
        $this->tableSource->expects($this->once())
            ->method('collect')
            ->willReturn([MetricSample::single('cached', 'gauge', 'C', 1)]);

        $manifest = new ObservabilityManifest('app', [], [$descriptor]);
        $engine   = $this->engine();
        $first    = $engine->render($manifest);
        $second   = $engine->render($manifest);

        $this->assertStringContainsString('app_cached 1', $first);
        $this->assertStringContainsString('app_cached 1', $second);
    }
}
