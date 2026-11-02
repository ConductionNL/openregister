<?php

/**
 * OpenRegister AppHost — Observability Manifest
 *
 * Parses and validates the `observability` block of a host app's
 * `src/manifest.json` into descriptor value objects, applying ADR-040
 * defaults when the block (or a sub-section) is absent.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category ValueObject
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
 * Validated observability configuration for a single host app.
 *
 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-1.2
 */
final class ObservabilityManifest {
	/**
	 * Default status-code policy (ADR-006: 503 on critical failure).
	 */
	public const POLICY_ADR006 = 'adr006';

	/**
	 * Always-200 policy (decidesk REQ-API-004 reverse-proxy contract).
	 */
	public const POLICY_ALWAYS200 = 'always200';

	/**
	 * Closed set of supported status-code policies.
	 *
	 * @var string[]
	 */
	public const POLICIES = [self::POLICY_ADR006, self::POLICY_ALWAYS200];

	/**
	 * Constructor.
	 *
	 * @param string $appId Calling app id.
	 * @param HealthCheckDescriptor[] $checks Parsed health checks.
	 * @param MetricDescriptor[] $metrics Parsed metrics.
	 * @param string $statusCodePolicy adr006|always200.
	 * @param bool $cors Emit CORS headers when true.
	 * @param string[] $diagnostics Validation diagnostics (block fell back to defaults).
	 */
	public function __construct(
		public readonly string $appId,
		public readonly array $checks,
		public readonly array $metrics,
		public readonly string $statusCodePolicy = self::POLICY_ADR006,
		public readonly bool $cors = false,
		public readonly array $diagnostics = [],
	) {
	}//end __construct()

	/**
	 * Build an observability config from a decoded manifest.
	 *
	 * Invalid descriptors do not throw out of this method: they are collected
	 * into `$diagnostics` and the config falls back to safe defaults so the
	 * endpoint never 500s on a bad manifest (Descriptor Validation requirement).
	 *
	 * @param string $appId Calling app id.
	 * @param array<string, mixed> $manifest Decoded manifest.json.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-1.2
	 */
	public static function fromManifest(string $appId, array $manifest): self {
		$block = $manifest['observability'] ?? null;
		if (is_array($block) === false) {
			return self::defaults(appId: $appId, manifest: $manifest);
		}

		$diagnostics = [];
		$health = self::parseHealth(health: ($block['health'] ?? null), diagnostics: $diagnostics);

		// If the health block was malformed enough to lose checks, fall back to default health.
		$checks = $health['checks'];
		if ($health['valid'] === false || $checks === []) {
			$checks = self::defaultChecks(manifest: $manifest);
		}

		$metrics = self::parseMetrics(rawMetrics: ($block['metrics'] ?? []), diagnostics: $diagnostics);

		return new self(
			appId: $appId,
			checks: $checks,
			metrics: $metrics,
			statusCodePolicy: $health['statusCodePolicy'],
			cors: $health['cors'],
			diagnostics: $diagnostics
		);
	}//end fromManifest()

	/**
	 * Read the health section: status code policy, CORS flag and checks.
	 *
	 * @param mixed    $health      The `observability.health` value.
	 * @param string[] $diagnostics Diagnostics collected so far; appended to.
	 *
	 * @return array{statusCodePolicy: string, cors: bool, checks: HealthCheckDescriptor[], valid: bool}
	 */
	private static function parseHealth(mixed $health, array &$diagnostics): array {
		$result = ['statusCodePolicy' => self::POLICY_ADR006, 'cors' => false, 'checks' => [], 'valid' => true];
		if (is_array($health) === false) {
			return $result;
		}

		$result['statusCodePolicy'] = self::parseStatusCodePolicy(
			policy: ($health['statusCodePolicy'] ?? self::POLICY_ADR006),
			diagnostics: $diagnostics
		);
		$result['cors'] = ($health['cors'] ?? false) === true;

		$rawChecks = $health['checks'] ?? [];
		if (is_array($rawChecks) === false) {
			$diagnostics[] = 'observability.health.checks must be an array.';
			$result['valid'] = false;
			return $result;
		}

		foreach ($rawChecks as $index => $rawCheck) {
			if (is_array($rawCheck) === false) {
				$diagnostics[] = sprintf('Health check #%d is not an object.', $index);
				$result['valid'] = false;
				continue;
			}

			try {
				$result['checks'][] = HealthCheckDescriptor::fromArray(raw: $rawCheck, index: (int)$index);
			} catch (ObservabilityValidationException $e) {
				$diagnostics[] = $e->getMessage();
				$result['valid'] = false;
			}
		}

		return $result;
	}//end parseHealth()

	/**
	 * The status code policy, or adr006 with a diagnostic when it is not a known policy.
	 *
	 * @param mixed    $policy      The declared policy.
	 * @param string[] $diagnostics Diagnostics collected so far; appended to.
	 *
	 * @return string
	 */
	private static function parseStatusCodePolicy(mixed $policy, array &$diagnostics): string {
		if (is_string($policy) === true && in_array($policy, self::POLICIES, true) === true) {
			return $policy;
		}

		$shownPolicy = gettype($policy);
		if (is_scalar($policy) === true) {
			$shownPolicy = (string)$policy;
		}

		$diagnostics[] = sprintf('Invalid statusCodePolicy "%s"; defaulting to adr006.', $shownPolicy);
		return self::POLICY_ADR006;
	}//end parseStatusCodePolicy()

	/**
	 * Read the metrics section, skipping each bad metric; implicit info/up always still serve.
	 *
	 * @param mixed    $rawMetrics  The `observability.metrics` value.
	 * @param string[] $diagnostics Diagnostics collected so far; appended to.
	 *
	 * @return MetricDescriptor[]
	 */
	private static function parseMetrics(mixed $rawMetrics, array &$diagnostics): array {
		if (is_array($rawMetrics) === false) {
			$diagnostics[] = 'observability.metrics must be an array.';
			return [];
		}

		$metrics = [];
		foreach ($rawMetrics as $index => $rawMetric) {
			if (is_array($rawMetric) === false) {
				$diagnostics[] = sprintf('Metric #%d is not an object.', $index);
				continue;
			}

			try {
				$metrics[] = MetricDescriptor::fromArray(raw: $rawMetric);
			} catch (ObservabilityValidationException $e) {
				$diagnostics[] = $e->getMessage();
			}
		}

		return $metrics;
	}//end parseMetrics()

	/**
	 * Build the default config for an app with no (or an unusable)
	 * observability block: a `database` check, plus `orAvailable` when the
	 * manifest declares OpenRegister registers; metrics = implicit only.
	 *
	 * @param string $appId Calling app id.
	 * @param array<string, mixed> $manifest Decoded manifest.json.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-1.2
	 */
	public static function defaults(string $appId, array $manifest): self {
		return new self(
			appId: $appId,
			checks: self::defaultChecks(manifest: $manifest),
			metrics: [],
			statusCodePolicy: self::POLICY_ADR006,
			cors: false,
			diagnostics: []
		);
	}//end defaults()

	/**
	 * Build the default health check list from a manifest.
	 *
	 * @param array<string, mixed> $manifest Decoded manifest.json.
	 *
	 * @return HealthCheckDescriptor[]
	 */
	private static function defaultChecks(array $manifest): array {
		$checks = [
			new HealthCheckDescriptor(id: 'database', type: 'database', severity: 'critical'),
		];

		if (self::declaresOpenRegisterRegisters(manifest: $manifest) === true) {
			$checks[] = new HealthCheckDescriptor(id: 'or', type: 'orAvailable', severity: 'critical');
		}

		return $checks;
	}//end defaultChecks()

	/**
	 * Heuristic: does the manifest declare OpenRegister registers? Looks at the
	 * common manifest shapes (`registers`, `openregister.registers`,
	 * `config.registers`) so the default `orAvailable` check is added for
	 * OR-backed apps.
	 *
	 * @param array<string, mixed> $manifest Decoded manifest.json.
	 *
	 * @return bool
	 */
	private static function declaresOpenRegisterRegisters(array $manifest): bool {
		if (isset($manifest['registers']) === true && empty($manifest['registers']) === false) {
			return true;
		}

		$openregister = $manifest['openregister'] ?? null;
		if (is_array($openregister) === true && empty($openregister['registers']) === false) {
			return true;
		}

		$config = $manifest['config'] ?? null;
		if (is_array($config) === true && empty($config['registers']) === false) {
			return true;
		}

		return false;
	}//end declaresOpenRegisterRegisters()
}//end class
