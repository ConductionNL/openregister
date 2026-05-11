<?php

/**
 * OpenRegister AggregationAnnotationValidator
 *
 * Schema-save validation for the `x-openregister-aggregations` annotation.
 * Returns a list of errors; empty = valid.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

/**
 * Validates the `x-openregister-aggregations` schema annotation shape.
 *
 * Each aggregation maps a name → spec object.  Two DSL variants are supported:
 *
 * Intra-schema (legacy + new alias):
 *   { metric|select, field?, filter|where?, groupBy? }
 *   - `metric`/`select` MUST be one of count|sum|avg|min|max|count_distinct.
 *   - `field` is REQUIRED for sum|avg|min|max|count_distinct, MUST exist on the schema.
 *   - `groupBy.field` (when present) MUST exist on the schema.
 *   - `filter`/`where` is a flat map of field → value-or-operator-shape.
 *
 * Cross-schema (new):
 *   { from, metric|select?, field?, where|filter?, groupBy? }
 *   - `from` names a foreign schema slug.
 *   - `metric`/`select` defaults to `count` when omitted.
 *   - `where`/`filter` values may contain `@self.<field>` parent-references.
 *   - Field existence is **not** validated against the host schema's properties
 *     (the target schema is not available at annotation-save time).
 */
final class AggregationAnnotationValidator
{

    private const VALID_METRICS = ['count', 'sum', 'avg', 'min', 'max', 'count_distinct'];

    private const REQUIRES_FIELD = ['sum', 'avg', 'min', 'max', 'count_distinct'];

    /**
     * Validate the `x-openregister-aggregations` annotation on a schema.
     *
     * Cross-schema specs (those with a `from` key) are validated to the
     * extent possible without loading the target schema:
     *   - `from` must be a non-empty string.
     *   - `metric`/`select` must be a known metric when present.
     *   - `where`/`filter` must be a map when present.
     *   - Field existence is skipped (target schema not available here).
     *
     * @param array<string, mixed> $schema Full schema definition (must include `properties`).
     *
     * @return array<int, array{code: string, message: string}> Validation error list.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function validate(array $schema): array
    {
        if (isset($schema['x-openregister-aggregations']) === false) {
            return [];
        }

        $aggregations = $schema['x-openregister-aggregations'];
        if (is_array($aggregations) === false || count($aggregations) === 0) {
            return [
                [
                    'code'    => 'aggregations-empty',
                    'message' => 'x-openregister-aggregations must declare at least one aggregation.',
                ],
            ];
        }

        $properties = ($schema['properties'] ?? []);
        $propKeys   = is_array($properties) === true ? array_keys($properties) : [];

        $errors = [];
        foreach ($aggregations as $name => $spec) {
            if (is_string($name) === false || $name === '') {
                $errors[] = [
                    'code'    => 'aggregation-bad-name',
                    'message' => 'Aggregation names must be non-empty strings.',
                ];
                continue;
            }

            if (is_array($spec) === false) {
                $errors[] = [
                    'code'    => 'aggregation-malformed',
                    'message' => sprintf('Aggregation "%s" must be an object.', $name),
                ];
                continue;
            }

            // Cross-schema aggregation: lighter validation (no property check).
            $fromRef = ($spec['from'] ?? null);
            if ($fromRef !== null) {
                $errors = array_merge(
                    $errors,
                    $this->validateCrossSchemaSpec(name: $name, spec: $spec)
                );
                continue;
            }

            // Intra-schema aggregation: full property-existence checks.
            // Support `select` as alias for `metric`.
            $metric = (string) ($spec['metric'] ?? $spec['select'] ?? '');
            if (in_array($metric, self::VALID_METRICS, true) === false) {
                $errors[] = [
                    'code'    => 'aggregation-bad-metric',
                    'message' => sprintf(
                        'Aggregation "%s" metric "%s" is not in [%s].',
                        $name,
                        $metric,
                        implode(', ', self::VALID_METRICS)
                    ),
                ];
                continue;
            }

            if (in_array($metric, self::REQUIRES_FIELD, true) === true) {
                $field = (string) ($spec['field'] ?? '');
                if ($field === '') {
                    $errors[] = [
                        'code'    => 'aggregation-field-missing',
                        'message' => sprintf('Aggregation "%s" with metric "%s" requires a field.', $name, $metric),
                    ];
                } else if (in_array($field, $propKeys, true) === false) {
                    $errors[] = [
                        'code'    => 'aggregation-field-not-in-schema',
                        'message' => sprintf(
                            'Aggregation "%s" field "%s" is not declared in the schema properties.',
                            $name,
                            $field
                        ),
                    ];
                }
            }

            // Support `where` as alias for `filter`.
            $filter = ($spec['filter'] ?? $spec['where'] ?? null);
            if ($filter !== null && is_array($filter) === false) {
                $errors[] = [
                    'code'    => 'aggregation-filter-malformed',
                    'message' => sprintf('Aggregation "%s" filter/where must be a map.', $name),
                ];
            } else if (is_array($filter) === true) {
                foreach (array_keys($filter) as $filterField) {
                    if (in_array((string) $filterField, $propKeys, true) === false) {
                        $errors[] = [
                            'code'    => 'aggregation-filter-field-unknown',
                            'message' => sprintf(
                                'Aggregation "%s" filter references unknown field "%s".',
                                $name,
                                (string) $filterField
                            ),
                        ];
                    }
                }
            }//end if

            $groupBy = ($spec['groupBy'] ?? null);
            if ($groupBy !== null) {
                if (is_array($groupBy) === false || isset($groupBy['field']) === false) {
                    $errors[] = [
                        'code'    => 'aggregation-groupby-malformed',
                        'message' => sprintf('Aggregation "%s" groupBy must be {field, bucket?}.', $name),
                    ];
                } else if (in_array((string) $groupBy['field'], $propKeys, true) === false) {
                    $errors[] = [
                        'code'    => 'aggregation-groupby-field-unknown',
                        'message' => sprintf(
                            'Aggregation "%s" groupBy.field "%s" is not declared in the schema properties.',
                            $name,
                            (string) $groupBy['field']
                        ),
                    ];
                }
            }
        }//end foreach

        return $errors;
    }//end validate()

    /**
     * Validate a cross-schema aggregation spec (`from` key present).
     *
     * @param string               $name Aggregation name (for error messages).
     * @param array<string, mixed> $spec The raw spec object.
     *
     * @return array<int, array{code: string, message: string}> Error list (empty = valid).
     */
    private function validateCrossSchemaSpec(string $name, array $spec): array
    {
        $errors = [];

        $from = ($spec['from'] ?? null);
        if (is_string($from) === false || $from === '') {
            $errors[] = [
                'code'    => 'aggregation-from-empty',
                'message' => sprintf('Cross-schema aggregation "%s" must have a non-empty `from` string.', $name),
            ];
        }

        // `metric`/`select` defaults to `count` when omitted — only reject unknown non-empty values.
        $rawMetric = ($spec['metric'] ?? $spec['select'] ?? null);
        if ($rawMetric !== null) {
            $metric = (string) $rawMetric;
            if (in_array($metric, self::VALID_METRICS, true) === false) {
                $errors[] = [
                    'code'    => 'aggregation-bad-metric',
                    'message' => sprintf(
                        'Cross-schema aggregation "%s" metric "%s" is not in [%s].',
                        $name,
                        $metric,
                        implode(', ', self::VALID_METRICS)
                    ),
                ];
            }
        }

        // The where/filter clause must be a map when present.
        $filter = ($spec['where'] ?? $spec['filter'] ?? null);
        if ($filter !== null && is_array($filter) === false) {
            $errors[] = [
                'code'    => 'aggregation-filter-malformed',
                'message' => sprintf('Cross-schema aggregation "%s" where/filter must be a map.', $name),
            ];
        }

        return $errors;
    }//end validateCrossSchemaSpec()
}//end class
