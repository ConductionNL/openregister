<?php

/**
 * AppHost metric source tests — tableCount allowlist + missing-table-zero +
 * grouped labelMap/labelDefaults, appConfig, provider escape-hatch discovery.
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
use OCA\OpenRegister\AppHost\IMetricsProvider;
use OCA\OpenRegister\AppHost\Observability\MetricDescriptor;
use OCA\OpenRegister\AppHost\Observability\MetricSample;
use OCA\OpenRegister\AppHost\Observability\ObservabilityValidationException;
use OCA\OpenRegister\AppHost\Observability\Source\AppConfigMetricSource;
use OCA\OpenRegister\AppHost\Observability\Source\ProviderMetricSource;
use OCA\OpenRegister\AppHost\Observability\Source\TableMetricSource;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Source-level coverage for tableCount / appConfig / provider.
 */
class MetricSourceTest extends TestCase {
	private function descriptor(array $source, string $name = 'm', string $type = 'gauge'): MetricDescriptor {
		return new MetricDescriptor($name, $type, $source['kind'], $source);
	}

	// --- TableMetricSource ---------------------------------------------------

	public function testTableCountMissingTableEmitsZeroSamples(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(false);

		$source = new TableMetricSource($db, $this->createMock(LoggerInterface::class));
		$samples = $source->collect('launchpad', $this->descriptor(['kind' => 'tableCount', 'table' => 'launchpad_widgets']));

		$this->assertCount(1, $samples);
		$this->assertSame([], $samples[0]->samples);
	}

	public function testTableCountUngroupedCount(): void {
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn('42');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('func')->willReturn(new class {
			public function count($a = '*', $b = null) {
				return 'COUNT';
			}
		});
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturn($qb);

		$source = new TableMetricSource($db, $this->createMock(LoggerInterface::class));
		$samples = $source->collect('launchpad', $this->descriptor(['kind' => 'tableCount', 'table' => 'launchpad_widgets']));

		$this->assertSame(42, $samples[0]->samples[0]['value']);
	}

	public function testTableCountGroupedWithLabelMapAndDefaults(): void {
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn([
			['cnt' => 5, 'type' => 'shared'],
			['cnt' => 2, 'type' => null],
		]);

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('addSelect')->willReturnSelf();
		$qb->method('addGroupBy')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('func')->willReturn(new class {
			public function count($a = '*', $b = null) {
				return 'COUNT';
			}
		});
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturn($qb);

		$source = new TableMetricSource($db, $this->createMock(LoggerInterface::class));
		$samples = $source->collect('launchpad', $this->descriptor([
			'kind' => 'tableCount',
			'table' => 'launchpad_dashboards',
			'groupBy' => ['type'],
			'labelMap' => ['type' => 'kind'],
			'labelDefaults' => ['type' => 'personal'],
		]));

		$points = $samples[0]->samples;
		$this->assertSame(['kind' => 'shared'], $points[0]['labels']);
		$this->assertSame(5, $points[0]['value']);
		// NULL column => labelDefault, renamed key.
		$this->assertSame(['kind' => 'personal'], $points[1]['labels']);
	}

	public function testTableCountRejectsNonAllowlistedTableAtSourceLevel(): void {
		$db = $this->createMock(IDBConnection::class);
		$source = new TableMetricSource($db, $this->createMock(LoggerInterface::class));

		// Bypass the descriptor validator to prove the source RE-ENFORCES the
		// allowlist (defence in depth) — construct a descriptor directly.
		$descriptor = new MetricDescriptor('m', 'gauge', 'tableCount', ['kind' => 'tableCount', 'table' => 'oc_users; DROP']);

		$this->expectException(ObservabilityValidationException::class);
		$source->collect('app', $descriptor);
	}

	// --- AppConfigMetricSource -----------------------------------------------

	public function testAppConfigSourceReadsIntValue(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->with('docudesk', 'pdf_generations_total', 0)->willReturn(123);

		$source = new AppConfigMetricSource($appConfig);
		$samples = $source->collect('docudesk', $this->descriptor(['kind' => 'appConfig', 'key' => 'pdf_generations_total'], 'pdf_generations_total', 'counter'));

		$this->assertSame(123, $samples[0]->samples[0]['value']);
		$this->assertSame('counter', $samples[0]->type);
	}

	// --- ProviderMetricSource ------------------------------------------------

	/**
	 * A locator that hands back the given container for any app.
	 *
	 * The topology is INJECTED rather than reached for. Before this seam
	 * existed, `collect()` resolved the calling app's container through
	 * `\OC::$server` and never consulted the container these tests build — so
	 * this test read the REAL shillinq provider's metrics on any instance where
	 * shillinq was installed, and only passed where it was absent.
	 */
	private function locatorReturning(?ContainerInterface $appContainer): AppContainerLocator {
		$locator = $this->createMock(AppContainerLocator::class);
		$locator->method('find')->willReturn($appContainer);
		$locator->method('findOr')->willReturnCallback(
			static function (string $appId, ContainerInterface $fallback) use ($appContainer): ContainerInterface {
				return ($appContainer ?? $fallback);
			}
		);

		return $locator;
	}

	public function testProviderEscapeHatchDiscoveredByAlias(): void {
		$provider = new class implements IMetricsProvider {
			public function metrics(): array {
				return [MetricSample::single('bridge_state', 'gauge', 'Bridge', 1)];
			}
		};

		$alias = IMetricsProvider::class . '::shillinq';

		// The APP's own container is where a leaf registers its alias.
		$appContainer = $this->createMock(ContainerInterface::class);
		$appContainer->method('has')->willReturnMap([[$alias, true]]);
		$appContainer->method('get')->willReturnMap([[$alias, $provider]]);

		$source = new ProviderMetricSource(
			$this->createMock(ContainerInterface::class),
			$this->locatorReturning($appContainer),
			$this->createMock(LoggerInterface::class)
		);
		$samples = $source->collect('shillinq', $this->descriptor(['kind' => 'provider']));

		$this->assertCount(1, $samples);
		$this->assertSame('bridge_state', $samples[0]->name);
	}

	/**
	 * The alias is read from the APP's container, not OpenRegister's.
	 *
	 * This is the whole substance of #390: NC app containers are isolated, so a
	 * provider registered by a leaf is invisible in OpenRegister's container.
	 * Here OpenRegister's container would answer, and must not be asked.
	 */
	public function testProviderIsResolvedFromTheAppsOwnContainerNotOpenRegisters(): void {
		$provider = new class implements IMetricsProvider {
			public function metrics(): array {
				return [MetricSample::single('wrong_container', 'gauge', 'Wrong', 1)];
			}
		};

		$alias = IMetricsProvider::class . '::shillinq';

		// OpenRegister's own container CAN answer the alias...
		$ownContainer = $this->createMock(ContainerInterface::class);
		$ownContainer->method('has')->willReturnMap([[$alias, true]]);
		$ownContainer->method('get')->willReturnMap([[$alias, $provider]]);

		// ...but the app has its own container, which does not.
		$appContainer = $this->createMock(ContainerInterface::class);
		$appContainer->method('has')->willReturn(false);

		$source = new ProviderMetricSource(
			$ownContainer,
			$this->locatorReturning($appContainer),
			$this->createMock(LoggerInterface::class)
		);
		$samples = $source->collect('shillinq', $this->descriptor(['kind' => 'provider']));

		$this->assertSame(
			[],
			$samples,
			'the provider was read from OpenRegister\'s container, which never sees a leaf app\'s registrations'
		);
	}

	/**
	 * An app with NO registered container falls back rather than fataling.
	 *
	 * One app that was never bootstrapped must not take down a scrape that
	 * walks every installed app.
	 */
	public function testAnAppWithoutItsOwnContainerFallsBack(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(false);

		$source = new ProviderMetricSource(
			$container,
			$this->locatorReturning(null),
			$this->createMock(LoggerInterface::class)
		);
		$samples = $source->collect('neverbootstrapped', $this->descriptor(['kind' => 'provider']));

		$this->assertSame([], $samples);
	}

	public function testProviderAbsentYieldsNoSamples(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(false);

		$source = new ProviderMetricSource(
			$container,
			$this->locatorReturning($container),
			$this->createMock(LoggerInterface::class)
		);
		$samples = $source->collect('appwithoutprovider', $this->descriptor(['kind' => 'provider']));

		$this->assertSame([], $samples);
	}
}
