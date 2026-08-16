<?php

/**
 * OpenRegister AppHost — Prometheus Renderer
 *
 * Renders MetricSample value objects to Prometheus text exposition format
 * 0.0.4 (HELP/TYPE lines, `{app}_` prefix sanitised to [a-z0-9_], label
 * value escaping). The exposition format is engine-owned so adopting apps
 * cannot drift it (fixes shillinq's JSON metrics on adoption).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\AppHost\Observability
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Observability;

/**
 * Renders metric samples to Prometheus text exposition format 0.0.4.
 *
 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-3.1
 */
class PrometheusRenderer {
	/**
	 * The Content-Type for Prometheus text exposition 0.0.4.
	 */
	public const CONTENT_TYPE = 'text/plain; version=0.0.4; charset=utf-8';

	/**
	 * Render a list of metric families for a given app prefix.
	 *
	 * @param string $appId Calling app id (used for the metric prefix).
	 * @param MetricSample[] $samples Metric families.
	 *
	 * @return string Prometheus text exposition.
	 *
	 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-3.1
	 */
	public function render(string $appId, array $samples): string {
		$prefix = $this->sanitizePrefix(appId: $appId);
		$lines = [];

		foreach ($samples as $sample) {
			$metricName = $prefix . '_' . $this->sanitizeMetricName(name: $sample->name);
			$metricType = 'gauge';
			if ($sample->type === 'counter') {
				$metricType = 'counter';
			}

			$lines[] = '# HELP ' . $metricName . ' ' . $this->escapeHelp(text: $sample->help);
			$lines[] = '# TYPE ' . $metricName . ' ' . $metricType;

			foreach ($sample->samples as $point) {
				$labels = $point['labels'];
				$value = $point['value'];
				$lines[] = $metricName . $this->renderLabels(labels: $labels) . ' ' . $this->renderValue(value: $value);
			}

			$lines[] = '';
		}//end foreach

		return implode("\n", $lines) . "\n";
	}//end render()

	/**
	 * Sanitise the app id into a Prometheus-safe metric prefix `[a-z0-9_]`.
	 *
	 * @param string $appId Calling app id.
	 *
	 * @return string
	 */
	private function sanitizePrefix(string $appId): string {
		$prefix = strtolower($appId);
		$prefix = preg_replace('/[^a-z0-9_]/', '_', $prefix);
		$prefix = trim((string)$prefix, '_');
		if ($prefix === '' || preg_match('/^[a-z_]/', $prefix) !== 1) {
			$prefix = 'app_' . $prefix;
		}

		return $prefix;
	}//end sanitizePrefix()

	/**
	 * Sanitise a metric name body to `[a-zA-Z0-9_]`.
	 *
	 * @param string $name Metric name.
	 *
	 * @return string
	 */
	private function sanitizeMetricName(string $name): string {
		return (string)preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
	}//end sanitizeMetricName()

	/**
	 * Render a label set into `{k="v",...}` (or empty string).
	 *
	 * @param array<string, string> $labels Label map.
	 *
	 * @return string
	 */
	private function renderLabels(array $labels): string {
		if ($labels === []) {
			return '';
		}

		$parts = [];
		foreach ($labels as $key => $value) {
			$safeKey = (string)preg_replace('/[^a-zA-Z0-9_]/', '_', (string)$key);
			if (preg_match('/^[a-zA-Z_]/', $safeKey) !== 1) {
				$safeKey = '_' . $safeKey;
			}

			$parts[] = $safeKey . '="' . $this->escapeLabelValue(value: (string)$value) . '"';
		}

		return '{' . implode(',', $parts) . '}';
	}//end renderLabels()

	/**
	 * Escape a label value per the exposition format (backslash, quote, newline).
	 *
	 * @param string $value Raw value.
	 *
	 * @return string
	 */
	private function escapeLabelValue(string $value): string {
		return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
	}//end escapeLabelValue()

	/**
	 * Escape HELP text (backslash + newline).
	 *
	 * @param string $text Help text.
	 *
	 * @return string
	 */
	private function escapeHelp(string $text): string {
		return str_replace(['\\', "\n"], ['\\\\', '\\n'], $text);
	}//end escapeHelp()

	/**
	 * Render a numeric value (ints stay integral, floats keep precision).
	 *
	 * @param float|int $value Value.
	 *
	 * @return string
	 */
	private function renderValue(float|int $value): string {
		if (is_int($value) === true || (float)(int)$value === (float)$value) {
			return (string)(int)$value;
		}

		return (string)$value;
	}//end renderValue()
}//end class
