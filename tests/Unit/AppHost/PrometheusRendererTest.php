<?php

/**
 * AppHost PrometheusRenderer tests — exposition format, prefix sanitise,
 * label escaping.
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

use OCA\OpenRegister\AppHost\Observability\MetricSample;
use OCA\OpenRegister\AppHost\Observability\PrometheusRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Verifies Prometheus text 0.0.4 rendering invariants.
 */
class PrometheusRendererTest extends TestCase {
	private PrometheusRenderer $renderer;

	protected function setUp(): void {
		$this->renderer = new PrometheusRenderer();
	}

	public function testHelpTypeAndPrefix(): void {
		$out = $this->renderer->render('procest', [
			MetricSample::single('cases_total', 'gauge', 'Cases', 7),
		]);
		$this->assertStringContainsString('# HELP procest_cases_total Cases', $out);
		$this->assertStringContainsString('# TYPE procest_cases_total gauge', $out);
		$this->assertStringContainsString('procest_cases_total 7', $out);
	}

	public function testLabelledSamples(): void {
		$out = $this->renderer->render('procest', [
			new MetricSample('cases_total', 'gauge', 'Cases', [
				['labels' => ['status' => 'open'], 'value' => 3],
				['labels' => ['status' => 'closed'], 'value' => 5],
			]),
		]);
		$this->assertStringContainsString('procest_cases_total{status="open"} 3', $out);
		$this->assertStringContainsString('procest_cases_total{status="closed"} 5', $out);
	}

	public function testCounterType(): void {
		$out = $this->renderer->render('app', [MetricSample::single('hits', 'counter', 'Hits', 1)]);
		$this->assertStringContainsString('# TYPE app_hits counter', $out);
	}

	public function testLabelValueEscaping(): void {
		$out = $this->renderer->render('app', [
			new MetricSample('m', 'gauge', 'H', [['labels' => ['k' => 'a"b\\c' . "\n" . 'd'], 'value' => 1]]),
		]);
		$this->assertStringContainsString('k="a\\"b\\\\c\\nd"', $out);
	}

	public function testPrefixSanitisedToSafeChars(): void {
		$out = $this->renderer->render('My-App.v2', [MetricSample::single('m', 'gauge', 'H', 1)]);
		// Lowercased, non [a-z0-9_] => _.
		$this->assertStringContainsString('my_app_v2_m 1', $out);
	}
}
