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
	 * Calling app id.
	 *
	 * @var string
	 */
	public readonly string $appId;

	/**
	 * Parsed health checks.
	 *
	 * @var HealthCheckDescriptor[]
	 */
	public readonly array $checks;

	/**
	 * Parsed metrics.
	 *
	 * @var MetricDescriptor[]
	 */
	public readonly array $metrics;

	/**
	 * Status-code policy: adr006|always200.
	 *
	 * @var string
	 */
	public readonly string $statusCodePolicy;

	/**
	 * Emit CORS headers when true.
	 *
	 * @var bool
	 */
	public readonly bool $cors;

	/**
	 * Validation diagnostics (block fell back to defaults).
	 *
	 * @var string[]
	 */
	public readonly array $diagnostics;

	/**
	 * Constructor.
	 *
	 * @param string $appId Calling app id.
	 * @param ObservabilityProbes $probes Parsed health checks and metrics.
	 * @param HealthResponsePolicy $response Status-code policy and CORS switch.
	 * @param string[] $diagnostics Validation diagnostics (block fell back to defaults).
	 */
	public function __construct(
		string $appId,
		ObservabilityProbes $probes,
		HealthResponsePolicy $response = new HealthResponsePolicy(),
		array $diagnostics = [],
	) {
		$this->appId            = $appId;
		$this->checks           = $probes->checks;
		$this->metrics          = $probes->metrics;
		$this->statusCodePolicy = $response->statusCodePolicy;
		$this->cors             = $response->cors;
		$this->diagnostics      = $diagnostics;
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
			probes: new ObservabilityProbes(checks: $checks, metrics: $metrics),
			response: new HealthResponsePolicy(statusCodePolicy: $health['statusCodePolicy'], cors: $health['cors']),
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

		return array_merge($result, self::parseChecks(rawChecks: ($health['checks'] ?? []), diagnostics: $diagnostics));
	}//end parseHealth()

	/**
	 * Parse the health checks; any invalid entry marks the block invalid.
	 *
	 * @param mixed    $rawChecks   The `checks` list.
	 * @param string[] $diagnostics Diagnostics accumulator (by ref).
	 *
	 * @return array{checks: HealthCheckDescriptor[], valid: bool}
	 */
	private static function parseChecks(mixed $rawChecks, array &$diagnostics): array {
		if (is_array($rawChecks) === false) {
			$diagnostics[] = 'observability.health.checks must be an array.';
			return ['checks' => [], 'valid' => false];
		}

		return self::parseEachCheck(rawChecks: $rawChecks, diagnostics: $diagnostics);
	}//end parseChecks()

	/**
	 * Parse every entry of a checks list.
	 *
	 * @param array    $rawChecks   The `checks` list.
	 * @param string[] $diagnostics Diagnostics accumulator (by ref).
	 *
	 * @return array{checks: HealthCheckDescriptor[], valid: bool}
	 */
	private static function parseEachCheck(array $rawChecks, array &$diagnostics): array {
		$parsed = ['checks' => [], 'valid' => true];
		foreach ($rawChecks as $index => $rawCheck) {
			try {
				$parsed['checks'][] = self::parseCheck(rawCheck: $rawCheck, index: $index);
			} catch (ObservabilityValidationException $e) {
				$diagnostics[] = $e->getMessage();
				$parsed['valid'] = false;
			}
		}

		return $parsed;
	}//end parseEachCheck()

	/**
	 * Parse one health check.
	 *
	 * @param mixed      $rawCheck The raw entry.
	 * @param int|string $index    Its position in the list.
	 *
	 * @return HealthCheckDescriptor
	 *
	 * @throws ObservabilityValidationException When the entry is not an object or not a valid check.
	 */
	private static function parseCheck(mixed $rawCheck, int|string $index): HealthCheckDescriptor {
		if (is_array($rawCheck) === false) {
			throw new ObservabilityValidationException(message: sprintf('Health check #%d is not an object.', $index));
		}

		return HealthCheckDescriptor::fromArray(raw: $rawCheck, index: (int)$index);
	}//end parseCheck()

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
			probes: new ObservabilityProbes(checks: self::defaultChecks(manifest: $manifest), metrics: [])
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
