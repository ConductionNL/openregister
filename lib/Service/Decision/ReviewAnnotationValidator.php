<?php

/**
 * OpenRegister ReviewAnnotationValidator
 *
 * Refuses an `x-openregister-review` declaration the ownership rule cannot
 * honour, at schema save, so a typo never runs as an open review.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Decision
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-reviewers-decision-is-changed-only-by-that-reviewer-or-a-supervisor-req-rod-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Decision;

/**
 * Shape checks for `x-openregister-review`.
 *
 * Whether each supervisor group exists is not checked here: an app's repair
 * step may create its groups after its schemas are imported, and a group that
 * does not exist grants nobody anything, so it cannot open the review.
 *
 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-reviewers-decision-is-changed-only-by-that-reviewer-or-a-supervisor-req-rod-001
 */
final class ReviewAnnotationValidator {

	/**
	 * The refusals for a schema configuration; empty when it is sound or absent.
	 *
	 * @param array<string, mixed> $configuration The schema configuration.
	 *
	 * @return array<int, array{code: string, message: string}> The refusals.
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-reviewers-decision-is-changed-only-by-that-reviewer-or-a-supervisor-req-rod-001
	 */
	public function validate(array $configuration): array {
		if (array_key_exists(ReviewPolicy::ANNOTATION, $configuration) === false) {
			return [];
		}

		$declaration = $configuration[ReviewPolicy::ANNOTATION];
		if (is_array($declaration) === false) {
			return [self::refusal(code: 'review-shape', message: ReviewPolicy::ANNOTATION.' must be an object with ownership, supervisors and hideOthers.')];
		}

		return array_merge(self::unknownKeys(declaration: $declaration), self::badValues(declaration: $declaration));
	}//end validate()

	/**
	 * A refusal for every key the declaration may not hold.
	 *
	 * @param array<mixed> $declaration The declaration.
	 *
	 * @return array<int, array{code: string, message: string}> The refusals.
	 */
	private static function unknownKeys(array $declaration): array {
		$errors = [];
		foreach (array_keys($declaration) as $key) {
			if (in_array($key, ReviewPolicy::KEYS, true) === false) {
				$errors[] = self::refusal(code: 'review-unknown-key', message: 'Unknown key "'.$key.'" in '.ReviewPolicy::ANNOTATION.'.');
			}
		}

		return $errors;
	}//end unknownKeys()

	/**
	 * A refusal for every value the ownership rule cannot honour.
	 *
	 * @param array<mixed> $declaration The declaration.
	 *
	 * @return array<int, array{code: string, message: string}> The refusals.
	 */
	private static function badValues(array $declaration): array {
		$errors = [];
		$ownership = ($declaration['ownership'] ?? ReviewPolicy::OWNERSHIP_OPEN);
		if (in_array($ownership, [ReviewPolicy::OWNERSHIP_OPEN, ReviewPolicy::OWNERSHIP_DECIDER], true) === false) {
			$named = gettype($ownership);
			if (is_scalar($ownership) === true) {
				$named = (string)$ownership;
			}

			$errors[] = self::refusal(code: 'review-ownership', message: 'Ownership "'.$named.'" is not open or decider.');
		}

		if (array_key_exists('supervisors', $declaration) === true && self::isListOfStrings(value: $declaration['supervisors']) === false) {
			$errors[] = self::refusal(code: 'review-supervisors', message: 'Supervisors must be a list of group ids.');
		}

		if (array_key_exists('hideOthers', $declaration) === true && is_bool($declaration['hideOthers']) === false) {
			$errors[] = self::refusal(code: 'review-hide-others', message: 'hideOthers must be true or false.');
		}

		return $errors;
	}//end badValues()

	/**
	 * Whether a value is a list of non-empty strings.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True for a list of group ids.
	 */
	private static function isListOfStrings(mixed $value): bool {
		if (is_array($value) === false || array_is_list($value) === false) {
			return false;
		}

		foreach ($value as $item) {
			if (is_string($item) === false || trim($item) === '') {
				return false;
			}
		}

		return true;
	}//end isListOfStrings()

	/**
	 * One refusal.
	 *
	 * @param string $code    The refusal code.
	 * @param string $message What is wrong.
	 *
	 * @return array{code: string, message: string} The refusal.
	 */
	private static function refusal(string $code, string $message): array {
		return ['code' => $code, 'message' => $message];
	}//end refusal()
}//end class
