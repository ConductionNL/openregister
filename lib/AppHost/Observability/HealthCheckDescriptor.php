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
	 * Stable check id used as the `checks` map key.
	 *
	 * @var string
	 */
	public readonly string $id;

	/**
	 * One of self::TYPES.
	 *
	 * @var string
	 */
	public readonly string $type;

	/**
	 * One of self::SEVERITIES.
	 *
	 * @var string
	 */
	public readonly string $severity;

	/**
	 * Target app id (appEnabled only).
	 *
	 * @var string|null
	 */
	public readonly ?string $app;

	/**
	 * AppConfig key (appConfig only).
	 *
	 * @var string|null
	 */
	public readonly ?string $key;

	/**
	 * AppConfig assertion (appConfig only).
	 *
	 * @var string|null
	 */
	public readonly ?string $assert;

	/**
	 * Constructor.
	 *
	 * @param string            $id       Stable check id used as the `checks` map key.
	 * @param string            $type     One of self::TYPES.
	 * @param string            $severity One of self::SEVERITIES.
	 * @param HealthCheckTarget $target   What the check looks at: the app, or the appConfig key and assertion.
	 */
	public function __construct(
		string $id,
		string $type,
		string $severity,
		HealthCheckTarget $target=new HealthCheckTarget(),
	) {
		$this->id       = $id;
		$this->type     = $type;
		$this->severity = $severity;
		$this->app      = $target->app;
		$this->key      = $target->key;
		$this->assert   = $target->assert;
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
		$type = self::oneOf(value: $raw['type'] ?? null, rule: ['allowed' => self::TYPES, 'message' => 'Unknown health check type "%s" (allowed: %s).']);
		$severity = self::oneOf(
			value: $raw['severity'] ?? 'critical',
			rule: ['allowed' => self::SEVERITIES, 'message' => 'Invalid severity "%s" for health check (allowed: %s).']
		);

		$id = $raw['id'] ?? $type . '_' . $index;
		if (is_string($id) === false || $id === '') {
			throw new ObservabilityValidationException('Health check id must be a non-empty string.');
		}

		$target = new HealthCheckTarget();
		if ($type === 'appEnabled') {
			$target = new HealthCheckTarget(app: self::appId(raw: $raw));
		}

		if ($type === 'appConfig') {
			$target = self::appConfigTarget(raw: $raw);
		}

		return new self(
			id: $id,
			type: $type,
			severity: $severity,
			target: $target
		);
	}//end fromArray()

	/**
	 * A value that must be one of a closed set of strings.
	 *
	 * @param mixed                                       $value The value.
	 * @param array{allowed: string[], message: string} $rule  The set, and the refusal with %s for the value and the set.
	 *
	 * @return string
	 *
	 * @throws ObservabilityValidationException When the value is not in the set.
	 *
	 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-1.1
	 */
	private static function oneOf(mixed $value, array $rule): string {
		if (is_string($value) === false || in_array($value, $rule['allowed'], true) === false) {
			throw new ObservabilityValidationException(
				sprintf($rule['message'], DescriptorValue::describe(value: $value), implode(', ', $rule['allowed']))
			);
		}

		return $value;
	}//end oneOf()

	/**
	 * The app id an appEnabled check names.
	 *
	 * @param array<string, mixed> $raw The raw entry.
	 *
	 * @return string
	 *
	 * @throws ObservabilityValidationException When the app id is missing or malformed.
	 *
	 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-1.1
	 */
	private static function appId(array $raw): string {
		$app = $raw['app'] ?? null;
		if (is_string($app) === false || preg_match('/^[a-z0-9_-]+$/i', $app) !== 1) {
			throw new ObservabilityValidationException('appEnabled check requires a valid "app" id.');
		}

		return $app;
	}//end appId()

	/**
	 * The key and assertion an appConfig check names; the assertion defaults to present.
	 *
	 * @param array<string, mixed> $raw The raw entry.
	 *
	 * @return HealthCheckTarget The key and assertion, with no app.
	 *
	 * @throws ObservabilityValidationException When the key is empty or the assertion unknown.
	 *
	 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-1.1
	 */
	private static function appConfigTarget(array $raw): HealthCheckTarget {
		$key = $raw['key'] ?? null;
		if (is_string($key) === false || $key === '') {
			throw new ObservabilityValidationException('appConfig check requires a non-empty "key".');
		}

		$assert = self::oneOf(
			value: $raw['assert'] ?? 'present',
			rule: ['allowed' => self::ASSERTIONS, 'message' => 'Invalid appConfig assert "%s" (allowed: %s).']
		);

		return new HealthCheckTarget(key: $key, assert: $assert);
	}//end appConfigTarget()
}//end class
