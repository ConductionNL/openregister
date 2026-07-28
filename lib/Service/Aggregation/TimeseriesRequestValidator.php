<?php

/**
 * TimeseriesRequestValidator
 *
 * Validates the query-parameter shape posted to the ad-hoc timeseries
 * aggregation endpoint (REST) and the equivalent GraphQL `groupBy`
 * argument. Returns a normalised array ready to feed into
 * AggregationQuery::create(), or throws InvalidArgumentException with a
 * client-safe message that maps to HTTP 400.
 *
 * Pulled into its own class so:
 *  - REST controller and GraphQL resolver share one allow-list /
 *    error vocabulary,
 *  - the validator is unit-testable in isolation (no controller boot,
 *    no GraphQL closure trampoline),
 *  - the validation rules are documented once in the spec and tested
 *    once at this layer.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Aggregation
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Schema;

/**
 * Validates the input shape of an ad-hoc timeseries aggregation request.
 */
class TimeseriesRequestValidator
{

    /**
     * Magic-table metadata columns that are allowed as bucketing /
     * groupBy fields even though they are not declared properties on
     * the schema. Mirrors the metadata prefix in MagicMapper.
     *
     * @var string[]
     */
    public const METADATA_FIELDS = ['_created', '_updated', '_deleted_at'];

    /**
     * Allowed interval names on the REST endpoint. Mapped to the
     * AggregationQuery::DATE_BUCKET_GAPS vocabulary in lower-case.
     *
     * @var array<string, string>
     */
    private const INTERVAL_MAP = [
        'MINUTE'  => 'minute',
        'HOUR'    => 'hour',
        'DAY'     => 'day',
        'WEEK'    => 'week',
        'MONTH'   => 'month',
        'QUARTER' => 'quarter',
        'YEAR'    => 'year',
    ];

    /**
     * Sub-day interval gaps (require `format: date-time` on the field).
     *
     * @var string[]
     */
    private const SUB_DAY_GAPS = ['minute', 'hour'];

    /**
     * Validate and normalise a request shape into an AggregationQuery.
     *
     * Input shape (all values strings as received from HTTP query
     * params; GraphQL resolver passes typed values but the shape is the
     * same):
     *  - field        (string, required)
     *  - interval     (string, optional — one of INTERVAL_MAP keys)
     *  - from         (string, required when interval set)
     *  - to           (string, required when interval set)
     *  - metric       (string, optional, default 'count')
     *  - metricField  (string, required when metric != count)
     *  - filter       (array<string, mixed>, optional)
     *  - cumulative   (bool, optional, default false — REQ-AGG-103; only
     *                  valid when `interval` is set)
     *
     * @param array<string, mixed> $input  The raw request shape.
     * @param Schema               $schema The schema being aggregated (for field allow-listing).
     *
     * @return AggregationQuery The validated query value object.
     *
     * @throws InvalidArgumentException When any input rule is violated.
     *                                  The message is client-safe and
     *                                  maps directly to a 400 body.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     *   Fail-fast validation chain — each branch is one independent
     *   guard returning a distinct client-facing error.
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     *   Each guard is short; the method length is the count of
     *   independent guards, not a structural concern.
     * @SuppressWarnings(PHPMD.ElseExpression)
     *   The else branch nulls metricField for count-metric requests —
     *   inlining it would obscure the contract that the value object
     *   expects null for count.
     * @SuppressWarnings(PHPMD.StaticAccess)
     *   AggregationQuery::create() is the canonical, validated factory
     *   for the value object.
     *
     * @spec openspec/specs/aggregations-backend-native/spec.md
     */
    public function validate(array $input, Schema $schema): AggregationQuery
    {
        $field = (string) ($input['field'] ?? '');
        if ($field === '') {
            throw new InvalidArgumentException('`field` is required');
        }

        $allowed = $this->allowedFields(schema: $schema);
        if (in_array($field, $allowed, true) === false) {
            throw new InvalidArgumentException(
                sprintf('`field` "%s" is not a declared property of the schema', $field)
            );
        }

        $metric      = strtolower((string) ($input['metric'] ?? 'count'));
        $metricField = ($input['metricField'] ?? null);
        if ($metric !== 'count') {
            if ($metricField === null || $metricField === '') {
                throw new InvalidArgumentException(
                    sprintf('`metricField` is required when metric is "%s"', $metric)
                );
            }

            if (in_array((string) $metricField, $allowed, true) === false) {
                throw new InvalidArgumentException(
                    sprintf('`metricField` "%s" is not a declared property of the schema', $metricField)
                );
            }
        } else {
            // For count, field on AggregationQuery is null (count is
            // record-level, not field-level).
            $metricField = null;
        }

        $rawInterval = ($input['interval'] ?? null);
        $dateBucket  = null;
        if ($rawInterval !== null && $rawInterval !== '') {
            $intervalKey = strtoupper((string) $rawInterval);
            if (isset(self::INTERVAL_MAP[$intervalKey]) === false) {
                throw new InvalidArgumentException(
                    sprintf(
                        '`interval` MUST be one of %s (got: %s)',
                        implode(', ', array_keys(self::INTERVAL_MAP)),
                        (string) $rawInterval
                    )
                );
            }

            $gap = self::INTERVAL_MAP[$intervalKey];

            // Sub-day gaps require a date-time field.
            if (in_array($gap, self::SUB_DAY_GAPS, true) === true) {
                $format = $this->fieldFormat(schema: $schema, field: $field);
                if ($format !== 'date-time') {
                    throw new InvalidArgumentException(
                        sprintf(
                            'sub-day interval "%s" requires a `date-time` field; "%s" has format "%s"',
                            $intervalKey,
                            $field,
                            $format ?? 'unspecified'
                        )
                    );
                }
            }

            $from = (string) ($input['from'] ?? '');
            $to   = (string) ($input['to'] ?? '');
            if ($from === '' || $to === '') {
                throw new InvalidArgumentException(
                    '`from` and `to` are required when `interval` is set'
                );
            }

            if (strtotime($from) === false || strtotime($to) === false) {
                throw new InvalidArgumentException(
                    '`from` and `to` MUST be parseable ISO-8601 datetimes'
                );
            }

            $dateBucket = [
                'field' => $field,
                'start' => $from,
                'end'   => $to,
                'gap'   => $gap,
            ];
        }//end if

        // Categorical groupBy fires when no interval was supplied (the
        // bucketing axis IS the field) OR when explicitly absent. For
        // interval-bucket requests we let dateBucket carry the field and
        // leave groupBy null — AggregationQuery::create() rejects the
        // combination as mutually exclusive.
        $groupBy = null;
        if ($dateBucket === null) {
            $groupBy = ['field' => $field];
        }

        $filter = (array) ($input['filter'] ?? []);

        $cumulative = $this->toBool(raw: $input['cumulative'] ?? false);
        if ($cumulative === true && $dateBucket === null) {
            throw new InvalidArgumentException(
                '`cumulative` requires `interval` (and `from`/`to`) to be set — it orders and accumulates over time buckets'
            );
        }

        return AggregationQuery::create(
            metric: $metric,
            field: $metricField,
            filter: $filter,
            groupBy: $groupBy,
            dateBucket: $dateBucket,
            cumulative: $cumulative
        );

    }//end validate()

    /**
     * Normalise a raw `cumulative` query-param value to a strict bool.
     *
     * HTTP query params arrive as strings (`'true'`, `'1'`, `'false'`,
     * `'0'`, `''`); the GraphQL resolver may pass an actual bool. Only
     * `true`/`'true'`/`'1'`/`1` are truthy — everything else (including
     * absent/`null`) is false, so an unrecognised value fails closed
     * rather than silently turning cumulative on.
     *
     * @param mixed $raw The raw `cumulative` input value.
     *
     * @return bool The normalised flag.
     */
    private function toBool(mixed $raw): bool
    {
        if (is_bool($raw) === true) {
            return $raw;
        }

        if (is_string($raw) === true) {
            return in_array(strtolower($raw), ['true', '1'], true);
        }

        if (is_int($raw) === true) {
            return ($raw === 1);
        }

        return false;

    }//end toBool()

    /**
     * Compute the allow-list of fields the request may reference: every
     * declared schema property + the magic-table metadata columns.
     *
     * @param Schema $schema The schema being aggregated.
     *
     * @return string[] Allow-listed field names.
     */
    private function allowedFields(Schema $schema): array
    {
        $properties = ($schema->getProperties() ?? []);
        $declared   = array_keys($properties);
        return array_merge($declared, self::METADATA_FIELDS);

    }//end allowedFields()

    /**
     * Look up the JSON-Schema `format` for a declared property.
     *
     * @param Schema $schema The schema.
     * @param string $field  The property name.
     *
     * @return string|null The format string, or null when the property
     *                     is a metadata column or has no format declared.
     */
    private function fieldFormat(Schema $schema, string $field): ?string
    {
        // Magic metadata cols are date-time-shaped by convention.
        if (in_array($field, self::METADATA_FIELDS, true) === true) {
            return 'date-time';
        }

        $properties = ($schema->getProperties() ?? []);
        $property   = ($properties[$field] ?? null);
        if (is_array($property) === false) {
            return null;
        }

        $format = ($property['format'] ?? null);
        if (is_string($format) === true) {
            return $format;
        }

        return null;

    }//end fieldFormat()
}//end class
