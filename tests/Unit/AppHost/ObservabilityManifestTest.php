<?php
/**
 * AppHost observability manifest parsing + defaults tests.
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

use OCA\OpenRegister\AppHost\Observability\ObservabilityManifest;
use PHPUnit\Framework\TestCase;

/**
 * Covers default-block behaviour, policy parsing and the OR-registers heuristic.
 */
class ObservabilityManifestTest extends TestCase
{
    public function testAbsentBlockYieldsDatabaseDefaultOnly(): void
    {
        $m = ObservabilityManifest::fromManifest('myapp', ['name' => 'My App']);
        $this->assertCount(1, $m->checks);
        $this->assertSame('database', $m->checks[0]->type);
        $this->assertSame([], $m->metrics);
        $this->assertSame(ObservabilityManifest::POLICY_ADR006, $m->statusCodePolicy);
    }

    public function testAbsentBlockAddsOrAvailableWhenRegistersDeclared(): void
    {
        $m = ObservabilityManifest::fromManifest('myapp', ['registers' => ['zaak']]);
        $types = array_map(fn ($c) => $c->type, $m->checks);
        $this->assertContains('database', $types);
        $this->assertContains('orAvailable', $types);
    }

    public function testStatusCodePolicyAlways200Parsed(): void
    {
        $m = ObservabilityManifest::fromManifest('decidesk', [
            'observability' => [
                'health' => [
                    'statusCodePolicy' => 'always200',
                    'cors'             => true,
                    'checks'           => [['id' => 'db', 'type' => 'database']],
                ],
            ],
        ]);
        $this->assertSame('always200', $m->statusCodePolicy);
        $this->assertTrue($m->cors);
    }

    public function testInvalidPolicyFallsBackToAdr006(): void
    {
        $m = ObservabilityManifest::fromManifest('app', [
            'observability' => ['health' => ['statusCodePolicy' => 'whatever', 'checks' => [['type' => 'database']]]],
        ]);
        $this->assertSame(ObservabilityManifest::POLICY_ADR006, $m->statusCodePolicy);
        $this->assertNotEmpty($m->diagnostics);
    }

    public function testFullBlockParsesChecksAndMetrics(): void
    {
        $m = ObservabilityManifest::fromManifest('procest', [
            'observability' => [
                'health'  => [
                    'checks' => [
                        ['id' => 'database', 'type' => 'database'],
                        ['id' => 'or', 'type' => 'orAvailable'],
                    ],
                ],
                'metrics' => [
                    ['name' => 'cases_total', 'type' => 'gauge', 'source' => ['kind' => 'objectCount', 'schema' => 'zaak', 'groupBy' => ['status']]],
                    ['name' => 'pdf_total', 'type' => 'counter', 'source' => ['kind' => 'appConfig', 'key' => 'pdf_total']],
                ],
            ],
        ]);
        $this->assertCount(2, $m->checks);
        $this->assertCount(2, $m->metrics);
        $this->assertSame([], $m->diagnostics);
    }
}
