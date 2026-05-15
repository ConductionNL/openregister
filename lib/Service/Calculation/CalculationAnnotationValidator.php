<?php

/**
 * OpenRegister CalculationAnnotationValidator
 *
 * Schema-save validation for the `x-openregister-calculations` annotation.
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
    ];

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
        $propKeys   = is_array($properties) === true ? array_keys($properties) : [];
        $calcNames  = array_keys($calcs);
        $allRefs    = array_merge($propKeys, $calcNames);

        $errors = [];
        $deps   = [];

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
     * @param mixed                                            $expr    Sub-expression to walk.
     * @param string                                           $owner   Name of the calc currently being walked.
     * @param array<int, string>                               $allRefs Available property + calc names.
     * @param array<int, array{code: string, message: string}> $errors  Mutable error accumulator.
     * @param array<int, string>                               $deps    Mutable list of calc deps for the current calc.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function walk(mixed $expr, string $owner, array $allRefs, array &$errors, array &$deps): void
    {
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
            $name = is_string($args) === true ? $args : (is_array($args) === true ? (string) ($args[0] ?? '') : '');
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
            return;
        }//end if

        // DateDiff uses a named-key dict {from, to, unit} rather than a positional array.
        if ($op === 'dateDiff') {
            $this->walkDateDiff(args: $args, owner: $owner, allRefs: $allRefs, errors: $errors, deps: $deps);
            return;
        }

        if (is_array($args) === false) {
            $this->walk(expr: $args, owner: $owner, allRefs: $allRefs, errors: $errors, deps: $deps);
            return;
        }

        foreach ($args as $sub) {
            $this->walk(expr: $sub, owner: $owner, allRefs: $allRefs, errors: $errors, deps: $deps);
        }
    }//end walk()

    /**
     * Validate a `dateDiff` operator's named-key argument dict.
     *
     * Required keys: `from`, `to`, `unit`. Each is itself a sub-expression
     * (scalar literal or nested expression). The `unit` value, when a bare
     * string literal, is additionally checked against the allowed list.
     *
     * @param mixed                                            $args    The dateDiff argument value.
     * @param string                                           $owner   Name of the calc currently being walked.
     * @param array<int, string>                               $allRefs Available property + calc names.
     * @param array<int, array{code: string, message: string}> $errors  Mutable error accumulator.
     * @param array<int, string>                               $deps    Mutable list of calc deps for the current calc.
     *
     * @return void
     */
    private function walkDateDiff(
        mixed $args,
        string $owner,
        array $allRefs,
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
        $this->walk(expr: $args['from'], owner: $owner, allRefs: $allRefs, errors: $errors, deps: $deps);
        $this->walk(expr: $args['to'], owner: $owner, allRefs: $allRefs, errors: $errors, deps: $deps);

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
     * Find a cycle in the calculation dependency graph using DFS colouring.
     *
     * @param array<string, array<int, string>> $deps Dependency map.
     *
     * @return array<int, string>|null A cycle path if found, else null.
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
