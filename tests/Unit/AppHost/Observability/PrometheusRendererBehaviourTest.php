<?php

/**
 * Tests for PrometheusRenderer beyond PrometheusRendererTest: prefixes that
 * need `app_`, label keys that need `_`, HELP escaping, float values and the
 * exact document layout.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Observability
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Observability;

use OCA\OpenRegister\AppHost\Observability\MetricSample;
use OCA\OpenRegister\AppHost\Observability\PrometheusRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Exact-output checks of the renderer.
 */
class PrometheusRendererBehaviourTest extends TestCase {

	public function testAPrefixStartingWithADigitGetsAppPrefix(): void {
		$out = (new PrometheusRenderer())->render('2fa', [new MetricSample('m', 'gauge', 'H', [['labels' => [], 'value' => 1]])]);

		$this->assertSame("# HELP app_2fa_m H\n# TYPE app_2fa_m gauge\napp_2fa_m 1\n\n", $out);
	}

	public function testAnAppIdOfOnlyUnsafeCharactersBecomesApp(): void {
		$out = (new PrometheusRenderer())->render('--', [new MetricSample('m', 'gauge', 'H', [['labels' => [], 'value' => 1]])]);

		$this->assertStringContainsString("\napp__m 1\n", $out);
	}

	public function testUnderscoresAreTrimmedFromThePrefix(): void {
		$out = (new PrometheusRenderer())->render('_myapp_', [new MetricSample('m', 'gauge', 'H', [['labels' => [], 'value' => 1]])]);

		$this->assertStringContainsString("\nmyapp_m 1\n", $out);
	}

	public function testMetricNameAndLabelKeysAreSanitised(): void {
		$out = (new PrometheusRenderer())->render(
			'app',
			[new MetricSample('cases.total', 'gauge', 'H', [['labels' => ['1st' => 'a', 'b-c' => 'b', 'ok' => 'c'], 'value' => 2]])]
		);

		$this->assertStringContainsString('app_cases_total{_1st="a",b_c="b",ok="c"} 2', $out);
	}

	public function testHelpBackslashAndNewlineAreEscaped(): void {
		$out = (new PrometheusRenderer())->render('app', [new MetricSample('m', 'gauge', "a\\b\nc", [['labels' => [], 'value' => 1]])]);

		$this->assertStringContainsString('# HELP app_m a\\\\b\\nc'."\n", $out);
	}

	public function testUnknownTypeRendersAsGauge(): void {
		$out = (new PrometheusRenderer())->render('app', [new MetricSample('m', 'histogram', 'H', [['labels' => [], 'value' => 1]])]);

		$this->assertStringContainsString("# TYPE app_m gauge\n", $out);
	}

	public function testFloatValuesKeepFractionAndIntegralFloatsLoseIt(): void {
		$out = (new PrometheusRenderer())->render(
			'app',
			[
				new MetricSample(
					'm',
					'gauge',
					'H',
					[
						['labels' => ['k' => 'a'], 'value' => 1.5],
						['labels' => ['k' => 'b'], 'value' => 3.0],
					]
				),
			]
		);

		$this->assertSame(
			"# HELP app_m H\n# TYPE app_m gauge\napp_m{k=\"a\"} 1.5\napp_m{k=\"b\"} 3\n\n",
			$out
		);
	}

	public function testAFamilyWithoutSamplesStillGetsHelpAndTypeAndNoSamplesRenderNothing(): void {
		$renderer = new PrometheusRenderer();

		$this->assertSame(
			"# HELP app_m H\n# TYPE app_m counter\n\n",
			$renderer->render('app', [new MetricSample('m', 'counter', 'H', [])])
		);
		$this->assertSame("\n", $renderer->render('app', []));
	}
}//end class
