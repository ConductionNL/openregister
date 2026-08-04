<?php

/**
 * OpenRegister CalculationAnnotationValidator
 *
 * Schema-save validation for the `x-openregister-calculations` annotation.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation
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

namespace OCA\OpenRegister\Service\Calculation;

/**
 * Validates `x-openregister-calculations` annotation shape and references.
 *
 * Checks (per calculation):
 * - Spec is an object with `type` (string|integer|number|boolean|date) and `expression`.
 * - Optional `materialise` is a boolean.
 * - Every `prop` reference points to a property on the schema OR another
 *   calculation declared in this annotation.
 * - Every operator key is in the v1 vocabulary.
 *
 * Cross-calculation:
 * - Cycle detection across {prop:calcA, prop:calcB} dependency graph.
 * - `x-openregister-references` shape validation + `@ref.<name>` token recognition.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The validator mirrors the evaluator's
 *   operator vocabulary plus three reference families (@ref / @self / plain) plus the
 *   references-block shape checks; each branch is a distinct validation rule that cannot be
 *   collapsed without losing a specific, user-facing error message. Splitting into sub-validators
 *   would require a rule registry that is out of scope for this single-responsibility class.
 *
 * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-2
 * @spec openspec/changes/calc-engine-reference-lookup/tasks.md#task-3
 */
final class CalculationAnnotationValidator
{

    /**
     * Supported unit values for the dateDiff operator.
     */
    private const VALID_DATE_DIFF_UNITS = ['years', 'months', 'weeks', 'days', 'hours', 'minutes', 'seconds'];

    /**
     * Operator vocabulary recognised by the v1 calculation evaluator.
     */
    private const VALID_OPS = [
        'prop',
        'lit',
        'concat',
        'if',
        'not',
        'and',
        'or',
        '+',
        '-',
        '*',
        '/',
        '%',
        'eq',
        'ne',
        'lt',
        'lte',
        'gt',
        'gte',
        'now',
        'diffDays',
        'formatDate',
        'dateDiff',
        'dateAdd',
        'sequence',
        'max',
        'min',
        'coalesce',
        'abs',
        'round',
        'year',
        'monthsElapsed',
        'sha256',
    ];

    /**
     * Allowed `metric` values for an aggregate-reference declaration.
     */
    private const VALID_AGGREGATE_METRICS = ['count', 'sum', 'avg', 'min', 'max'];

    /**
     * Allowed `type` values for a calculation declaration.
     */
    private const VALID_TYPES = ['string', 'integer', 'number', 'boolean', 'date'];

    /**
     * Validate a schema's `x-openregister-calculations` annotation.
     *
     * @param array<string, mixed> $schema Full schema (must include `properties`).
     *
     * @return array<int, array{code: string, message: string}> Validation error list.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) The method collects three reference
     *   families (calc names, x-openregister-references, x-openregister-aggregate-refs), runs
     *   the per-calc shape + expression walk, and the cycle check in one pass; the steps share
     *   the accumulated name/dep/error state, so splitting them would thread several mutable
     *   accumulators through helpers for no readability gain.
     *
     * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-2
     */
    public function validate(array $schema): array
    {
        if (isset($schema['x-openregister-calculations']) === false) {
            return [];
        }

        $calcs = $schema['x-openregister-calculations'];
        if (is_array($calcs) === false || count($calcs) === 0) {
            return [
                [
                    'code'    => 'calculations-empty',
                    'message' => 'x-openregister-calculations must declare at least one calculation.',
                ],
            ];
        }

        $properties = ($schema['properties'] ?? []);
        $propKeys   = [];
        if (is_array($properties) === true) {
            $propKeys = array_keys($properties);
        }

        $calcNames = array_keys($calcs);
        $allRefs   = array_merge($propKeys, $calcNames);

        // Declared cross-object references (`x-openregister-references`) make
        // `@ref.<name>.<field>` prop tokens valid. Collect their names so the
        // walker accepts them, and validate the references block shape.
        $references    = ($schema['x-openregister-references'] ?? []);
        $referenceKeys = [];
        if (is_array($references) === true) {
            $referenceKeys = array_keys($references);
        }

        // Declared aggregate-references (`x-openregister-aggregate-refs`) make
        // `@aggregate.<name>` / `@aggregate.<name>.<field>` prop tokens valid.
        // Collect their names so the walker accepts them, and validate the
        // aggregate-refs block shape.
        $aggregateRefs    = ($schema['x-openregister-aggregate-refs'] ?? []);
        $aggregateRefKeys = [];
        if (is_array($aggregateRefs) === true) {
            $aggregateRefKeys = array_keys($aggregateRefs);
        }

        $errors = [];
        $deps   = [];

        $this->validateReferences(references: $references, propKeys: $propKeys, errors: $errors);
        $this->validateAggregateRefs(aggregateRefs: $aggregateRefs, errors: $errors);

        foreach ($calcs as $name => $spec) {
            if (is_string($name) === false || $name === '') {
                $errors[] = [
                    'code'    => 'calculation-bad-name',
                    'message' => 'Calculation names must be non-empty strings.',
                ];
                continue;
            }

            if (is_array($spec) === false) {
                $errors[] = [
                    'code'    => 'calculation-malformed',
                    'message' => sprintf('Calculation "%s" must be an object.', $name),
                ];
                continue;
            }

            $type = (string) ($spec['type'] ?? '');
            if (in_array($type, self::VALID_TYPES, true) === false) {
                $errors[] = [
                    'code'    => 'calculation-bad-type',
                    'message' => sprintf(
                        'Calculation "%s" type must be one of [%s].',
                        $name,
                        implode(', ', self::VALID_TYPES)
                    ),
                ];
            }

            if (isset($spec['expression']) === false) {
                $errors[] = [
                    'code'    => 'calculation-no-expression',
                    'message' => sprintf('Calculation "%s" requires an expression.', $name),
                ];
                continue;
            }

            $deps[$name] = [];
            $this->walk(
                expr: $spec['expression'],
                owner: $name,
                allRefs: $allRefs,
                referenceKeys: $referenceKeys,
                aggregateRefKeys: $aggregateRefKeys,
                errors: $errors,
                deps: $deps[$name]
            );
        }//end foreach

        $cycle = $this->findCycle(deps: $deps);
        if ($cycle !== null) {
            $errors[] = [
                'code'    => 'calculation-cycle',
                'message' => sprintf('Calculation cycle detected: %s.', implode(' -> ', $cycle)),
            ];
        }

        return $errors;
    }//end validate()

    /**
     * Recursively walk an expression AST collecting errors and dependencies.
     *
     * @param mixed                                            $expr             Sub-expression to walk.
     * @param string                                           $owner            Name of the calc currently being walked.
     * @param array<int, string>                               $allRefs          Available property + calc names.
     * @param array<int, string>                               $referenceKeys    Declared `x-openregister-references` names.
     * @param array<int, string>                               $aggregateRefKeys Declared `x-openregister-aggregate-refs` names.
     * @param array<int, array{code: string, message: string}> $errors           Mutable error accumulator.
     * @param array<int, string>                               $deps             Mutable list of calc deps for the current calc.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function walk(
        mixed $expr,
        string $owner,
        array $allRefs,
        array $referenceKeys,
        array $aggregateRefKeys,
        array &$errors,
        array &$deps
    ): void {
        if (is_array($expr) === false) {
            return;
            // Bare scalar literal.
        }

        if (count($expr) !== 1) {
            $errors[] = [
                'code'    => 'calculation-malformed-expr',
                'message' => sprintf('Calculation "%s": expression must be single-key.', $owner),
            ];
            return;
        }

        $op = (string) array_key_first($expr);
        if (in_array($op, self::VALID_OPS, true) === false) {
            $errors[] = [
                'code'    => 'calculation-unknown-op',
                'message' => sprintf('Calculation "%s": unknown operator "%s".', $owner, $op),
            ];
            return;
        }

        $args = $expr[$op];
        if ($op === 'prop') {
            $this->walkProp(
                args: $args,
                owner: $owner,
                allRefs: $allRefs,
                referenceKeys: $referenceKeys,
                aggregateRefKeys: $aggregateRefKeys,
                errors: $errors,
                deps: $deps
            );
            return;
        }

        // DateDiff uses a named-key dict {from, to, unit} rather than a positional array.
        if ($op === 'dateDiff') {
            $this->walkDateDiff(
                args: $args,
                owner: $owner,
                allRefs: $allRefs,
                referenceKeys: $referenceKeys,
                aggregateRefKeys: $aggregateRefKeys,
                errors: $errors,
                deps: $deps
            );
            return;
        }

        if (is_array($args) === false) {
            $this->walk(
                expr: $args,
                owner: $owner,
                allRefs: $allRefs,
                referenceKeys: $referenceKeys,
                aggregateRefKeys: $aggregateRefKeys,
                errors: $errors,
                deps: $deps
            );
            return;
        }

        foreach ($args as $sub) {
            $this->walk(
                expr: $sub,
                owner: $owner,
                allRefs: $allRefs,
                referenceKeys: $referenceKeys,
                aggregateRefKeys: $aggregateRefKeys,
                errors: $errors,
                deps: $deps
            );
        }
    }//end walk()

    /**
     * Validate a `prop` reference token and record calc-to-calc dependencies.
     *
     * Recognises four families: `@ref.<declared-reference>.<field>` (cross-object
     * reference injected by the listener), `@aggregate.<declared-aggregate>` (folded
     * aggregate injected by the listener), `@self.<system-field>` (object metadata
     * injected by the listener), and plain property / sibling-calculation names.
     *
     * @param mixed                                            $args             The prop operator's argument.
     * @param string                                           $owner            Name of the calc currently being walked.
     * @param array<int, string>                               $allRefs          Available property + calc names.
     * @param array<int, string>                               $referenceKeys    Declared `x-openregister-references` names.
     * @param array<int, string>                               $aggregateRefKeys Declared `x-openregister-aggregate-refs` names.
     * @param array<int, array{code: string, message: string}> $errors           Mutable error accumulator.
     * @param array<int, string>                               $deps             Mutable list of calc deps for the current calc.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) The method recognises four prop-token
     *   families (@ref, @aggregate, @self, plain), each with its own validity check and
     *   user-facing error message; the families are flat, mutually-exclusive early returns,
     *   so extracting each into a helper would only add indirection without reducing the rules.
     *
     * @spec openspec/changes/calc-engine-reference-lookup/tasks.md#task-3
     * @spec openspec/changes/calc-engine-aggregate-reference/tasks.md#task-4
     */
    private function walkProp(
        mixed $args,
        string $owner,
        array $allRefs,
        array $referenceKeys,
        array $aggregateRefKeys,
        array &$errors,
        array &$deps
    ): void {
        $name = '';
        if (is_string($args) === true) {
            $name = $args;
        } else if (is_array($args) === true) {
            $name = (string) ($args[0] ?? '');
        }

        if ($name === '') {
            $errors[] = [
                'code'    => 'calculation-prop-unknown',
                'message' => sprintf(
                    'Calculation "%s": prop "%s" is not a property or calculation.',
                    $owner,
                    $name
                ),
            ];
            return;
        }

        // `@ref.<declared-reference>.<field>` is allowed — the listener
        // pre-resolves declared references and injects them at evaluation
        // time. The reference name (the first dotted segment after @ref.)
        // must be declared in `x-openregister-references`. No dependency
        // tracking for @ref refs (they read other objects, not calcs).
        if (str_starts_with($name, '@ref.') === true) {
            $refName = explode('.', substr($name, 5))[0] ?? '';
            if (in_array($refName, $referenceKeys, true) === false) {
                $errors[] = [
                    'code'    => 'calculation-ref-unknown',
                    'message' => sprintf(
                        'Calculation "%s": @ref.%s is not a declared reference. Declared: %s.',
                        $owner,
                        $refName,
                        implode(', ', $referenceKeys)
                    ),
                ];
            }

            return;
        }

        // `@aggregate.<declared-aggregate>[.<groupKey>]` is allowed — the
        // listener pre-resolves declared aggregate-references and injects them
        // at evaluation time. The aggregate name (the first dotted segment
        // after @aggregate.) must be declared in `x-openregister-aggregate-refs`.
        // No dependency tracking (aggregates fold other objects, not calcs).
        if (str_starts_with($name, '@aggregate.') === true) {
            $aggName = explode('.', substr($name, 11))[0] ?? '';
            if (in_array($aggName, $aggregateRefKeys, true) === false) {
                $errors[] = [
                    'code'    => 'calculation-aggregate-unknown',
                    'message' => sprintf(
                        'Calculation "%s": @aggregate.%s is not a declared aggregate-reference. Declared: %s.',
                        $owner,
                        $aggName,
                        implode(', ', $aggregateRefKeys)
                    ),
                ];
            }

            return;
        }

        // `@self.<known-system-field>` is always allowed — the listener
        // injects @self metadata at evaluation time. No dependency
        // tracking for @self refs since they don't participate in
        // the calculation cycle graph.
        if (str_starts_with($name, '@self.') === true) {
            $sysField = substr($name, 6);
            $allowed  = ['id', 'uuid', 'register', 'schema', 'owner', 'created', 'updated'];
            if (in_array($sysField, $allowed, true) === false) {
                $errors[] = [
                    'code'    => 'calculation-self-unknown',
                    'message' => sprintf(
                        'Calculation "%s": @self.%s is not a known system field. Allowed: %s.',
                        $owner,
                        $sysField,
                        implode(', ', $allowed)
                    ),
                ];
            }

            return;
        }

        if (in_array($name, $allRefs, true) === false) {
            $errors[] = [
                'code'    => 'calculation-prop-unknown',
                'message' => sprintf(
                    'Calculation "%s": prop "%s" is not a property or calculation.',
                    $owner,
                    $name
                ),
            ];
            return;
        }

        $deps[] = $name;
    }//end walkProp()

    /**
     * Validate a `dateDiff` operator's named-key argument dict.
     *
     * Required keys: `from`, `to`, `unit`. Each is itself a sub-expression
     * (scalar literal or nested expression). The `unit` value, when a bare
     * string literal, is additionally checked against the allowed list.
     *
     * @param mixed                                            $args             The dateDiff argument value.
     * @param string                                           $owner            Name of the calc currently being walked.
     * @param array<int, string>                               $allRefs          Available property + calc names.
     * @param array<int, string>                               $referenceKeys    Declared `x-openregister-references` names.
     * @param array<int, string>                               $aggregateRefKeys Declared `x-openregister-aggregate-refs` names.
     * @param array<int, array{code: string, message: string}> $errors           Mutable error accumulator.
     * @param array<int, string>                               $deps             Mutable list of calc deps for the current calc.
     *
     * @return void
     */
    private function walkDateDiff(
        mixed $args,
        string $owner,
        array $allRefs,
        array $referenceKeys,
        array $aggregateRefKeys,
        array &$errors,
        array &$deps
    ): void {
        if (is_array($args) === false
            || array_key_exists('from', $args) === false
            || array_key_exists('to', $args) === false
            || array_key_exists('unit', $args) === false
        ) {
            $errors[] = [
                'code'    => 'calculation-dateDiff-missing-keys',
                'message' => sprintf(
                    'Calculation "%s": dateDiff requires keys: from, to, unit.',
                    $owner
                ),
            ];
            return;
        }

        // Walk from and to as sub-expressions so prop refs are validated.
        $this->walk(
            expr: $args['from'],
            owner: $owner,
            allRefs: $allRefs,
            referenceKeys: $referenceKeys,
            aggregateRefKeys: $aggregateRefKeys,
            errors: $errors,
            deps: $deps
        );
        $this->walk(
            expr: $args['to'],
            owner: $owner,
            allRefs: $allRefs,
            referenceKeys: $referenceKeys,
            aggregateRefKeys: $aggregateRefKeys,
            errors: $errors,
            deps: $deps
        );

        // Validate unit when it's a plain string literal (not a nested expression).
        $unit = $args['unit'];
        if (is_string($unit) === true && in_array($unit, self::VALID_DATE_DIFF_UNITS, true) === false) {
            $errors[] = [
                'code'    => 'calculation-dateDiff-invalid-unit',
                'message' => sprintf(
                    'Calculation "%s": dateDiff unit "%s" is invalid. Supported: %s.',
                    $owner,
                    $unit,
                    implode(', ', self::VALID_DATE_DIFF_UNITS)
                ),
            ];
        }
    }//end walkDateDiff()

    /**
     * Validate the optional `x-openregister-references` annotation shape.
     *
     * Each reference must declare a non-empty `schema` and a `mode` of
     * `relatedObject` or `lookup`. A `relatedObject` reference requires a
     * `field`; a `lookup` reference requires a `filters` map.
     *
     * @param mixed                                            $references The references map.
     * @param array<int, string>                               $propKeys   Schema property names (for field checks).
     * @param array<int, array{code: string, message: string}> $errors     Mutable error accumulator.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     *
     * @spec openspec/changes/calc-engine-reference-lookup/tasks.md#task-3
     */
    private function validateReferences(mixed $references, array $propKeys, array &$errors): void
    {
        if (is_array($references) === false || count($references) === 0) {
            return;
        }

        foreach ($references as $name => $spec) {
            if (is_string($name) === false || $name === '' || is_array($spec) === false) {
                $errors[] = [
                    'code'    => 'reference-malformed',
                    'message' => sprintf('Reference "%s" must be a named object.', (string) $name),
                ];
                continue;
            }

            $schema = (string) ($spec['schema'] ?? '');
            if ($schema === '') {
                $errors[] = [
                    'code'    => 'reference-no-schema',
                    'message' => sprintf('Reference "%s" requires a target schema.', $name),
                ];
            }

            $mode = (string) ($spec['mode'] ?? '');
            if ($mode !== 'relatedObject' && $mode !== 'lookup') {
                $errors[] = [
                    'code'    => 'reference-bad-mode',
                    'message' => sprintf(
                        'Reference "%s" mode must be relatedObject or lookup.',
                        $name
                    ),
                ];
                continue;
            }

            if ($mode === 'relatedObject') {
                $field = (string) ($spec['field'] ?? '');
                if ($field === '') {
                    $errors[] = [
                        'code'    => 'reference-no-field',
                        'message' => sprintf('Reference "%s" (relatedObject) requires a field.', $name),
                    ];
                } else if (in_array($field, $propKeys, true) === false) {
                    $errors[] = [
                        'code'    => 'reference-field-unknown',
                        'message' => sprintf(
                            'Reference "%s": field "%s" is not a schema property.',
                            $name,
                            $field
                        ),
                    ];
                }
            }

            if ($mode === 'lookup' && is_array($spec['filters'] ?? null) === false) {
                $errors[] = [
                    'code'    => 'reference-no-filters',
                    'message' => sprintf('Reference "%s" (lookup) requires a filters map.', $name),
                ];
            }
        }//end foreach
    }//end validateReferences()

    /**
     * Validate the optional `x-openregister-aggregate-refs` annotation shape.
     *
     * Each aggregate-reference must declare a non-empty `schema` and a `metric`
     * of count/sum/avg/min/max. A non-`count` metric additionally requires a
     * `field` (matching `AggregationQuery::create()`'s own fail-fast contract).
     *
     * @param mixed                                            $aggregateRefs The aggregate-references map.
     * @param array<int, array{code: string, message: string}> $errors        Mutable error accumulator.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     *
     * @spec openspec/changes/calc-engine-aggregate-reference/tasks.md#task-4
     */
    private function validateAggregateRefs(mixed $aggregateRefs, array &$errors): void
    {
        if (is_array($aggregateRefs) === false || count($aggregateRefs) === 0) {
            return;
        }

        foreach ($aggregateRefs as $name => $spec) {
            if (is_string($name) === false || $name === '' || is_array($spec) === false) {
                $errors[] = [
                    'code'    => 'aggregate-ref-malformed',
                    'message' => sprintf('Aggregate-reference "%s" must be a named object.', (string) $name),
                ];
                continue;
            }

            $schema = (string) ($spec['schema'] ?? '');
            if ($schema === '') {
                $errors[] = [
                    'code'    => 'aggregate-ref-no-schema',
                    'message' => sprintf('Aggregate-reference "%s" requires a target schema.', $name),
                ];
            }

            $metric = (string) ($spec['metric'] ?? '');
            if (in_array($metric, self::VALID_AGGREGATE_METRICS, true) === false) {
                $errors[] = [
                    'code'    => 'aggregate-ref-bad-metric',
                    'message' => sprintf(
                        'Aggregate-reference "%s" metric must be one of [%s].',
                        $name,
                        implode(', ', self::VALID_AGGREGATE_METRICS)
                    ),
                ];
                continue;
            }

            if ($metric !== 'count' && (string) ($spec['field'] ?? '') === '') {
                $errors[] = [
                    'code'    => 'aggregate-ref-no-field',
                    'message' => sprintf(
                        'Aggregate-reference "%s" metric "%s" requires a field.',
                        $name,
                        $metric
                    ),
                ];
            }
        }//end foreach
    }//end validateAggregateRefs()

    /**
     * Find a cycle in the calculation dependency graph using DFS colouring.
     *
     * @param array<string, array<int, string>> $deps Dependency map.
     *
     * @return array<int, string>|null A cycle path if found, else null.
     *
     * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-2
     */
    private function findCycle(array $deps): ?array
    {
        $colour = [];
        $stack  = [];
        $path   = null;

        $visit = function (string $node) use (&$visit, &$colour, &$stack, &$deps, &$path) {
            if ($path !== null) {
                return;
            }

            if (($colour[$node] ?? 0) === 1) {
                $idx = array_search($node, $stack, true);
                if ($idx !== false) {
                    $path   = array_slice($stack, $idx);
                    $path[] = $node;
                }

                return;
            }

            if (($colour[$node] ?? 0) === 2) {
                return;
            }

            $colour[$node] = 1;
            $stack[]       = $node;
            foreach (($deps[$node] ?? []) as $next) {
                if (isset($deps[$next]) === true) {
                    // Only follow calc-to-calc edges.
                    $visit($next);
                }
            }

            array_pop($stack);
            $colour[$node] = 2;
        };

        foreach (array_keys($deps) as $name) {
            $visit($name);
            if ($path !== null) {
                return $path;
            }
        }

        return null;
    }//end findCycle()
}//end class
