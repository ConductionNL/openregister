<?php

/**
 * OpenRegister AppHost — Health Check Descriptor
 *
 * Immutable value object describing a single declarative health check parsed
 * from a host app's manifest `observability.health.checks[]` block.
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
 * Validated descriptor for one declarative health check.
 *
 * The closed set of supported types is defined by ADR-040; any other value
 * raises {@see ObservabilityValidationException} so it surfaces as a manifest
 * diagnostic rather than a runtime 500.
 *
 * @psalm-immutable
 *
 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-1.1
 */
final class HealthCheckDescriptor {
	/**
	 * Closed set of supported check types (ADR-040).
	 *
	 * @var string[]
	 */
	public const TYPES = [
		'database',
		'filesystem',
		'appEnabled',
		'appConfig',
		'orAvailable',
	];

	/**
	 * Closed set of supported severities.
	 *
	 * @var string[]
	 */
	public const SEVERITIES = ['critical', 'degraded'];

	/**
	 * Closed set of supported appConfig assertions.
	 *
	 * @var string[]
	 */
	public const ASSERTIONS = ['present', 'nonEmpty'];

	/**
	 * Constructor.
	 *
	 * @param string $id Stable check id used as the `checks` map key.
	 * @param string $type One of self::TYPES.
	 * @param string $severity One of self::SEVERITIES.
	 * @param string|null $app Target app id (appEnabled only).
	 * @param string|null $key AppConfig key (appConfig only).
	 * @param string|null $assert AppConfig assertion (appConfig only).
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $type,
		public readonly string $severity,
		public readonly ?string $app = null,
		public readonly ?string $key = null,
		public readonly ?string $assert = null,
	) {
	}//end __construct()

	/**
	 * Build a validated descriptor from a raw manifest array entry.
	 *
	 * @param array<string, mixed> $raw Raw `checks[]` entry.
	 * @param int $index Position in the array (for default ids).
	 *
	 * @return self
	 *
	 * @throws ObservabilityValidationException When the entry is malformed.
	 *
	 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-1.1
	 */
	public static function fromArray(array $raw, int $index = 0): self {
		$type = $raw['type'] ?? null;
		if (is_string($type) === false || in_array($type, self::TYPES, true) === false) {
			$allowed = implode(', ', self::TYPES);
			throw new ObservabilityValidationException(
				sprintf('Unknown health check type "%s" (allowed: %s).', self::describe(value: $type), $allowed)
			);
		}

		$severity = $raw['severity'] ?? 'critical';
		if (is_string($severity) === false || in_array($severity, self::SEVERITIES, true) === false) {
			$allowed = implode(', ', self::SEVERITIES);
			throw new ObservabilityValidationException(
				sprintf('Invalid severity "%s" for health check (allowed: %s).', self::describe(value: $severity), $allowed)
			);
		}

		$id = $raw['id'] ?? $type . '_' . $index;
		if (is_string($id) === false || $id === '') {
			throw new ObservabilityValidationException('Health check id must be a non-empty string.');
		}

		$app = null;
		$key = null;
		$assert = null;

		if ($type === 'appEnabled') {
			$app = $raw['app'] ?? null;
			if (is_string($app) === false || preg_match('/^[a-z0-9_-]+$/i', $app) !== 1) {
				throw new ObservabilityValidationException('appEnabled check requires a valid "app" id.');
			}
		}

		if ($type === 'appConfig') {
			$key = $raw['key'] ?? null;
			if (is_string($key) === false || $key === '') {
				throw new ObservabilityValidationException('appConfig check requires a non-empty "key".');
			}

			$assert = $raw['assert'] ?? 'present';
			if (is_string($assert) === false || in_array($assert, self::ASSERTIONS, true) === false) {
				$allowed = implode(', ', self::ASSERTIONS);
				throw new ObservabilityValidationException(
					sprintf('Invalid appConfig assert "%s" (allowed: %s).', self::describe(value: $assert), $allowed)
				);
			}
		}

		return new self(
			id: $id,
			type: $type,
			severity: $severity,
			app: $app,
			key: $key,
			assert: $assert
		);
	}//end fromArray()

	/**
	 * Render a raw (possibly non-scalar) value as a short string for an
	 * error message, without leaking object internals.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return string
	 */
	private static function describe(mixed $value): string {
		if (is_scalar($value) === true) {
			return (string)$value;
		}

		return gettype($value);
	}//end describe()
}//end class
