<?php
/**
 * AppHost HealthCheckExecutor tests — each primitive + severity×policy matrix
 * + IHealthCheckProvider escape-hatch discovery.
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

use OCA\OpenRegister\AppHost\AppContainerLocator;
use OCA\OpenRegister\AppHost\IHealthCheckProvider;
use OCA\OpenRegister\AppHost\Observability\HealthCheckDescriptor;
use OCA\OpenRegister\AppHost\Observability\HealthCheckExecutor;
use OCA\OpenRegister\AppHost\Observability\ObservabilityManifest;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\DB\IResult;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Exercises the five primitives and status/HTTP resolution.
 */
class HealthCheckExecutorTest extends TestCase
{
    private IDBConnection $db;
    private ITempManager $tempManager;
    private IAppManager $appManager;
    private IAppConfig $appConfig;
    private ContainerInterface $container;
    private HealthCheckExecutor $executor;

    protected function setUp(): void
    {
        $this->db          = $this->createMock(IDBConnection::class);
        $this->tempManager = $this->createMock(ITempManager::class);
        $this->appManager  = $this->createMock(IAppManager::class);
        $this->appConfig   = $this->createMock(IAppConfig::class);
        $this->container   = $this->createMock(ContainerInterface::class);

        $result = $this->createMock(IResult::class);
        $this->db->method('executeQuery')->willReturn($result);
        $this->tempManager->method('getTemporaryFile')->willReturnCallback(fn () => tempnam(sys_get_temp_dir(), 'tst'));

        $this->executor = new HealthCheckExecutor(
            $this->db,
            $this->tempManager,
            $this->appManager,
            $this->appConfig,
            $this->container,
            // No app-specific container, so the executor falls back to the one
            // these tests build — the topology is stated here rather than read
            // from whichever apps the developer has installed.
            $this->locatorReturning(null),
            $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * A locator that hands back the given container for any app.
     *
     * @param ContainerInterface|null $appContainer The app's own container, or null when it has none.
     */
    private function locatorReturning(?ContainerInterface $appContainer): AppContainerLocator
    {
        $locator = $this->createMock(AppContainerLocator::class);
        $locator->method('find')->willReturn($appContainer);
        $locator->method('findOr')->willReturnCallback(
            static function (string $appId, ContainerInterface $fallback) use ($appContainer): ContainerInterface {
                return ($appContainer ?? $fallback);
            }
        );

        return $locator;
    }

    private function manifest(array $checks, string $policy = ObservabilityManifest::POLICY_ADR006): ObservabilityManifest
    {
        return new ObservabilityManifest('myapp', $checks, [], $policy);
    }

    public function testDatabaseCheckPasses(): void
    {
        $r = $this->executor->execute($this->manifest([new HealthCheckDescriptor('db', 'database', 'critical')]));
        $this->assertSame('ok', $r->status);
        $this->assertSame('ok', $r->checks['db']);
        $this->assertSame(Http::STATUS_OK, $r->httpStatusCode);
    }

    public function testFilesystemCheckPasses(): void
    {
        $r = $this->executor->execute($this->manifest([new HealthCheckDescriptor('fs', 'filesystem', 'degraded')]));
        $this->assertSame('ok', $r->checks['fs']);
    }

    public function testAppEnabledCheckPassesAndFails(): void
    {
        $this->appManager->method('isInstalled')->willReturnMap([['openregister', true], ['ghost', false]]);

        $ok = $this->executor->execute($this->manifest([new HealthCheckDescriptor('or', 'appEnabled', 'critical', 'openregister')]));
        $this->assertSame('ok', $ok->checks['or']);

        $fail = $this->executor->execute($this->manifest([new HealthCheckDescriptor('g', 'appEnabled', 'critical', 'ghost')]));
        $this->assertStringStartsWith('failed', $fail->checks['g']);
        $this->assertSame('error', $fail->status);
    }

    public function testAppConfigPresentAndNonEmpty(): void
    {
        $this->appConfig->method('hasKey')->willReturn(true);
        $this->appConfig->method('getValueString')->willReturn('value');

        $r = $this->executor->execute($this->manifest([new HealthCheckDescriptor('c', 'appConfig', 'degraded', null, 'token_set', 'nonEmpty')]));
        $this->assertSame('ok', $r->checks['c']);
    }

    public function testAppConfigEmptyFailsNonEmptyAssert(): void
    {
        $this->appConfig->method('hasKey')->willReturn(true);
        $this->appConfig->method('getValueString')->willReturn('');

        $r = $this->executor->execute($this->manifest([new HealthCheckDescriptor('c', 'appConfig', 'degraded', null, 'token_set', 'nonEmpty')]));
        $this->assertStringStartsWith('failed', $r->checks['c']);
        $this->assertSame('degraded', $r->status);
    }

    public function testOrAvailableCheck(): void
    {
        $this->container->method('get')->willReturn(new \stdClass());
        $r = $this->executor->execute($this->manifest([new HealthCheckDescriptor('or', 'orAvailable', 'critical')]));
        $this->assertSame('ok', $r->checks['or']);
    }

    public function testCriticalFailureUnderAdr006Yields503(): void
    {
        $this->appManager->method('isInstalled')->willReturn(false);
        $r = $this->executor->execute($this->manifest(
            [new HealthCheckDescriptor('or', 'appEnabled', 'critical', 'ghost')],
            ObservabilityManifest::POLICY_ADR006
        ));
        $this->assertSame('error', $r->status);
        $this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $r->httpStatusCode);
    }

    public function testDegradedFailureUnderAlways200Yields200(): void
    {
        $r = $this->executor->execute($this->manifest(
            [new HealthCheckDescriptor('fs', 'filesystem', 'degraded')],
            ObservabilityManifest::POLICY_ALWAYS200
        ));
        // Make filesystem fail by returning false from temp manager.
        $this->assertSame(Http::STATUS_OK, $r->httpStatusCode);
    }

    public function testCriticalFailureUnderAlways200StillReturns200(): void
    {
        $this->appManager->method('isInstalled')->willReturn(false);
        $r = $this->executor->execute($this->manifest(
            [new HealthCheckDescriptor('or', 'appEnabled', 'critical', 'ghost')],
            ObservabilityManifest::POLICY_ALWAYS200
        ));
        $this->assertSame('error', $r->status);
        $this->assertSame(Http::STATUS_OK, $r->httpStatusCode);
    }

    public function testHealthCheckProviderEscapeHatchMerged(): void
    {
        $provider = new class implements IHealthCheckProvider {
            public function check(): array
            {
                return ['queue' => ['ok' => false, 'severity' => 'degraded', 'message' => 'backlog']];
            }
        };

        $alias = IHealthCheckProvider::class.'::myapp';
        $this->container->method('has')->willReturnMap([[$alias, true]]);
        $this->container->method('get')->willReturnMap([[$alias, $provider]]);

        $r = $this->executor->execute($this->manifest([new HealthCheckDescriptor('db', 'database', 'critical')]));
        $this->assertArrayHasKey('queue', $r->checks);
        $this->assertStringStartsWith('failed', $r->checks['queue']);
        $this->assertSame('degraded', $r->status);
    }

    public function testExceptionMessageNeverLeaks(): void
    {
        $this->db->method('executeQuery')->willThrowException(new \RuntimeException('SECRET CONNECTION STRING root:hunter2@db'));
        $r = $this->executor->execute($this->manifest([new HealthCheckDescriptor('db', 'database', 'critical')]));
        $this->assertStringStartsWith('failed', $r->checks['db']);
        $this->assertStringNotContainsString('hunter2', $r->checks['db']);
        $this->assertStringNotContainsString('SECRET', $r->checks['db']);
    }
}
