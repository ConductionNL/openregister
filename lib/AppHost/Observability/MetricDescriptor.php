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
 */
final class MetricDescriptor
{
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
     * @param string               $name     Metric name (without the `{app}_` prefix).
     * @param string               $type     Prometheus type (gauge|counter).
     * @param string               $kind     Source kind (self::KINDS).
     * @param array<string, mixed> $source   Raw validated source params.
     * @param string|null          $help     HELP text.
     * @param int                  $cacheTtl Per-metric cache TTL in seconds (0 = no cache).
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $kind,
        public readonly array $source,
        public readonly ?string $help=null,
        public readonly int $cacheTtl=0
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
     */
    public static function fromArray(array $raw): self
    {
        $name = $raw['name'] ?? null;
        if (is_string($name) === false || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name) !== 1) {
            throw new ObservabilityValidationException('Metric "name" must be a valid Prometheus metric name.');
        }

        $type = $raw['type'] ?? 'gauge';
        if (is_string($type) === false || in_array($type, self::METRIC_TYPES, true) === false) {
            throw new ObservabilityValidationException(
                sprintf('Invalid metric type "%s" (allowed: %s).', is_scalar($type) === true ? (string) $type : gettype($type), implode(', ', self::METRIC_TYPES))
            );
        }

        $source = $raw['source'] ?? null;
        if (is_array($source) === false) {
            throw new ObservabilityValidationException(sprintf('Metric "%s" requires a "source" object.', $name));
        }

        $kind = $source['kind'] ?? null;
        if (is_string($kind) === false || in_array($kind, self::KINDS, true) === false) {
            throw new ObservabilityValidationException(
                sprintf('Unknown metric source kind "%s" (allowed: %s).', is_scalar($kind) === true ? (string) $kind : gettype($kind), implode(', ', self::KINDS))
            );
        }

        self::validateSource(name: $name, kind: $kind, source: $source);

        $cacheTtl = $raw['cacheTtl'] ?? 0;
        if (is_int($cacheTtl) === false || $cacheTtl < 0) {
            throw new ObservabilityValidationException(sprintf('Metric "%s" cacheTtl must be a non-negative integer.', $name));
        }

        $help = $raw['help'] ?? null;
        if ($help !== null && is_string($help) === false) {
            throw new ObservabilityValidationException(sprintf('Metric "%s" help must be a string.', $name));
        }

        return new self(
            name: $name,
            type: $type,
            kind: $kind,
            source: $source,
            help: $help,
            cacheTtl: $cacheTtl
        );
    }//end fromArray()

    /**
     * Validate a source block per its kind.
     *
     * @param string               $name   Metric name (for messages).
     * @param string               $kind   Source kind.
     * @param array<string, mixed> $source Raw source block.
     *
     * @return void
     *
     * @throws ObservabilityValidationException When the source is malformed.
     */
    private static function validateSource(string $name, string $kind, array $source): void
    {
        switch ($kind) {
            case 'objectCount':
            case 'objectSum':
                if (isset($source['schema']) === false || is_string($source['schema']) === false || $source['schema'] === '') {
                    throw new ObservabilityValidationException(sprintf('Metric "%s" %s source requires a "schema" slug.', $name, $kind));
                }

                if (isset($source['register']) === true && is_string($source['register']) === false) {
                    throw new ObservabilityValidationException(sprintf('Metric "%s" "register" must be a slug string.', $name));
                }

                if ($kind === 'objectSum' && (isset($source['field']) === false || is_string($source['field']) === false || $source['field'] === '')) {
                    throw new ObservabilityValidationException(sprintf('Metric "%s" objectSum source requires a numeric "field".', $name));
                }

                self::validateGroupBy(name: $name, source: $source);
                self::validateFilter(name: $name, source: $source);
                break;

            case 'tableCount':
                $table = $source['table'] ?? null;
                if (is_string($table) === false || preg_match(self::TABLE_REGEX, $table) !== 1) {
                    throw new ObservabilityValidationException(
                        sprintf('Metric "%s" tableCount "table" must match %s (got "%s").', $name, self::TABLE_REGEX, is_scalar($table) === true ? (string) $table : gettype($table))
                    );
                }

                self::validateGroupBy(name: $name, source: $source);
                self::validateFilter(name: $name, source: $source);
                self::validateColumnMap(name: $name, key: 'labelMap', source: $source);
                self::validateColumnMap(name: $name, key: 'labelDefaults', source: $source);
                break;

            case 'appConfig':
                if (isset($source['key']) === false || is_string($source['key']) === false || $source['key'] === '') {
                    throw new ObservabilityValidationException(sprintf('Metric "%s" appConfig source requires a "key".', $name));
                }
                break;

            case 'provider':
                // No parameters; resolution happens via the registered IMetricsProvider alias.
                break;
        }//end switch
    }//end validateSource()

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
    private static function validateGroupBy(string $name, array $source): void
    {
        if (isset($source['groupBy']) === false) {
            return;
        }

        if (is_array($source['groupBy']) === false) {
            throw new ObservabilityValidationException(sprintf('Metric "%s" groupBy must be an array.', $name));
        }

        foreach ($source['groupBy'] as $field) {
            if (is_string($field) === false || preg_match('/^[a-zA-Z0-9_.]+$/', $field) !== 1) {
                throw new ObservabilityValidationException(sprintf('Metric "%s" groupBy field "%s" is invalid.', $name, is_scalar($field) === true ? (string) $field : gettype($field)));
            }
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
    private static function validateFilter(string $name, array $source): void
    {
        if (isset($source['filter']) === false) {
            return;
        }

        if (is_array($source['filter']) === false) {
            throw new ObservabilityValidationException(sprintf('Metric "%s" filter must be an object.', $name));
        }

        foreach ($source['filter'] as $field => $ops) {
            if (is_string($field) === false || preg_match('/^[a-zA-Z0-9_.]+$/', $field) !== 1) {
                throw new ObservabilityValidationException(sprintf('Metric "%s" filter field "%s" is invalid.', $name, is_scalar($field) === true ? (string) $field : gettype($field)));
            }

            if (is_array($ops) === false) {
                throw new ObservabilityValidationException(sprintf('Metric "%s" filter "%s" must map operators to values.', $name, $field));
            }

            foreach (array_keys($ops) as $operator) {
                if (in_array($operator, self::FILTER_OPERATORS, true) === false) {
                    throw new ObservabilityValidationException(
                        sprintf('Metric "%s" filter operator "%s" is not allowed (allowed: %s).', $name, is_scalar($operator) === true ? (string) $operator : gettype($operator), implode(', ', self::FILTER_OPERATORS))
                    );
                }
            }
        }//end foreach
    }//end validateFilter()

    /**
     * Validate a column => string map (labelMap / labelDefaults).
     *
     * @param string               $name   Metric name.
     * @param string               $key    Source key.
     * @param array<string, mixed> $source Source block.
     *
     * @return void
     *
     * @throws ObservabilityValidationException When malformed.
     */
    private static function validateColumnMap(string $name, string $key, array $source): void
    {
        if (isset($source[$key]) === false) {
            return;
        }

        if (is_array($source[$key]) === false) {
            throw new ObservabilityValidationException(sprintf('Metric "%s" %s must be an object.', $name, $key));
        }

        foreach ($source[$key] as $column => $value) {
            if (is_string($column) === false || preg_match('/^[a-zA-Z0-9_]+$/', $column) !== 1) {
                throw new ObservabilityValidationException(sprintf('Metric "%s" %s column "%s" is invalid.', $name, $key, is_scalar($column) === true ? (string) $column : gettype($column)));
            }

            if (is_scalar($value) === false) {
                throw new ObservabilityValidationException(sprintf('Metric "%s" %s value for "%s" must be scalar.', $name, $key, $column));
            }
        }
    }//end validateColumnMap()
}//end class
