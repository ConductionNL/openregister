<?php

/**
 * Integration tests for the production observability surface.
 *
 * Verifies the `/api/health` and `/api/metrics` controllers return
 * the expected response shape end-to-end against the running NC
 * instance: health JSON envelope with database + filesystem checks,
 * metrics Prometheus text-exposition format with the canonical
 * register/schema/object counters.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use OCA\OpenRegister\Controller\HealthController;
use OCA\OpenRegister\Controller\MetricsController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TextPlainResponse;
use PHPUnit\Framework\TestCase;

/**
 * @group DB
 */
class ProductionObservabilityIntegrationTest extends TestCase
{
    private HealthController $healthController;
    private MetricsController $metricsController;

    protected function setUp(): void
    {
        parent::setUp();
        $this->healthController  = \OC::$server->get(HealthController::class);
        $this->metricsController = \OC::$server->get(MetricsController::class);
    }

    public function testHealthEndpointReturnsOkStructure(): void
    {
        $response = $this->healthController->index();
        $this->assertInstanceOf(JSONResponse::class, $response);

        $body = $response->getData();
        $this->assertSame(Http::STATUS_OK, $response->getStatus(), 'health endpoint MUST return 200 when DB + filesystem are reachable');
        $this->assertSame('ok', $body['status'] ?? null);
        $this->assertArrayHasKey('version', $body);
        $this->assertArrayHasKey('checks', $body);

        $checks = $body['checks'];
        $this->assertSame('ok', $checks['database']   ?? null, 'database check MUST report ok in a normal dev env');
        $this->assertSame('ok', $checks['filesystem'] ?? null, 'filesystem check MUST report ok in a normal dev env');
    }

    public function testHealthVersionMatchesAppVersion(): void
    {
        $response = $this->healthController->index();
        $body     = $response->getData();
        $this->assertNotEmpty($body['version'] ?? '', 'health response MUST include a non-empty version string');
        // Sanity: the version MUST be a sensible semver-ish shape, not literally "?" or "unknown".
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', (string) $body['version']);
    }

    public function testMetricsEndpointReturnsPrometheusTextFormat(): void
    {
        $response = $this->metricsController->index();
        $this->assertInstanceOf(TextPlainResponse::class, $response);

        $body = $this->extractResponseBody($response);
        $this->assertNotEmpty($body);

        // Prometheus exposition format: every metric MUST be preceded by
        // `# HELP` and `# TYPE` comment lines per the spec.
        $this->assertStringContainsString('# HELP openregister_info', $body);
        $this->assertStringContainsString('# TYPE openregister_info gauge', $body);
        $this->assertMatchesRegularExpression(
            '/openregister_info\{version="[^"]+",php_version="[^"]+"\} 1/',
            $body,
            'openregister_info gauge MUST carry version + php_version labels'
        );
    }

    public function testMetricsExposeStandardCanonicalCounters(): void
    {
        $body = $this->extractResponseBody($this->metricsController->index());

        // The canonical metric inventory documented in the spec.
        $expected = [
            'openregister_up',
            'openregister_registers_total',
            'openregister_schemas_total',
            'openregister_objects_total',
            'openregister_search_requests_total',
        ];
        foreach ($expected as $metric) {
            $this->assertStringContainsString($metric, $body, "metrics output MUST include '$metric'");
        }
    }

    public function testMetricsExposeCrudOperationCounters(): void
    {
        $body = $this->extractResponseBody($this->metricsController->index());

        // CRUD operation counters sourced from the audit-trail ledger —
        // closes the spec's "CRUD Operation Counters" task.
        foreach (['created', 'updated', 'deleted', 'read'] as $action) {
            $metric = 'openregister_objects_'.$action.'_total';
            $this->assertStringContainsString(
                '# HELP '.$metric,
                $body,
                "metrics output MUST advertise '$metric' with a HELP comment"
            );
            $this->assertStringContainsString(
                '# TYPE '.$metric.' counter',
                $body,
                "metrics output MUST type '$metric' as a Prometheus counter"
            );
            $this->assertMatchesRegularExpression(
                '/'.preg_quote($metric, '/').' \d+/',
                $body,
                "'$metric' MUST emit a non-negative integer value"
            );
        }
    }

    public function testMetricsExposeWebhookDeliveryCountersWithStatusLabel(): void
    {
        $body = $this->extractResponseBody($this->metricsController->index());

        $metric = 'openregister_webhook_deliveries_total';
        $this->assertStringContainsString(
            '# HELP '.$metric,
            $body,
            "metrics output MUST advertise '$metric' with a HELP comment"
        );
        $this->assertStringContainsString(
            '# TYPE '.$metric.' counter',
            $body,
            "metrics output MUST type '$metric' as a Prometheus counter"
        );

        // The webhook counter MUST be labelled with status; the label
        // vocabulary is bounded to {success, failure} so cardinality
        // stays predictable. We don't assert any concrete count because
        // the dev env may have zero webhook deliveries — only that when
        // a labelled line is present, it uses the documented vocabulary.
        if (preg_match_all('/openregister_webhook_deliveries_total\{status="([^"]+)"\}/', $body, $matches) > 0) {
            foreach ($matches[1] as $status) {
                $this->assertContains(
                    $status,
                    ['success', 'failure'],
                    'webhook status label vocabulary MUST be bounded to {success, failure}'
                );
            }
        }
    }

    public function testMetricsContentTypeIsPrometheus(): void
    {
        $response = $this->metricsController->index();
        $headers  = $response->getHeaders();
        $this->assertArrayHasKey('Content-Type', $headers);
        $this->assertStringContainsString(
            'text/plain',
            (string) $headers['Content-Type'],
            'metrics endpoint MUST set Content-Type: text/plain (Prometheus exposition format)'
        );
        $this->assertStringContainsString(
            'version=0.0.4',
            (string) $headers['Content-Type'],
            'metrics endpoint Content-Type MUST advertise Prometheus exposition format version 0.0.4'
        );
    }

    public function testMetricsCountsAreNonNegativeIntegers(): void
    {
        $body = $this->extractResponseBody($this->metricsController->index());

        // Pull the registers/schemas counters and assert they are
        // non-negative integers (counts can't be -1, etc.).
        if (preg_match('/openregister_registers_total (\d+)/', $body, $m)) {
            $this->assertGreaterThanOrEqual(0, (int) $m[1]);
        } else {
            $this->fail('openregister_registers_total numeric value not found in metrics output');
        }

        if (preg_match('/openregister_schemas_total (\d+)/', $body, $m)) {
            $this->assertGreaterThanOrEqual(0, (int) $m[1]);
        } else {
            $this->fail('openregister_schemas_total numeric value not found in metrics output');
        }
    }

    /**
     * Extract the response body from a TextPlainResponse via the standard
     * AppFramework `render()` API.
     */
    private function extractResponseBody(TextPlainResponse $response): string
    {
        return (string) $response->render();
    }
}
