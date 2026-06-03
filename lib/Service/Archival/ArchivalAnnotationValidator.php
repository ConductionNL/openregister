<?php

/**
 * OpenRegister Archival Annotation Validator
 *
 * Validates the x-openregister-archival annotation at schema-save time.
 * Mirrors the pattern of LifecycleAnnotationValidator and peers.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

/**
 * Validates the x-openregister-archival annotation block on a schema.
 *
 * Returns a list of structured errors; an empty list means the annotation is valid.
 */
class ArchivalAnnotationValidator
{

    /**
     * Keys that are allowed directly under retention.{} (besides 'rules').
     */
    private const ALLOWED_RETENTION_KEYS = ['default', 'rules'];

    /**
     * Keys that are allowed inside each rule object.
     */
    private const ALLOWED_RULE_KEYS = ['condition', 'retention', 'reason'];

    /**
     * Validate the x-openregister-archival block from a schema's configuration.
     *
     * @param array $schemaConfiguration The full configuration array of the schema
     *                                   (as returned by Schema::getConfiguration()).
     *
     * @return array<int, array{code: string, message: string}> Structured errors; empty = valid.
     */
    public function validate(array $schemaConfiguration): array
    {
        $errors = [];

        $annotation = $schemaConfiguration['x-openregister-archival'] ?? null;
        if ($annotation === null) {
            // No annotation present — nothing to validate.
            return [];
        }

        if (is_array($annotation) === false) {
            $errors[] = [
                'code'    => 'archival-annotation-not-array',
                'message' => 'x-openregister-archival must be an object, got '.gettype($annotation),
            ];
            return $errors;
        }

        $retention = $annotation['retention'] ?? null;
        if ($retention === null || is_array($retention) === false) {
            $errors[] = [
                'code'    => 'archival-retention-missing',
                'message' => 'x-openregister-archival.retention must be an object',
            ];
            return $errors;
        }

        // Reject unknown keys under retention.{}.
        foreach (array_keys($retention) as $key) {
            if (in_array($key, self::ALLOWED_RETENTION_KEYS, true) === false) {
                $errors[] = [
                    'code'    => 'archival-retention-unknown-key',
                    'message' => "Unknown key under x-openregister-archival.retention: '$key'. Allowed: ".implode(', ', self::ALLOWED_RETENTION_KEYS),
                ];
            }
        }

        // Validate retention.default.
        if (array_key_exists('default', $retention) === false || $retention['default'] === null) {
            $errors[] = [
                'code'    => 'archival-retention-default-missing',
                'message' => 'x-openregister-archival.retention.default is required',
            ];
        } else {
            $errors = array_merge(
                    $errors,
                    $this->validateDuration(
                value: $retention['default'],
                path: 'retention.default',
                missingCode: 'archival-retention-default-missing',
                malformedCode: 'archival-retention-default-malformed'
            )
                    );
        }

        // Validate retention.rules[] if present.
        if (isset($retention['rules']) === true) {
            if (is_array($retention['rules']) === false) {
                $errors[] = [
                    'code'    => 'archival-rules-not-array',
                    'message' => 'x-openregister-archival.retention.rules must be an array',
                ];
            } else {
                foreach ($retention['rules'] as $index => $rule) {
                    $errors = array_merge($errors, $this->validateRule(rule: $rule, index: $index));
                }
            }
        }

        return $errors;
    }//end validate()

    /**
     * Validate a single retention rule object.
     *
     * @param mixed $rule  The rule to validate.
     * @param int   $index The zero-based position in the rules array (for error messages).
     *
     * @return array<int, array{code: string, message: string}> Errors for this rule.
     */
    private function validateRule(mixed $rule, int $index): array
    {
        $errors = [];
        $prefix = "retention.rules[$index]";

        if (is_array($rule) === false) {
            $errors[] = [
                'code'    => 'archival-rule-not-object',
                'message' => "$prefix must be an object",
            ];
            return $errors;
        }

        // Reject unknown keys.
        foreach (array_keys($rule) as $key) {
            if (in_array($key, self::ALLOWED_RULE_KEYS, true) === false) {
                $errors[] = [
                    'code'    => 'archival-rule-unknown-key',
                    'message' => "Unknown key under $prefix: '$key'. Allowed: ".implode(', ', self::ALLOWED_RULE_KEYS),
                ];
            }
        }

        // Validate condition (required, non-empty string).
        if (isset($rule['condition']) === false) {
            $errors[] = [
                'code'    => 'archival-rule-condition-missing',
                'message' => "$prefix.condition is required",
            ];
        } else if (is_string($rule['condition']) === false) {
            $errors[] = [
                'code'    => 'archival-rule-condition-not-string',
                'message' => "$prefix.condition must be a string, got ".gettype($rule['condition']),
            ];
        } else if (trim($rule['condition']) === '') {
            $errors[] = [
                'code'    => 'archival-rule-condition-empty',
                'message' => "$prefix.condition must not be empty",
            ];
        }

        // Validate retention (required, ISO-8601 duration).
        $errors = array_merge(
                $errors,
                $this->validateDuration(
            value: $rule['retention'] ?? null,
            path: "$prefix.retention",
            missingCode: 'archival-rule-retention-missing',
            malformedCode: 'archival-rule-retention-malformed'
        )
                );

        // Validate reason (optional, must be string if present).
        if (isset($rule['reason']) === true && is_string($rule['reason']) === false) {
            $errors[] = [
                'code'    => 'archival-rule-reason-not-string',
                'message' => "$prefix.reason must be a string if provided",
            ];
        }

        return $errors;
    }//end validateRule()

    /**
     * Validate that a value is a parseable ISO-8601 duration string.
     *
     * @param mixed  $value         The candidate duration value.
     * @param string $path          Human-readable path for error messages.
     * @param string $missingCode   Error code when the value is absent.
     * @param string $malformedCode Error code when the value is present but not parseable.
     *
     * @return array<int, array{code: string, message: string}> Zero or one errors.
     */
    private function validateDuration(mixed $value, string $path, string $missingCode, string $malformedCode): array
    {
        if ($value === null) {
            return [
                [
                    'code'    => $missingCode,
                    'message' => "$path is required",
                ],
            ];
        }

        if (is_string($value) === false) {
            return [
                [
                    'code'    => $malformedCode,
                    'message' => "$path must be an ISO-8601 duration string (e.g. P30D), got ".gettype($value),
                ],
            ];
        }

        try {
            new \DateInterval($value);
        } catch (\Exception $e) {
            return [
                [
                    'code'    => $malformedCode,
                    'message' => "$path '$value' is not a valid ISO-8601 duration: ".$e->getMessage(),
                ],
            ];
        }

        return [];
    }//end validateDuration()
}//end class
