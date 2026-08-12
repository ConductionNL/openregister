<?php

/**
 * OpenRegister DedupAnnotationValidator
 *
 * Validates the shape of an `x-openregister-dedup` schema annotation,
 * returning a list of `{code, message}` errors. Mirrors the contract of the
 * other annotation validators: an empty array means valid; the caller
 * (SchemaMapper) degrades any error to a non-fatal warning so a malformed
 * dedup block never aborts a schema import.
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
 * Shape validator for the `x-openregister-dedup` annotation.
 *
 * @spec openspec/changes/mdm-foundation/tasks.md#task-5
 */
class DedupAnnotationValidator {
	/**
	 * Recognised match methods.
	 *
	 * @var array<int, string>
	 */
	private const VALID_METHODS = ['exact', 'normalized', 'levenshtein'];

	/**
	 * Validate the `x-openregister-dedup` annotation in a schema shape.
	 *
	 * @param array<string, mixed> $schema Shape with `properties` and `x-openregister-dedup`.
	 *
	 * @return array<int, array{code: string, message: string}> Errors; empty when valid.
	 *
	 * @spec openspec/changes/mdm-foundation/tasks.md#task-5
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) The branch count tracks the annotation's
	 *   optional top-level fields (matchRules presence, threshold, blockingKeys) plus the
	 *   per-rule loop; each is a distinct, independently-testable guard.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Same rationale — flat sequential guards.
	 */
	public function validate(array $schema): array {
		$annotation = ($schema['x-openregister-dedup'] ?? null);
		if ($annotation === null) {
			return [];
		}

		if (is_array($annotation) === false) {
			return [['code' => 'dedup.not-object', 'message' => 'x-openregister-dedup must be an object.']];
		}

		$matchRules = ($annotation['matchRules'] ?? null);
		if (is_array($matchRules) === false || count($matchRules) === 0) {
			return [['code' => 'dedup.no-rules', 'message' => 'x-openregister-dedup requires a non-empty "matchRules" array.']];
		}

		$errors = [];
		foreach ($matchRules as $index => $rule) {
			$errors = array_merge($errors, $this->validateRule(rule: $rule, index: (int)$index));
		}

		if (array_key_exists('threshold', $annotation) === true && is_numeric($annotation['threshold']) === false) {
			$errors[] = ['code' => 'dedup.bad-threshold', 'message' => 'x-openregister-dedup "threshold" must be numeric.'];
		}

		$blockingKeys = ($annotation['blockingKeys'] ?? null);
		if ($blockingKeys !== null && is_array($blockingKeys) === false) {
			$errors[] = ['code' => 'dedup.bad-blocking-keys', 'message' => 'x-openregister-dedup "blockingKeys" must be an array.'];
		}

		return $errors;
	}//end validate()

	/**
	 * Validate a single match rule.
	 *
	 * @param mixed $rule Rule definition.
	 * @param int $index Position in matchRules (for messaging).
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function validateRule($rule, int $index): array {
		if (is_array($rule) === false) {
			return [['code' => 'dedup.rule-not-object', 'message' => sprintf('Match rule #%d must be an object.', $index)]];
		}

		if ((string)($rule['field'] ?? '') === '') {
			return [['code' => 'dedup.missing-field', 'message' => sprintf('Match rule #%d requires a "field".', $index)]];
		}

		$method = (string)($rule['method'] ?? '');
		if (in_array($method, self::VALID_METHODS, true) === false) {
			$valid = implode(', ', self::VALID_METHODS);
			return [
				[
					'code' => 'dedup.unknown-method',
					'message' => sprintf('Match rule #%d has unknown method "%s" (expected one of: %s).', $index, $method, $valid),
				],
			];
		}

		$errors = [];
		if (array_key_exists('weight', $rule) === true && is_numeric($rule['weight']) === false) {
			$errors[] = ['code' => 'dedup.bad-weight', 'message' => sprintf('Match rule #%d "weight" must be numeric.', $index)];
		}

		return $errors;
	}//end validateRule()
}//end class
