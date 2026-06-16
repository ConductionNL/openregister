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
class MetricSourceTest extends TestCase
{
    private function descriptor(array $source, string $name = 'm', string $type = 'gauge'): MetricDescriptor
    {
        return new MetricDescriptor($name, $type, $source['kind'], $source);
    }

    // --- TableMetricSource ---------------------------------------------------

    public function testTableCountMissingTableEmitsZeroSamples(): void
    {
        $db = $this->createMock(IDBConnection::class);
        $db->method('tableExists')->willReturn(false);

        $source  = new TableMetricSource($db, $this->createMock(LoggerInterface::class));
        $samples = $source->collect('launchpad', $this->descriptor(['kind' => 'tableCount', 'table' => 'launchpad_widgets']));

        $this->assertCount(1, $samples);
        $this->assertSame([], $samples[0]->samples);
    }

    public function testTableCountUngroupedCount(): void
    {
        $result = $this->createMock(IResult::class);
        $result->method('fetchOne')->willReturn('42');

        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('func')->willReturn(new class {
            public function count($a = '*', $b = null)
            {
                return 'COUNT';
            }
        });
        $qb->method('executeQuery')->willReturn($result);

        $db = $this->createMock(IDBConnection::class);
        $db->method('tableExists')->willReturn(true);
        $db->method('getQueryBuilder')->willReturn($qb);

        $source  = new TableMetricSource($db, $this->createMock(LoggerInterface::class));
        $samples = $source->collect('launchpad', $this->descriptor(['kind' => 'tableCount', 'table' => 'launchpad_widgets']));

        $this->assertSame(42, $samples[0]->samples[0]['value']);
    }

    public function testTableCountGroupedWithLabelMapAndDefaults(): void
    {
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
            public function count($a = '*', $b = null)
            {
                return 'COUNT';
            }
        });
        $qb->method('executeQuery')->willReturn($result);

        $db = $this->createMock(IDBConnection::class);
        $db->method('tableExists')->willReturn(true);
        $db->method('getQueryBuilder')->willReturn($qb);

        $source  = new TableMetricSource($db, $this->createMock(LoggerInterface::class));
        $samples = $source->collect('launchpad', $this->descriptor([
            'kind'          => 'tableCount',
            'table'         => 'launchpad_dashboards',
            'groupBy'       => ['type'],
            'labelMap'      => ['type' => 'kind'],
            'labelDefaults' => ['type' => 'personal'],
        ]));

        $points = $samples[0]->samples;
        $this->assertSame(['kind' => 'shared'], $points[0]['labels']);
        $this->assertSame(5, $points[0]['value']);
        // NULL column => labelDefault, renamed key.
        $this->assertSame(['kind' => 'personal'], $points[1]['labels']);
    }

    public function testTableCountRejectsNonAllowlistedTableAtSourceLevel(): void
    {
        $db     = $this->createMock(IDBConnection::class);
        $source = new TableMetricSource($db, $this->createMock(LoggerInterface::class));

        // Bypass the descriptor validator to prove the source RE-ENFORCES the
        // allowlist (defence in depth) — construct a descriptor directly.
        $descriptor = new MetricDescriptor('m', 'gauge', 'tableCount', ['kind' => 'tableCount', 'table' => 'oc_users; DROP']);

        $this->expectException(ObservabilityValidationException::class);
        $source->collect('app', $descriptor);
    }

    // --- AppConfigMetricSource -----------------------------------------------

    public function testAppConfigSourceReadsIntValue(): void
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueInt')->with('docudesk', 'pdf_generations_total', 0)->willReturn(123);

        $source  = new AppConfigMetricSource($appConfig);
        $samples = $source->collect('docudesk', $this->descriptor(['kind' => 'appConfig', 'key' => 'pdf_generations_total'], 'pdf_generations_total', 'counter'));

        $this->assertSame(123, $samples[0]->samples[0]['value']);
        $this->assertSame('counter', $samples[0]->type);
    }

    // --- ProviderMetricSource ------------------------------------------------

    public function testProviderEscapeHatchDiscoveredByAlias(): void
    {
        $provider = new class implements IMetricsProvider {
            public function metrics(): array
            {
                return [MetricSample::single('bridge_state', 'gauge', 'Bridge', 1)];
            }
        };

        $alias     = IMetricsProvider::class.'::shillinq';
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnMap([[$alias, true]]);
        $container->method('get')->willReturnMap([[$alias, $provider]]);

        $source  = new ProviderMetricSource($container, $this->createMock(LoggerInterface::class));
        $samples = $source->collect('shillinq', $this->descriptor(['kind' => 'provider']));

        $this->assertCount(1, $samples);
        $this->assertSame('bridge_state', $samples[0]->name);
    }

    public function testProviderAbsentYieldsNoSamples(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        $source  = new ProviderMetricSource($container, $this->createMock(LoggerInterface::class));
        $samples = $source->collect('appwithoutprovider', $this->descriptor(['kind' => 'provider']));

        $this->assertSame([], $samples);
    }
}
