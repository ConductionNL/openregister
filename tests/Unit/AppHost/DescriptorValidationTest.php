<?php
/**
 * AppHost descriptor validation tests.
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

use OCA\OpenRegister\AppHost\Observability\HealthCheckDescriptor;
use OCA\OpenRegister\AppHost\Observability\MetricDescriptor;
use OCA\OpenRegister\AppHost\Observability\ObservabilityManifest;
use OCA\OpenRegister\AppHost\Observability\ObservabilityValidationException;
use PHPUnit\Framework\TestCase;

/**
 * Covers the closed type/kind/operator sets and the tableCount allowlist.
 */
class DescriptorValidationTest extends TestCase
{
    public function testValidHealthCheckTypes(): void
    {
        foreach (HealthCheckDescriptor::TYPES as $type) {
            $raw = ['id' => $type, 'type' => $type];
            if ($type === 'appEnabled') {
                $raw['app'] = 'openregister';
            }

            if ($type === 'appConfig') {
                $raw['key'] = 'token_set';
            }

            $descriptor = HealthCheckDescriptor::fromArray($raw);
            $this->assertSame($type, $descriptor->type);
            $this->assertSame('critical', $descriptor->severity);
        }
    }

    public function testUnknownHealthCheckTypeRejected(): void
    {
        $this->expectException(ObservabilityValidationException::class);
        HealthCheckDescriptor::fromArray(['type' => 'pingExternal']);
    }

    public function testDegradedSeverityParsed(): void
    {
        $d = HealthCheckDescriptor::fromArray(['type' => 'filesystem', 'severity' => 'degraded']);
        $this->assertSame('degraded', $d->severity);
    }

    public function testInvalidSeverityRejected(): void
    {
        $this->expectException(ObservabilityValidationException::class);
        HealthCheckDescriptor::fromArray(['type' => 'database', 'severity' => 'fatal']);
    }

    public function testAppConfigRequiresKey(): void
    {
        $this->expectException(ObservabilityValidationException::class);
        HealthCheckDescriptor::fromArray(['type' => 'appConfig']);
    }

    public function testValidMetricKinds(): void
    {
        $cases = [
            ['name' => 'a', 'source' => ['kind' => 'objectCount', 'schema' => 'zaak']],
            ['name' => 'b', 'source' => ['kind' => 'objectSum', 'schema' => 'lead', 'field' => 'value']],
            ['name' => 'c', 'source' => ['kind' => 'tableCount', 'table' => 'launchpad_widgets']],
            ['name' => 'd', 'source' => ['kind' => 'appConfig', 'key' => 'pdf_total']],
            ['name' => 'e', 'source' => ['kind' => 'provider']],
        ];
        foreach ($cases as $raw) {
            $d = MetricDescriptor::fromArray($raw);
            $this->assertContains($d->kind, MetricDescriptor::KINDS);
        }
    }

    public function testUnknownMetricKindRejected(): void
    {
        $this->expectException(ObservabilityValidationException::class);
        MetricDescriptor::fromArray(['name' => 'x', 'source' => ['kind' => 'rawSql', 'query' => 'SELECT 1']]);
    }

    public function testObjectSumRequiresField(): void
    {
        $this->expectException(ObservabilityValidationException::class);
        MetricDescriptor::fromArray(['name' => 'x', 'source' => ['kind' => 'objectSum', 'schema' => 'lead']]);
    }

    public function testTableCountAllowlistAcceptsSafeName(): void
    {
        $d = MetricDescriptor::fromArray(['name' => 'w', 'source' => ['kind' => 'tableCount', 'table' => 'launchpad_widget_placements']]);
        $this->assertSame('tableCount', $d->kind);
        $this->assertSame('launchpad_widget_placements', $d->source['table']);
    }

    /**
     * @dataProvider unsafeTableNames
     */
    public function testTableCountAllowlistRejectsUnsafeName(string $table): void
    {
        $this->expectException(ObservabilityValidationException::class);
        MetricDescriptor::fromArray(['name' => 'w', 'source' => ['kind' => 'tableCount', 'table' => $table]]);
    }

    public static function unsafeTableNames(): array
    {
        return [
            'sql injection'   => ['oc_users; DROP TABLE oc_users'],
            'space'           => ['oc users'],
            'uppercase'       => ['OC_Users'],
            'dash'            => ['oc-users'],
            'subselect'       => ['(select 1)'],
            'quote'           => ["oc_users'"],
            'empty'           => [''],
        ];
    }

    public function testFilterOperatorAllowlist(): void
    {
        foreach (MetricDescriptor::FILTER_OPERATORS as $op) {
            $d = MetricDescriptor::fromArray([
                'name'   => 'm',
                'source' => ['kind' => 'objectCount', 'schema' => 'zaak', 'filter' => ['deadline' => [$op => 'now']]],
            ]);
            $this->assertSame('objectCount', $d->kind);
        }
    }

    public function testUnknownFilterOperatorRejected(): void
    {
        $this->expectException(ObservabilityValidationException::class);
        MetricDescriptor::fromArray([
            'name'   => 'm',
            'source' => ['kind' => 'objectCount', 'schema' => 'zaak', 'filter' => ['deadline' => ['regex' => '.*']]],
        ]);
    }

    public function testInvalidDescriptorFallsBackToImplicitOnly(): void
    {
        // Manifest with one bad metric -> diagnostic recorded, metric dropped,
        // implicit info/up still serve (no metrics declared survive).
        $manifest = ObservabilityManifest::fromManifest('myapp', [
            'observability' => [
                'metrics' => [
                    ['name' => 'bad', 'source' => ['kind' => 'rawSql']],
                ],
            ],
        ]);

        $this->assertNotEmpty($manifest->diagnostics);
        $this->assertSame([], $manifest->metrics);
    }
}
