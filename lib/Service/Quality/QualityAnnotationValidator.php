<?php

/**
 * OpenRegister QualityAnnotationValidator
 *
 * Validates the shape of an `x-openregister-quality` schema annotation,
 * returning a list of `{code, message}` errors. Mirrors the contract of
 * {@see \OCA\OpenRegister\Service\Calculation\CalculationAnnotationValidator}:
 * an empty array means valid; the caller (SchemaMapper) degrades any error to
 * a non-fatal warning so a malformed quality block never aborts a schema import.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Quality
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

namespace OCA\OpenRegister\Service\Quality;

/**
 * Shape validator for the `x-openregister-quality` annotation.
 *
 * @spec openspec/changes/mdm-foundation/tasks.md#task-3
 */
class QualityAnnotationValidator
{
    /**
     * Recognised rule types.
     *
     * @var array<int, string>
     */
    private const VALID_TYPES = ['required', 'format', 'freshness'];

    /**
     * Validate the `x-openregister-quality` annotation in a schema shape.
     *
     * @param array<string, mixed> $schema Shape with `properties` and `x-openregister-quality`.
     *
     * @return array<int, array{code: string, message: string}> Errors; empty when valid.
     *
     * @spec openspec/changes/mdm-foundation/tasks.md#task-3
     */
    public function validate(array $schema): array
    {
        $annotation = ($schema['x-openregister-quality'] ?? null);
        if ($annotation === null) {
            return [];
        }

        if (is_array($annotation) === false) {
            return [['code' => 'quality.not-object', 'message' => 'x-openregister-quality must be an object.']];
        }

        $rules = ($annotation['rules'] ?? null);
        if (is_array($rules) === false || count($rules) === 0) {
            return [['code' => 'quality.no-rules', 'message' => 'x-openregister-quality requires a non-empty "rules" array.']];
        }

        $errors = [];
        foreach ($rules as $index => $rule) {
            $errors = array_merge($errors, $this->validateRule(rule: $rule, index: (int) $index));
        }

        $errors = array_merge($errors, $this->validateThresholds(annotation: $annotation));

        return $errors;
    }//end validate()

    /**
     * Validate a single rule entry.
     *
     * @param mixed $rule  Rule definition.
     * @param int   $index Position in the rules array (for messaging).
     *
     * @return array<int, array{code: string, message: string}>
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) The branch count tracks the per-rule-type
     *   validation matrix (shape, type, field, weight, format/pattern, half-life); each branch
     *   emits a distinct, independently-testable error and is clearer kept inline than scattered.
     * @SuppressWarnings(PHPMD.NPathComplexity)      Same rationale — flat per-field guards.
     */
    private function validateRule($rule, int $index): array
    {
        if (is_array($rule) === false) {
            return [['code' => 'quality.rule-not-object', 'message' => sprintf('Rule #%d must be an object.', $index)]];
        }

        $type = (string) ($rule['type'] ?? '');
        if (in_array($type, self::VALID_TYPES, true) === false) {
            return [
                [
                    'code'    => 'quality.unknown-type',
                    'message' => sprintf('Rule #%d has unknown type "%s" (expected one of: %s).', $index, $type, implode(', ', self::VALID_TYPES)),
                ],
            ];
        }

        $field = (string) ($rule['field'] ?? '');
        if ($field === '') {
            return [['code' => 'quality.missing-field', 'message' => sprintf('Rule #%d (%s) requires a "field".', $index, $type)]];
        }

        $errors = [];

        if (array_key_exists('weight', $rule) === true && is_numeric($rule['weight']) === false) {
            $errors[] = ['code' => 'quality.bad-weight', 'message' => sprintf('Rule #%d "weight" must be numeric.', $index)];
        }

        if ($type === 'format') {
            $hasNamed   = (isset($rule['format']) === true && (string) $rule['format'] !== '');
            $hasPattern = (isset($rule['pattern']) === true && (string) $rule['pattern'] !== '');
            if ($hasNamed === false && $hasPattern === false) {
                $errors[] = [
                    'code'    => 'quality.format-missing-spec',
                    'message' => sprintf('Rule #%d (format) requires "format" or "pattern".', $index),
                ];
            }
        }

        if ($type === 'freshness'
            && array_key_exists('halfLifeDays', $rule) === true
            && is_numeric($rule['halfLifeDays']) === false
        ) {
            $errors[] = ['code' => 'quality.bad-half-life', 'message' => sprintf('Rule #%d "halfLifeDays" must be numeric.', $index)];
        }

        return $errors;
    }//end validateRule()

    /**
     * Validate the optional thresholds block.
     *
     * @param array<string, mixed> $annotation Quality annotation.
     *
     * @return array<int, array{code: string, message: string}>
     */
    private function validateThresholds(array $annotation): array
    {
        $thresholds = ($annotation['thresholds'] ?? null);
        if ($thresholds === null) {
            return [];
        }

        if (is_array($thresholds) === false) {
            return [['code' => 'quality.thresholds-not-object', 'message' => 'x-openregister-quality "thresholds" must be an object.']];
        }

        $errors = [];
        foreach (['good', 'fair'] as $key) {
            if (array_key_exists($key, $thresholds) === true && is_numeric($thresholds[$key]) === false) {
                $errors[] = ['code' => 'quality.bad-threshold', 'message' => sprintf('Threshold "%s" must be numeric.', $key)];
            }
        }

        return $errors;
    }//end validateThresholds()
}//end class
