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
 * Each aggregation maps a name → { metric, field?, filter?, groupBy? }.
 *
 * - `metric` MUST be one of count|sum|avg|min|max|count_distinct.
 * - `field` is REQUIRED for sum|avg|min|max|count_distinct, MUST exist on the schema.
 * - `groupBy.field` (when present) MUST exist on the schema.
 * - `filter` is a flat map of field → value-or-operator-shape; every field MUST exist on the schema.
 */
final class AggregationAnnotationValidator
{

    private const VALID_METRICS = ['count', 'sum', 'avg', 'min', 'max', 'count_distinct'];

    private const REQUIRES_FIELD = ['sum', 'avg', 'min', 'max', 'count_distinct'];

    /**
     * Validate the `x-openregister-aggregations` annotation on a schema.
     *
     * @param array<string, mixed> $schema Full schema definition (must include `properties`).
     *
     * @return array<int, array{code: string, message: string}> Validation error list.
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

            $metric = (string) ($spec['metric'] ?? '');
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

            $filter = ($spec['filter'] ?? null);
            if ($filter !== null) {
                if (is_array($filter) === false) {
                    $errors[] = [
                        'code'    => 'aggregation-filter-malformed',
                        'message' => sprintf('Aggregation "%s" filter must be a map.', $name),
                    ];
                } else {
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
}//end class
