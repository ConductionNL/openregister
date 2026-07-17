<?php

/**
 * OpenRegister LifecycleAnnotationValidator
 *
 * Validates `x-openregister-lifecycle` schema annotations at schema-save time.
 * Returns a list of validation error messages — empty list = valid.
 *
 * Per ADR-024 (hydra#202), schemas declare state machines via this annotation;
 * the implementation is in `lifecycle-annotation` change directory.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
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

namespace OCA\OpenRegister\Service\Lifecycle;

/**
 * Pure validation logic for the `x-openregister-lifecycle` annotation.
 *
 * Hooked into the schema-save path in SchemaService (or its caller). Errors
 * map to HTTP 422 responses as schema-save failures.
 */
final class LifecycleAnnotationValidator
{
    /**
     * Validate the annotation block on a schema definition.
     *
     * @param array<string, mixed> $schema Full schema definition (top-level shape — must include `properties`).
     *
     * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     * @spec openspec/changes/fk-graph-lifecycle-transitions/specs/object-lifecycle/spec.md
     */
    public function validate(array $schema): array
    {
        if (isset($schema['x-openregister-lifecycle']) === false) {
            return [];
        }

        $annotation = $schema['x-openregister-lifecycle'];
        $errors     = [];

        // `property` is an additive alias for `field`; normalise it up-front so
        // the rest of validation (and the runtime listener) sees a single key.
        if (isset($annotation['field']) === false && isset($annotation['property']) === true) {
            $annotation['field'] = $annotation['property'];
        }

        // Graph mode: when a non-empty `graph` block is declared, the lifecycle
        // field is a `$ref` with no enum, so shape-check the graph block instead
        // of the static `transitions`/enum contract. Static-only schemas fall
        // straight through to the unchanged validation below (no regression).
        if (isset($annotation['graph']) === true
            && is_array($annotation['graph']) === true
            && $annotation['graph'] !== []
        ) {
            return $this->validateGraphMode(annotation: $annotation, schema: $schema);
        }

        // Required top-level fields.
        foreach (['field', 'initial', 'transitions'] as $required) {
            if (isset($annotation[$required]) === false) {
                $errors[] = [
                    'code'    => 'lifecycle-missing-key',
                    'message' => sprintf('x-openregister-lifecycle is missing required key "%s".', $required),
                ];
            }
        }

        if (count($errors) > 0) {
            return $errors;
        }

        $field       = (string) $annotation['field'];
        $initial     = (string) $annotation['initial'];
        $transitions = $annotation['transitions'];
        $final       = ($annotation['final'] ?? []);

        // Field must exist on the schema.
        $properties = ($schema['properties'] ?? []);
        if (isset($properties[$field]) === false) {
            $errors[] = [
                'code'    => 'lifecycle-field-missing',
                'message' => sprintf('x-openregister-lifecycle.field "%s" is not declared in `properties`.', $field),
            ];
            // Without the field, other checks can't proceed meaningfully.
            return $errors;
        }

        // Field must be a string with an enum constraint.
        $fieldDef = $properties[$field];
        if (($fieldDef['type'] ?? null) !== 'string') {
            $errors[] = [
                'code'    => 'lifecycle-field-not-string',
                'message' => sprintf('x-openregister-lifecycle.field "%s" must be type "string".', $field),
            ];
        }

        $enum = ($fieldDef['enum'] ?? null);
        if (is_array($enum) === false || count($enum) === 0) {
            $errors[] = [
                'code'    => 'lifecycle-field-no-enum',
                'message' => sprintf('x-openregister-lifecycle.field "%s" must declare an `enum` of allowed values.', $field),
            ];
            return $errors;
        }

        $enumSet = array_flip($enum);

        // Initial value must be in the enum.
        if (isset($enumSet[$initial]) === false) {
            $errors[] = [
                'code'    => 'lifecycle-initial-not-in-enum',
                'message' => sprintf('x-openregister-lifecycle.initial "%s" is not in the field\'s enum.', $initial),
            ];
        }

        // Final values (if declared) must be in the enum.
        if (is_array($final) === true) {
            foreach ($final as $finalState) {
                if (isset($enumSet[(string) $finalState]) === false) {
                    $errors[] = [
                        'code'    => 'lifecycle-final-not-in-enum',
                        'message' => sprintf('x-openregister-lifecycle.final value "%s" is not in the field\'s enum.', $finalState),
                    ];
                }
            }
        }

        // Transitions must be a non-empty map.
        if (is_array($transitions) === false || count($transitions) === 0) {
            $errors[] = [
                'code'    => 'lifecycle-transitions-empty',
                'message' => 'x-openregister-lifecycle.transitions must declare at least one action.',
            ];
            return $errors;
        }

        foreach ($transitions as $action => $spec) {
            if (is_array($spec) === false) {
                $errors[] = [
                    'code'    => 'lifecycle-transition-malformed',
                    'message' => sprintf('Transition "%s" must be an object with `from` and `to`.', (string) $action),
                ];
                continue;
            }

            // From: required, a single state string or an array of states, all
            // in the enum. A string is coerced to a one-element list.
            $from = ($spec['from'] ?? null);
            if (is_string($from) === true && $from !== '') {
                $from = [$from];
            }

            $fromOk = (is_array($from) === true && count($from) > 0);
            if ($fromOk === false) {
                $errors[] = [
                    'code'    => 'lifecycle-from-missing',
                    'message' => sprintf('Transition "%s" must declare a non-empty `from` array.', (string) $action),
                ];
            }

            $fromIterable = [];
            if ($fromOk === true) {
                $fromIterable = $from;
            }

            foreach ($fromIterable as $fromState) {
                if (isset($enumSet[(string) $fromState]) === false) {
                    $errors[] = [
                        'code'    => 'lifecycle-from-not-in-enum',
                        'message' => sprintf(
                            'Transition "%s" lists "from" state "%s" which is not in the field\'s enum.',
                            (string) $action,
                            (string) $fromState
                        ),
                    ];
                }
            }

            // To: required string in the enum.
            $to = ($spec['to'] ?? null);
            if (is_string($to) === false || $to === '') {
                $errors[] = [
                    'code'    => 'lifecycle-to-missing',
                    'message' => sprintf('Transition "%s" must declare a string `to` value.', (string) $action),
                ];
            } else if (isset($enumSet[$to]) === false) {
                $errors[] = [
                    'code'    => 'lifecycle-to-not-in-enum',
                    'message' => sprintf(
                        'Transition "%s" `to` value "%s" is not in the field\'s enum.',
                        (string) $action,
                        $to
                    ),
                ];
            }

            // Optional `requires` — must be a non-empty string when present.
            // We don't try to resolve the DI tag at validation time; that's
            // an install-time concern (warning) and a first-invocation
            // concern (hard fail). At schema-save we just shape-check.
            if (isset($spec['requires']) === true) {
                if (is_string($spec['requires']) === false || $spec['requires'] === '') {
                    $errors[] = [
                        'code'    => 'lifecycle-requires-malformed',
                        'message' => sprintf(
                            'Transition "%s" `requires` must be a non-empty DI tag string.',
                            (string) $action
                        ),
                    ];
                }
            }

            // Optional `authorization` — declarative per-transition group/role
            // gate (Engine 1). When present it must be a non-empty list whose
            // entries are either NC group id strings or `{ "role": "<name>" }`
            // objects. Shape-check only; group existence is a runtime concern.
            if (isset($spec['authorization']) === true) {
                $authError = $this->validateTransitionAuthorization(
                    authorization: $spec['authorization'],
                    action: (string) $action
                );
                if ($authError !== null) {
                    $errors[] = $authError;
                }
            }
        }//end foreach

        return $errors;
    }//end validate()

    /**
     * Validate a graph-mode `x-openregister-lifecycle` annotation.
     *
     * Shape-checks the `graph` block (`schema`, `parentField`, `parentFrom`,
     * `orderField`, `finalField` as non-empty strings; `allowedMoves` one of
     * `forward`|`adjacent`|`any`), keeps `field` required but relaxes the
     * `enum`/`type:string` constraint (a `$ref` field has no enum), and accepts
     * either the literal-string or object-form `initial`. Sibling schemas and
     * parent objects are NOT resolved — existence is a runtime concern.
     *
     * @param array<string, mixed> $annotation The normalised annotation block.
     * @param array<string, mixed> $schema     Full schema definition.
     *
     * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Each check maps to one distinct, irreducible graph-shape rule.
     *
     * @spec openspec/changes/fk-graph-lifecycle-transitions/specs/object-lifecycle/spec.md
     */
    private function validateGraphMode(array $annotation, array $schema): array
    {
        $errors = [];

        // `field` remains required and non-empty, but the enum/type:string
        // constraint is relaxed for a `$ref` lifecycle field.
        $field      = ($annotation['field'] ?? null);
        $fieldValid = (is_string($field) === true && $field !== '');
        if ($fieldValid === false) {
            $errors[] = [
                'code'    => 'lifecycle-missing-key',
                'message' => 'x-openregister-lifecycle is missing required key "field".',
            ];
        }

        if ($fieldValid === true) {
            $properties = ($schema['properties'] ?? []);
            if (is_array($properties) === true && isset($properties[$field]) === false) {
                $errors[] = [
                    'code'    => 'lifecycle-field-missing',
                    'message' => sprintf('x-openregister-lifecycle.field "%s" is not declared in `properties`.', $field),
                ];
            }
        }

        // `initial` (optional): accept the literal-string form or the object
        // form `{ from, field }` with both keys non-empty strings.
        if (isset($annotation['initial']) === true) {
            $initialError = $this->validateInitialForm(initial: $annotation['initial']);
            if ($initialError !== null) {
                $errors[] = $initialError;
            }
        }

        // Graph block: required non-empty string keys.
        $graph = $annotation['graph'];
        foreach (['schema', 'parentField', 'parentFrom', 'orderField', 'finalField'] as $key) {
            $value = ($graph[$key] ?? null);
            if (is_string($value) === false || $value === '') {
                $errors[] = [
                    'code'    => 'lifecycle-graph-missing-key',
                    'message' => sprintf('x-openregister-lifecycle.graph is missing required string key "%s".', $key),
                ];
            }
        }

        // `allowedMoves`: required, one of forward|adjacent|any.
        $allowed = ($graph['allowedMoves'] ?? null);
        if (in_array($allowed, ['forward', 'adjacent', 'any'], true) === false) {
            $shown = gettype($allowed);
            if (is_scalar($allowed) === true) {
                $shown = (string) $allowed;
            }

            $errors[] = [
                'code'    => 'lifecycle-graph-allowedmoves-invalid',
                'message' => sprintf(
                    'x-openregister-lifecycle.graph.allowedMoves "%s" must be one of forward|adjacent|any.',
                    $shown
                ),
            ];
        }

        return $errors;
    }//end validateGraphMode()

    /**
     * Shape-check the `initial` value in its two accepted forms.
     *
     * Valid: a non-empty string (literal form) or an object with non-empty
     * string `from` and `field` keys (object form). Returns a single structured
     * error on violation, or null when valid.
     *
     * @param mixed $initial The raw `initial` value off the annotation.
     *
     * @return array{code: string, message: string}|null Error, or null when valid.
     */
    private function validateInitialForm(mixed $initial): ?array
    {
        if (is_string($initial) === true) {
            return null;
        }

        if (is_array($initial) === true) {
            $from  = ($initial['from'] ?? null);
            $field = ($initial['field'] ?? null);
            if (is_string($from) === false || $from === ''
                || is_string($field) === false || $field === ''
            ) {
                return [
                    'code'    => 'lifecycle-initial-malformed',
                    'message' => 'x-openregister-lifecycle.initial object form must declare non-empty "from" and "field" strings.',
                ];
            }

            return null;
        }

        return [
            'code'    => 'lifecycle-initial-malformed',
            'message' => 'x-openregister-lifecycle.initial must be a string or an object with "from" and "field".',
        ];
    }//end validateInitialForm()

    /**
     * Shape-check a transition's optional `authorization` list.
     *
     * Valid: a non-empty array whose entries are either non-empty NC group id
     * strings or `{ "role": "<non-empty string>" }` objects. Returns a single
     * structured error on the first violation, or null when valid.
     *
     * @param mixed  $authorization The raw `authorization` value off the transition spec.
     * @param string $action        Transition action name, for the error message.
     *
     * @return array{code: string, message: string}|null Error, or null when valid.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Each return is a distinct, irreducible shape violation.
     */
    private function validateTransitionAuthorization(mixed $authorization, string $action): ?array
    {
        if (is_array($authorization) === false || $authorization === []) {
            return [
                'code'    => 'lifecycle-authorization-malformed',
                'message' => sprintf(
                    'Transition "%s" `authorization` must be a non-empty array of group ids or {role} objects.',
                    $action
                ),
            ];
        }

        foreach ($authorization as $entry) {
            $isGroupString = (is_string($entry) === true && $entry !== '');
            $isRoleObject  = (is_array($entry) === true
                && isset($entry['role']) === true
                && is_string($entry['role']) === true
                && $entry['role'] !== '');

            if ($isGroupString === false && $isRoleObject === false) {
                return [
                    'code'    => 'lifecycle-authorization-entry-malformed',
                    'message' => sprintf(
                        'Transition "%s" `authorization` entries must be non-empty group id strings or {"role":"<name>"} objects.',
                        $action
                    ),
                ];
            }
        }

        return null;
    }//end validateTransitionAuthorization()
}//end class
