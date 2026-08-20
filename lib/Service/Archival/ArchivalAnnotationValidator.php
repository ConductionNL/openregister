<?php

/**
 * OpenRegister ArchivalAnnotationValidator
 *
 * Schema-save validation for the `x-openregister-archival` annotation.
 * Returns a list of structured errors; empty = valid.
 *
 * Per openregister#1614, the annotation declares a default retention
 * (ISO-8601 duration) and an ordered list of condition-based rules whose
 * `retention` (also ISO-8601 duration) overrides the default when the
 * row matches the rule's `condition`. Validation is shape-only at save
 * time; the runtime condition grammar lives in `RetentionConditionEvaluator`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-2-1
 * @spec openspec/specs/archival-annotation-vocabulary/spec.md#scenario-non-iso-8601-retention-default-is-rejected
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use DateInterval;
use Exception;

/**
 * Pure validation logic for the `x-openregister-archival` annotation.
 *
 * Hooked into `SchemaMapper::insert()` / `update()` alongside the existing
 * lifecycle / aggregations validators. Errors translate to HTTP 422
 * schema-save failures via a single aggregated `\Exception` whose message
 * starts `x-openregister-archival: `.
 *
 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-2
 */
final class ArchivalAnnotationValidator {

	/**
	 * Allowed top-level keys under `retention`.
	 *
	 * @var array<int, string>
	 */
	private const ALLOWED_RETENTION_KEYS = ['default', 'rules'];

	/**
	 * Allowed keys under each rule.
	 *
	 * @var array<int, string>
	 */
	private const ALLOWED_RULE_KEYS = ['condition', 'retention', 'reason'];

	/**
	 * Validate the `x-openregister-archival` annotation block on a schema definition.
	 *
	 * @param array<string, mixed> $schema Full schema definition. The validator only
	 *                                     reads `x-openregister-archival`; it does
	 *                                     not consult `properties` because the
	 *                                     condition grammar is field-agnostic at
	 *                                     save time (fields are resolved by the
	 *                                     runtime evaluator against actual rows).
	 *
	 * @return array<int, array{code: string, message: string}> List of errors (empty = valid).
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 *
	 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-2
	 */
	public function validate(array $schema): array {
		if (isset($schema['x-openregister-archival']) === false) {
			return [];
		}

		$annotation = $schema['x-openregister-archival'];
		$errors = [];

		if (is_array($annotation) === false) {
			return [
				[
					'code' => 'archival-not-object',
					'message' => 'x-openregister-archival must be an object.',
				],
			];
		}

		// `retention` block is required.
		$retention = ($annotation['retention'] ?? null);
		if (is_array($retention) === false) {
			return [
				[
					'code' => 'archival-retention-missing',
					'message' => 'x-openregister-archival.retention is required and must be an object.',
				],
			];
		}

		// Reject unknown keys under `retention.` so typos fail loudly.
		foreach (array_keys($retention) as $key) {
			if (in_array((string)$key, self::ALLOWED_RETENTION_KEYS, true) === false) {
				$errors[] = [
					'code' => 'archival-retention-unknown-key',
					'message' => sprintf(
						'x-openregister-archival.retention contains unknown key "%s". Allowed: %s.',
						(string)$key,
						implode(', ', self::ALLOWED_RETENTION_KEYS)
					),
				];
			}
		}

		// `default` is required and must be a parseable ISO-8601 duration.
		if (isset($retention['default']) === false) {
			$errors[] = [
				'code' => 'archival-retention-default-missing',
				'message' => 'x-openregister-archival.retention.default is required (ISO-8601 duration, e.g. "P30D").',
			];
		} elseif ($this->isIsoDuration(value: $retention['default']) === false) {
			$errors[] = [
				'code' => 'archival-retention-default-malformed',
				'message' => sprintf(
					'x-openregister-archival.retention.default "%s" is not a valid ISO-8601 duration (e.g. "P30D", "PT1H", "P1Y6M").',
					(string)$retention['default']
				),
			];
		}

		// `rules` is optional but, when present, must be a list of rule objects.
		if (isset($retention['rules']) === true && is_array($retention['rules']) === false) {
			$errors[] = [
				'code' => 'archival-rules-not-array',
				'message' => 'x-openregister-archival.retention.rules must be an array of rule objects.',
			];
		}

		if (isset($retention['rules']) === true && is_array($retention['rules']) === true) {
			foreach ($retention['rules'] as $index => $rule) {
				$errors = array_merge($errors, $this->validateRule(index: (int)$index, rule: $rule));
			}
		}

		return $errors;
	}//end validate()

	/**
	 * Validate a single rule entry.
	 *
	 * @param int $index Zero-based rule index for error messages.
	 * @param mixed $rule Raw rule value.
	 *
	 * @return array<int, array{code: string, message: string}>
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	private function validateRule(int $index, mixed $rule): array {
		$errors = [];

		if (is_array($rule) === false) {
			return [
				[
					'code' => 'archival-rule-not-object',
					'message' => sprintf('x-openregister-archival.retention.rules[%d] must be an object.', $index),
				],
			];
		}

		// Reject unknown keys under each rule.
		foreach (array_keys($rule) as $key) {
			if (in_array((string)$key, self::ALLOWED_RULE_KEYS, true) === false) {
				$errors[] = [
					'code' => 'archival-rule-unknown-key',
					'message' => sprintf(
						'x-openregister-archival.retention.rules[%d] contains unknown key "%s". Allowed: %s.',
						$index,
						(string)$key,
						implode(', ', self::ALLOWED_RULE_KEYS)
					),
				];
			}
		}

		// `condition` MUST be a non-empty string.
		$condition = ($rule['condition'] ?? null);
		if (is_string($condition) === false) {
			$errors[] = [
				'code' => 'archival-rule-condition-not-string',
				'message' => sprintf(
					'x-openregister-archival.retention.rules[%d].condition must be a non-empty string.',
					$index
				),
			];
		} elseif (trim($condition) === '') {
			$errors[] = [
				'code' => 'archival-rule-condition-empty',
				'message' => sprintf(
					'x-openregister-archival.retention.rules[%d].condition must not be empty.',
					$index
				),
			];
		}

		// `retention` MUST be parseable ISO-8601 duration.
		if (isset($rule['retention']) === false) {
			$errors[] = [
				'code' => 'archival-rule-retention-missing',
				'message' => sprintf(
					'x-openregister-archival.retention.rules[%d].retention is required (ISO-8601 duration).',
					$index
				),
			];
		} elseif ($this->isIsoDuration(value: $rule['retention']) === false) {
			$errors[] = [
				'code' => 'archival-rule-retention-malformed',
				'message' => sprintf(
					'x-openregister-archival.retention.rules[%d].retention "%s" is not a valid ISO-8601 duration.',
					$index,
					(string)$rule['retention']
				),
			];
		}

		// `reason` is optional; if present it MUST be a string.
		if (isset($rule['reason']) === true && is_string($rule['reason']) === false) {
			$errors[] = [
				'code' => 'archival-rule-reason-not-string',
				'message' => sprintf(
					'x-openregister-archival.retention.rules[%d].reason must be a string when present.',
					$index
				),
			];
		}

		return $errors;
	}//end validateRule()

	/**
	 * Return true when $value is a string parseable as ISO-8601 duration.
	 *
	 * Delegates to PHP's `\DateInterval` constructor which accepts the
	 * full ISO-8601 grammar (P[n]Y[n]M[n]DT[n]H[n]M[n]S). Rejects empty
	 * strings and non-string types up-front so the constructor only sees
	 * shape-positive input.
	 *
	 * @param mixed $value Candidate value.
	 *
	 * @return bool True when the value parses as a duration.
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md#scenario-non-iso-8601-retention-default-is-rejected
	 */
	private function isIsoDuration(mixed $value): bool {
		if (is_string($value) === false || $value === '') {
			return false;
		}

		try {
			new DateInterval($value);
			return true;
		} catch (Exception $unused) {
			return false;
		}

	}//end isIsoDuration()
}//end class
