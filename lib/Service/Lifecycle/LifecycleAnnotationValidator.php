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
     */
    public function validate(array $schema): array
    {
        if (isset($schema['x-openregister-lifecycle']) === false) {
            return [];
        }

        $annotation = $schema['x-openregister-lifecycle'];
        $errors     = [];

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

            // From: required, array of states all in the enum.
            $from   = ($spec['from'] ?? null);
            $fromOk = (is_array($from) === true && count($from) > 0);
            if ($fromOk === false) {
                $errors[] = [
                    'code'    => 'lifecycle-from-missing',
                    'message' => sprintf('Transition "%s" must declare a non-empty `from` array.', (string) $action),
                ];
            }

            foreach (($fromOk === true ? $from : []) as $fromState) {
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
        }//end foreach

        return $errors;
    }//end validate()
}//end class
