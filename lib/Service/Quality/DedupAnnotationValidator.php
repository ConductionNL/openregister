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
class DedupAnnotationValidator implements DedupAnnotationValidatorInterface {
	/**
	 * Recognised match methods.
	 *
	 * @var array<int, string>
	 */
	private const VALID_METHODS = ['exact', 'normalized', 'levenshtein'];

	/**
	 * Recognised `onCreate` policies.
	 *
	 * `warn` is the default and means the check is advisory: the endpoint
	 * answers, and the save path does nothing. `block` refuses a create that
	 * strongly matches, unless the caller is in an override group.
	 *
	 * @var array<int, string>
	 */
	public const VALID_ON_CREATE = ['warn', 'block'];

	/**
	 * Validate the `x-openregister-dedup` annotation in a schema shape.
	 *
	 * @param array<string, mixed> $schema Shape with `properties` and `x-openregister-dedup`.
	 *
	 * @return array<int, array{code: string, message: string}> Errors; empty when valid.
	 *
	 * @spec openspec/changes/mdm-foundation/tasks.md#task-5
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

		return array_merge(
			$this->validateRules(matchRules: $matchRules),
			$this->validateThreshold(annotation: $annotation),
			$this->validateBlockingKeys(annotation: $annotation),
			$this->validateCreatePolicy(annotation: $annotation)
		);
	}//end validate()

	/**
	 * Validate every match rule, keeping the rules' order.
	 *
	 * @param array<int|string, mixed> $matchRules Match rule definitions.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function validateRules(array $matchRules): array {
		$errors = [];
		foreach ($matchRules as $index => $rule) {
			$errors = array_merge($errors, $this->validateRule(rule: $rule, index: (int)$index));
		}

		return $errors;
	}//end validateRules()

	/**
	 * The error for a present but non-numeric `threshold`.
	 *
	 * @param array<string, mixed> $annotation The `x-openregister-dedup` block.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function validateThreshold(array $annotation): array {
		if (array_key_exists('threshold', $annotation) === true && is_numeric($annotation['threshold']) === false) {
			return [['code' => 'dedup.bad-threshold', 'message' => 'x-openregister-dedup "threshold" must be numeric.']];
		}

		return [];
	}//end validateThreshold()

	/**
	 * The error for a `blockingKeys` that is set but not an array.
	 *
	 * @param array<string, mixed> $annotation The `x-openregister-dedup` block.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function validateBlockingKeys(array $annotation): array {
		$blockingKeys = ($annotation['blockingKeys'] ?? null);
		if ($blockingKeys !== null && is_array($blockingKeys) === false) {
			return [['code' => 'dedup.bad-blocking-keys', 'message' => 'x-openregister-dedup "blockingKeys" must be an array.']];
		}

		return [];
	}//end validateBlockingKeys()

	/**
	 * Validate the create-time policy: `onCreate` and `overrideGroups`.
	 *
	 * Both are optional, and their absence means the advisory default. A
	 * misspelled `onCreate` is reported rather than ignored: a schema that
	 * meant `block` and wrote `blocking` would otherwise save clean and let
	 * every duplicate through, which is the failure the declaration exists to
	 * prevent and the one hardest to notice.
	 *
	 * `overrideGroups` without `block` is NOT an error. It names who may
	 * override if the schema ever blocks, and refusing it would make turning
	 * blocking on a two-step edit for no gain.
	 *
	 * @param array<string, mixed> $annotation The `x-openregister-dedup` block.
	 *
	 * @return array<int, array{code: string, message: string}>
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-schema-declares-what-a-strong-match-does-at-create
	 */
	private function validateCreatePolicy(array $annotation): array {
		return array_merge(
			$this->validateOnCreate(onCreate: ($annotation['onCreate'] ?? null)),
			$this->validateOverrideGroups(overrideGroups: ($annotation['overrideGroups'] ?? null))
		);
	}//end validateCreatePolicy()

	/**
	 * The error for an `onCreate` that is set but not a recognised policy.
	 *
	 * @param mixed $onCreate The declared policy, or null when absent.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function validateOnCreate($onCreate): array {
		if ($onCreate === null || in_array($onCreate, self::VALID_ON_CREATE, true) === true) {
			return [];
		}

		$valid = implode(', ', self::VALID_ON_CREATE);
		return [
			[
				'code' => 'dedup.unknown-on-create',
				'message' => sprintf('x-openregister-dedup "onCreate" must be one of: %s.', $valid),
			],
		];
	}//end validateOnCreate()

	/**
	 * The errors for an `overrideGroups` that is not a list of group ids.
	 *
	 * @param mixed $overrideGroups The declared groups, or null when absent.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function validateOverrideGroups($overrideGroups): array {
		if ($overrideGroups === null) {
			return [];
		}

		if (is_array($overrideGroups) === false) {
			return [
				[
					'code' => 'dedup.bad-override-groups',
					'message' => 'x-openregister-dedup "overrideGroups" must be an array of group ids.',
				],
			];
		}

		return $this->overrideGroupEntryErrors(overrideGroups: $overrideGroups);
	}//end validateOverrideGroups()

	/**
	 * The errors for `overrideGroups` entries that are not non-empty group ids.
	 *
	 * @param array<int|string, mixed> $overrideGroups The declared groups.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function overrideGroupEntryErrors(array $overrideGroups): array {
		$errors = [];
		foreach ($overrideGroups as $index => $group) {
			if (is_string($group) === true && $group !== '') {
				continue;
			}

			$errors[] = [
				'code' => 'dedup.bad-override-group',
				'message' => sprintf('x-openregister-dedup "overrideGroups" entry #%d must be a non-empty group id.', (int)$index),
			];
		}

		return $errors;
	}//end overrideGroupEntryErrors()

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

		$refusal = $this->methodRefusal(method: (string)($rule['method'] ?? ''), index: $index);
		if ($refusal !== []) {
			return $refusal;
		}

		return $this->weightErrors(rule: $rule, index: $index);
	}//end validateRule()

	/**
	 * The error for a match rule with a present but non-numeric weight.
	 *
	 * @param array<string, mixed> $rule Rule definition.
	 * @param int $index Position in matchRules (for messaging).
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	private function weightErrors(array $rule, int $index): array {
		if (array_key_exists('weight', $rule) === true && is_numeric($rule['weight']) === false) {
			return [['code' => 'dedup.bad-weight', 'message' => sprintf('Match rule #%d "weight" must be numeric.', $index)]];
		}

		return [];
	}//end weightErrors()

	/**
	 * The error for a match rule whose method is not recognised.
	 *
	 * @param string $method Declared method.
	 * @param int $index Position in matchRules (for messaging).
	 *
	 * @return array<int, array{code: string, message: string}> Empty when the method is known.
	 */
	private function methodRefusal(string $method, int $index): array {
		if (in_array($method, self::VALID_METHODS, true) === true) {
			return [];
		}

		$valid = implode(', ', self::VALID_METHODS);
		return [
			[
				'code' => 'dedup.unknown-method',
				'message' => sprintf('Match rule #%d has unknown method "%s" (expected one of: %s).', $index, $method, $valid),
			],
		];
	}//end methodRefusal()
}//end class
