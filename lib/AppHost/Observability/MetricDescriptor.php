<?php

/**
 * OpenRegister AppHost — Metric Descriptor
 *
 * Immutable value object describing a single declarative Prometheus metric
 * parsed from a host app's manifest `observability.metrics[]` block.
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
 * Validated descriptor for one declarative metric.
 *
 * The closed set of source kinds, the Prometheus metric types, the filter
 * operator allowlist and the `tableCount.table` regex are all defined by
 * ADR-040 and enforced here at manifest-validation time.
 *
 * @psalm-immutable
 *
 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-1.1
 */
final class MetricDescriptor {
	/**
	 * Closed set of supported source kinds (ADR-040).
	 *
	 * @var string[]
	 */
	public const KINDS = [
		'objectCount',
		'objectSum',
		'tableCount',
		'appConfig',
		'provider',
	];

	/**
	 * Closed set of supported Prometheus metric types.
	 *
	 * @var string[]
	 */
	public const METRIC_TYPES = ['gauge', 'counter'];

	/**
	 * Closed set of supported filter operators (ADR-040).
	 *
	 * @var string[]
	 */
	public const FILTER_OPERATORS = ['eq', 'neq', 'lt', 'lte', 'gt', 'gte', 'like'];

	/**
	 * Allowlist regex for a `tableCount.table` value. The security boundary:
	 * only `[a-z0-9_]+` table names reach the QueryBuilder table API.
	 */
	public const TABLE_REGEX = '/^[a-z0-9_]+$/';

	/**
	 * Constructor.
	 *
	 * @param string $name Metric name (without the `{app}_` prefix).
	 * @param string $type Prometheus type (gauge|counter).
	 * @param string $kind Source kind (self::KINDS).
	 * @param array<string, mixed> $source Raw validated source params.
	 * @param string|null $help HELP text.
	 * @param int $cacheTtl Per-metric cache TTL in seconds (0 = no cache).
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $type,
		public readonly string $kind,
		public readonly array $source,
		public readonly ?string $help = null,
		public readonly int $cacheTtl = 0,
	) {
	}//end __construct()

	/**
	 * Build a validated descriptor from a raw manifest array entry.
	 *
	 * @param array<string, mixed> $raw Raw `metrics[]` entry.
	 *
	 * @return self
	 *
	 * @throws ObservabilityValidationException When the entry is malformed.
	 *
	 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-1.1
	 */
	public static function fromArray(array $raw): self {
		$name = self::validName(raw: $raw);
		$type = self::validType(raw: $raw);
		$source = self::validSource(name: $name, raw: $raw);
		$cacheTtl = self::validCacheTtl(name: $name, raw: $raw);

		return new self(
			name: $name,
			type: $type,
			kind: $source['kind'],
			source: $source,
			help: self::validHelp(name: $name, raw: $raw),
			cacheTtl: $cacheTtl
		);
	}//end fromArray()

	/**
	 * The entry's metric name, which must be a valid Prometheus metric name.
	 *
	 * @param array<string, mixed> $raw Raw `metrics[]` entry.
	 *
	 * @return string The name.
	 *
	 * @throws ObservabilityValidationException When the name is missing or malformed.
	 */
	private static function validName(array $raw): string {
		$name = $raw['name'] ?? null;
		if (is_string($name) === false || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name) !== 1) {
			throw new ObservabilityValidationException('Metric "name" must be a valid Prometheus metric name.');
		}

		return $name;
	}//end validName()

	/**
	 * The entry's Prometheus type, `gauge` when absent.
	 *
	 * @param array<string, mixed> $raw Raw `metrics[]` entry.
	 *
	 * @return string The type (self::METRIC_TYPES).
	 *
	 * @throws ObservabilityValidationException When the type is not supported.
	 */
	private static function validType(array $raw): string {
		$type = $raw['type'] ?? 'gauge';
		if (is_string($type) === false || in_array($type, self::METRIC_TYPES, true) === false) {
			$allowed = implode(', ', self::METRIC_TYPES);
			throw new ObservabilityValidationException(
				sprintf('Invalid metric type "%s" (allowed: %s).', DescriptorValue::describe(value: $type), $allowed)
			);
		}

		return $type;
	}//end validType()

	/**
	 * The entry's source block, validated for its kind.
	 *
	 * @param string               $name Metric name (for messages).
	 * @param array<string, mixed> $raw  Raw `metrics[]` entry.
	 *
	 * @return array<string, mixed> The source block; its `kind` is one of self::KINDS.
	 *
	 * @throws ObservabilityValidationException When the source is missing or malformed.
	 */
	private static function validSource(string $name, array $raw): array {
		$source = $raw['source'] ?? null;
		if (is_array($source) === false) {
			throw new ObservabilityValidationException(sprintf('Metric "%s" requires a "source" object.', $name));
		}

		self::validateSource(name: $name, source: $source);
		return $source;
	}//end validSource()

	/**
	 * The entry's per-metric cache TTL, 0 when absent.
	 *
	 * @param string               $name Metric name (for messages).
	 * @param array<string, mixed> $raw  Raw `metrics[]` entry.
	 *
	 * @return int The TTL in seconds.
	 *
	 * @throws ObservabilityValidationException When the TTL is not a non-negative integer.
	 */
	private static function validCacheTtl(string $name, array $raw): int {
		$cacheTtl = $raw['cacheTtl'] ?? 0;
		if (is_int($cacheTtl) === false || $cacheTtl < 0) {
			throw new ObservabilityValidationException(sprintf('Metric "%s" cacheTtl must be a non-negative integer.', $name));
		}

		return $cacheTtl;
	}//end validCacheTtl()

	/**
	 * The entry's HELP text, null when absent.
	 *
	 * @param string               $name Metric name (for messages).
	 * @param array<string, mixed> $raw  Raw `metrics[]` entry.
	 *
	 * @return string|null The HELP text.
	 *
	 * @throws ObservabilityValidationException When the HELP text is not a string.
	 */
	private static function validHelp(string $name, array $raw): ?string {
		$help = $raw['help'] ?? null;
		if ($help !== null && is_string($help) === false) {
			throw new ObservabilityValidationException(sprintf('Metric "%s" help must be a string.', $name));
		}

		return $help;
	}//end validHelp()

	/**
	 * Validate a source block per its kind.
	 *
	 * `provider` takes no parameters; resolution happens via the registered
	 * IMetricsProvider alias. The only kinds left after the others are the
	 * two object kinds, because the kind is checked against self::KINDS first.
	 *
	 * @param string               $name   Metric name (for messages).
	 * @param array<string, mixed> $source Raw source block.
	 *
	 * @return void
	 *
	 * @throws ObservabilityValidationException When the source is malformed.
	 */
	private static function validateSource(string $name, array $source): void {
		match (self::validKind(source: $source)) {
			'tableCount' => self::validateTableSource(name: $name, source: $source),
			'appConfig' => self::validateAppConfigSource(name: $name, source: $source),
			'provider' => null,
			default => self::validateObjectSource(name: $name, source: $source),
		};
	}//end validateSource()

	/**
	 * The source block's kind, which must be one of self::KINDS.
	 *
	 * @param array<string, mixed> $source Raw source block.
	 *
	 * @return string The kind.
	 *
	 * @throws ObservabilityValidationException When the kind is unknown.
	 */
	private static function validKind(array $source): string {
		$kind = $source['kind'] ?? null;
		if (is_string($kind) === false || in_array($kind, self::KINDS, true) === false) {
			$allowed = implode(', ', self::KINDS);
			throw new ObservabilityValidationException(
				sprintf('Unknown metric source kind "%s" (allowed: %s).', DescriptorValue::describe(value: $kind), $allowed)
			);
		}

		return $kind;
	}//end validKind()

	/**
	 * Validate an `objectCount` or `objectSum` source block.
	 *
	 * @param string               $name   Metric name (for messages).
	 * @param array<string, mixed> $source Raw source block.
	 *
	 * @return void
	 *
	 * @throws ObservabilityValidationException When the source is malformed.
	 */
	private static function validateObjectSource(string $name, array $source): void {
		self::validateSchemaReference(name: $name, source: $source);
		if ($source['kind'] === 'objectSum' && self::isNonEmptyString(value: $source['field'] ?? null) === false) {
			throw new ObservabilityValidationException(sprintf('Metric "%s" objectSum source requires a numeric "field".', $name));
		}

		self::validateGroupBy(name: $name, source: $source);
		self::validateFilter(name: $name, source: $source);
	}//end validateObjectSource()

	/**
	 * Validate the `schema` slug (required) and `register` slug (optional) of an object source.
	 *
	 * @param string               $name   Metric name (for messages).
	 * @param array<string, mixed> $source Raw source block.
	 *
	 * @return void
	 *
	 * @throws ObservabilityValidationException When either slug is malformed.
	 */
	private static function validateSchemaReference(string $name, array $source): void {
		if (self::isNonEmptyString(value: $source['schema'] ?? null) === false) {
			throw new ObservabilityValidationException(sprintf('Metric "%s" %s source requires a "schema" slug.', $name, $source['kind']));
		}

		if (isset($source['register']) === true && is_string($source['register']) === false) {
			throw new ObservabilityValidationException(sprintf('Metric "%s" "register" must be a slug string.', $name));
		}
	}//end validateSchemaReference()

	/**
	 * Validate a `tableCount` source block.
	 *
	 * @param string               $name   Metric name (for messages).
	 * @param array<string, mixed> $source Raw source block.
	 *
	 * @return void
	 *
	 * @throws ObservabilityValidationException When the source is malformed.
	 */
	private static function validateTableSource(string $name, array $source): void {
		$table = $source['table'] ?? null;
		if (is_string($table) === false || preg_match(self::TABLE_REGEX, $table) !== 1) {
			$shown = DescriptorValue::describe(value: $table);
			throw new ObservabilityValidationException(
				sprintf('Metric "%s" tableCount "table" must match %s (got "%s").', $name, self::TABLE_REGEX, $shown)
			);
		}

		self::validateGroupBy(name: $name, source: $source);
		self::validateFilter(name: $name, source: $source);
		self::validateLabelMaps(name: $name, source: $source);
	}//end validateTableSource()

	/**
	 * Validate the optional `labelMap` and `labelDefaults` column maps of a table source.
	 *
	 * @param string               $name   Metric name (for messages).
	 * @param array<string, mixed> $source Raw source block.
	 *
	 * @return void
	 *
	 * @throws ObservabilityValidationException When either map is malformed.
	 */
	private static function validateLabelMaps(string $name, array $source): void {
		foreach (['labelMap', 'labelDefaults'] as $key) {
			self::validateColumnMap(label: sprintf('Metric "%s" %s', $name, $key), map: $source[$key] ?? null);
		}
	}//end validateLabelMaps()

	/**
	 * Validate an `appConfig` source block: it requires a non-empty `key`.
	 *
	 * @param string               $name   Metric name (for messages).
	 * @param array<string, mixed> $source Raw source block.
	 *
	 * @return void
	 *
	 * @throws ObservabilityValidationException When the key is missing.
	 */
	private static function validateAppConfigSource(string $name, array $source): void {
		if (self::isNonEmptyString(value: $source['key'] ?? null) === false) {
			throw new ObservabilityValidationException(sprintf('Metric "%s" appConfig source requires a "key".', $name));
		}
	}//end validateAppConfigSource()

	/**
	 * Validate a `groupBy` list of string field/column names.
	 *
	 * @param string               $name   Metric name.
	 * @param array<string, mixed> $source Source block.
	 *
	 * @return void
	 *
	 * @throws ObservabilityValidationException When malformed.
	 */
	private static function validateGroupBy(string $name, array $source): void {
		$fields = self::optionalArray(
			value: $source['groupBy'] ?? null,
			error: sprintf('Metric "%s" groupBy must be an array.', $name)
		);
		foreach ($fields as $field) {
			self::validateFieldName(label: sprintf('Metric "%s" groupBy', $name), field: $field);
		}
	}//end validateGroupBy()

	/**
	 * Validate a `filter` map of field => { operator: value } against the
	 * operator allowlist.
	 *
	 * @param string               $name   Metric name.
	 * @param array<string, mixed> $source Source block.
	 *
	 * @return void
	 *
	 * @throws ObservabilityValidationException When an operator is not allowlisted.
	 */
	private static function validateFilter(string $name, array $source): void {
		$filter = self::optionalArray(value: $source['filter'] ?? null, error: sprintf('Metric "%s" filter must be an object.', $name));
		foreach ($filter as $field => $ops) {
			self::validateFieldName(label: sprintf('Metric "%s" filter', $name), field: $field);
			if (is_array($ops) === false) {
				throw new ObservabilityValidationException(sprintf('Metric "%s" filter "%s" must map operators to values.', $name, $field));
			}

			self::validateFilterOperators(name: $name, ops: $ops);
		}
	}//end validateFilter()

	/**
	 * Validate that every operator of one filter field is allowlisted.
	 *
	 * @param string              $name Metric name.
	 * @param array<mixed, mixed> $ops  Operator => value map of one filter field.
	 *
	 * @return void
	 *
	 * @throws ObservabilityValidationException When an operator is not allowlisted.
	 */
	private static function validateFilterOperators(string $name, array $ops): void {
		foreach (array_keys($ops) as $operator) {
			if (in_array($operator, self::FILTER_OPERATORS, true) === false) {
				$shown = DescriptorValue::describe(value: $operator);
				$allowed = implode(', ', self::FILTER_OPERATORS);
				throw new ObservabilityValidationException(
					sprintf('Metric "%s" filter operator "%s" is not allowed (allowed: %s).', $name, $shown, $allowed)
				);
			}
		}
	}//end validateFilterOperators()

	/**
	 * Validate one `groupBy` or `filter` field name: `[a-zA-Z0-9_.]+`.
	 *
	 * @param string $label Message prefix, e.g. `Metric "x" groupBy`.
	 * @param mixed  $field The field name.
	 *
	 * @return void
	 *
	 * @throws ObservabilityValidationException When the name is invalid.
	 */
	private static function validateFieldName(string $label, mixed $field): void {
		if (is_string($field) === false || preg_match('/^[a-zA-Z0-9_.]+$/', $field) !== 1) {
			$shown = DescriptorValue::describe(value: $field);
			throw new ObservabilityValidationException(sprintf('%s field "%s" is invalid.', $label, $shown));
		}
	}//end validateFieldName()

	/**
	 * Validate a column => string map (labelMap / labelDefaults).
	 *
	 * @param string $label Message prefix, e.g. `Metric "x" labelMap`.
	 * @param mixed  $map   The map, or null when absent.
	 *
	 * @return void
	 *
	 * @throws ObservabilityValidationException When malformed.
	 */
	private static function validateColumnMap(string $label, mixed $map): void {
		foreach (self::optionalArray(value: $map, error: sprintf('%s must be an object.', $label)) as $column => $value) {
			if (is_string($column) === false || preg_match('/^[a-zA-Z0-9_]+$/', $column) !== 1) {
				$shown = DescriptorValue::describe(value: $column);
				throw new ObservabilityValidationException(sprintf('%s column "%s" is invalid.', $label, $shown));
			}

			if (is_scalar($value) === false) {
				throw new ObservabilityValidationException(sprintf('%s value for "%s" must be scalar.', $label, $column));
			}
		}
	}//end validateColumnMap()

	/**
	 * An optional array-valued source key: absent (null) reads as empty.
	 *
	 * @param mixed  $value The key's value, or null when absent.
	 * @param string $error The message to raise when the value is not an array.
	 *
	 * @return array<mixed, mixed> The array, or [] when absent.
	 *
	 * @throws ObservabilityValidationException When present but not an array.
	 */
	private static function optionalArray(mixed $value, string $error): array {
		if ($value === null) {
			return [];
		}

		if (is_array($value) === false) {
			throw new ObservabilityValidationException($error);
		}

		return $value;
	}//end optionalArray()

	/**
	 * Whether a value is a non-empty string.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True for a string other than ''.
	 */
	private static function isNonEmptyString(mixed $value): bool {
		return is_string($value) === true && $value !== '';
	}//end isNonEmptyString()
}//end class
