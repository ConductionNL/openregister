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
class QualityAnnotationValidator {
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
	public function validate(array $schema): array {
		$annotation = ($schema['x-openregister-quality'] ?? null);
		if ($annotation === null) {
			return [];
		}

		if (is_array($annotation) === false) {
			return [['code' => 'quality.not-object', 'message' => 'x-openregister-quality must be an object.']];
		}

		$rules = ($annotation['rules'] ?? null);
		if ($this->isNonEmptyArray(value: $rules) === false) {
			return [['code' => 'quality.no-rules', 'message' => 'x-openregister-quality requires a non-empty "rules" array.']];
		}

		return array_merge($this->validateRules(rules: $rules), $this->validateThresholds(annotation: $annotation));
	}//end validate()

	/**
	 * Whether a value is an array with at least one entry.
	 *
	 * @param mixed $value Candidate value.
	 *
	 * @return bool
	 */
	private function isNonEmptyArray($value): bool {
		return is_array($value) === true && count($value) > 0;
	}//end isNonEmptyArray()

	/**
	 * Validate every rule entry, keeping the rules' order.
	 *
	 * @param array<int|string, mixed> $rules Rule definitions.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function validateRules(array $rules): array {
		$errors = [];
		foreach ($rules as $index => $rule) {
			$errors = array_merge($errors, $this->validateRule(rule: $rule, index: (int)$index));
		}

		return $errors;
	}//end validateRules()

	/**
	 * Validate a single rule entry.
	 *
	 * A rule that is not an object, has an unknown type or names no field is
	 * refused with that one error; otherwise its optional fields are checked.
	 *
	 * @param mixed $rule Rule definition.
	 * @param int $index Position in the rules array (for messaging).
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function validateRule($rule, int $index): array {
		if (is_array($rule) === false) {
			return [['code' => 'quality.rule-not-object', 'message' => sprintf('Rule #%d must be an object.', $index)]];
		}

		$type = (string)($rule['type'] ?? '');
		$refusal = $this->typeOrFieldRefusal(rule: $rule, type: $type, index: $index);
		if ($refusal !== []) {
			return $refusal;
		}

		return array_merge(
			$this->weightErrors(rule: $rule, index: $index),
			$this->typeSpecificErrors(rule: $rule, type: $type, index: $index)
		);
	}//end validateRule()

	/**
	 * The single error for a rule whose type is unknown or whose field is missing.
	 *
	 * @param array<string, mixed> $rule Rule definition.
	 * @param string $type Rule type token.
	 * @param int $index Position in the rules array (for messaging).
	 *
	 * @return array<int, array{code: string, message: string}> Empty when type and field are usable.
	 */
	private function typeOrFieldRefusal(array $rule, string $type, int $index): array {
		if (in_array($type, self::VALID_TYPES, true) === false) {
			return [
				[
					'code' => 'quality.unknown-type',
					'message' => sprintf('Rule #%d has unknown type "%s" (expected one of: %s).', $index, $type, implode(', ', self::VALID_TYPES)),
				],
			];
		}

		$field = (string)($rule['field'] ?? '');
		if ($field === '') {
			return [['code' => 'quality.missing-field', 'message' => sprintf('Rule #%d (%s) requires a "field".', $index, $type)]];
		}

		return [];
	}//end typeOrFieldRefusal()

	/**
	 * The error for a present but non-numeric weight.
	 *
	 * @param array<string, mixed> $rule Rule definition.
	 * @param int $index Position in the rules array (for messaging).
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function weightErrors(array $rule, int $index): array {
		if (array_key_exists('weight', $rule) === true && is_numeric($rule['weight']) === false) {
			return [['code' => 'quality.bad-weight', 'message' => sprintf('Rule #%d "weight" must be numeric.', $index)]];
		}

		return [];
	}//end weightErrors()

	/**
	 * The errors for the fields only one rule type reads.
	 *
	 * @param array<string, mixed> $rule Rule definition.
	 * @param string $type Rule type token.
	 * @param int $index Position in the rules array (for messaging).
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function typeSpecificErrors(array $rule, string $type, int $index): array {
		return match ($type) {
			'format' => $this->formatSpecErrors(rule: $rule, index: $index),
			'freshness' => $this->halfLifeErrors(rule: $rule, index: $index),
			default => [],
		};
	}//end typeSpecificErrors()

	/**
	 * The error for a format rule that names neither a format nor a pattern.
	 *
	 * @param array<string, mixed> $rule Rule definition.
	 * @param int $index Position in the rules array (for messaging).
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function formatSpecErrors(array $rule, int $index): array {
		if ($this->hasText(rule: $rule, key: 'format') === true || $this->hasText(rule: $rule, key: 'pattern') === true) {
			return [];
		}

		return [
			[
				'code' => 'quality.format-missing-spec',
				'message' => sprintf('Rule #%d (format) requires "format" or "pattern".', $index),
			],
		];
	}//end formatSpecErrors()

	/**
	 * Whether a rule carries a non-empty value under a key.
	 *
	 * @param array<string, mixed> $rule Rule definition.
	 * @param string $key Key to read.
	 *
	 * @return bool
	 */
	private function hasText(array $rule, string $key): bool {
		return isset($rule[$key]) === true && (string)$rule[$key] !== '';
	}//end hasText()

	/**
	 * The error for a freshness rule with a present but non-numeric half-life.
	 *
	 * @param array<string, mixed> $rule Rule definition.
	 * @param int $index Position in the rules array (for messaging).
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function halfLifeErrors(array $rule, int $index): array {
		if (array_key_exists('halfLifeDays', $rule) === true && is_numeric($rule['halfLifeDays']) === false) {
			return [['code' => 'quality.bad-half-life', 'message' => sprintf('Rule #%d "halfLifeDays" must be numeric.', $index)]];
		}

		return [];
	}//end halfLifeErrors()

	/**
	 * Validate the optional thresholds block.
	 *
	 * @param array<string, mixed> $annotation Quality annotation.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function validateThresholds(array $annotation): array {
		$thresholds = ($annotation['thresholds'] ?? null);
		if ($thresholds === null) {
			return [];
		}

		if (is_array($thresholds) === false) {
			return [['code' => 'quality.thresholds-not-object', 'message' => 'x-openregister-quality "thresholds" must be an object.']];
		}

		return $this->thresholdValueErrors(thresholds: $thresholds);
	}//end validateThresholds()

	/**
	 * The errors for present but non-numeric `good` / `fair` cut-offs.
	 *
	 * @param array<string, mixed> $thresholds Thresholds block.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function thresholdValueErrors(array $thresholds): array {
		$errors = [];
		foreach (['good', 'fair'] as $key) {
			if (array_key_exists($key, $thresholds) === true && is_numeric($thresholds[$key]) === false) {
				$errors[] = ['code' => 'quality.bad-threshold', 'message' => sprintf('Threshold "%s" must be numeric.', $key)];
			}
		}

		return $errors;
	}//end thresholdValueErrors()
}//end class
