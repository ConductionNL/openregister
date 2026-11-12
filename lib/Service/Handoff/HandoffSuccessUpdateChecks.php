<?php

/**
 * OpenRegister Handoff Success Update Checks
 *
 * The save-time rules for the `onSuccess` block of one `x-openregister-handoff`
 * entry: a non-empty `set` map whose keys the source schema defines. Split out
 * of {@see HandoffAnnotationValidator}, which runs them per entry.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Handoff
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Handoff;

/**
 * Validates one handoff entry's `onSuccess` block.
 */
final class HandoffSuccessUpdateChecks {

	/**
	 * Validate `onSuccess.set` keys exist on the source schema.
	 *
	 * @param HandoffAnnotationEntry $entry The entry.
	 *
	 * @return array<int, array{code: string, message: string}> The success-update errors.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Scenario: `onSuccess.set` names a property missing from the source schema)
	 */
	public function errors(HandoffAnnotationEntry $entry): array {
		$onSuccess = $entry->field(key: 'onSuccess');
		if ($onSuccess === null) {
			return [];
		}

		$set = $this->successSet(onSuccess: $onSuccess);
		if ($set === []) {
			return [$entry->fault(code: 'handoff-bad-success-update', message: '`onSuccess` must carry a non-empty `set` map.')];
		}

		return $this->undefinedPropertyErrors(entry: $entry, set: $set);
	}//end errors()

	/**
	 * The `set` map of an `onSuccess` block, or an empty map when it has none.
	 *
	 * @param mixed $onSuccess The raw `onSuccess` value.
	 *
	 * @return array<mixed, mixed> The `set` map.
	 */
	private function successSet(mixed $onSuccess): array {
		$set = null;
		if (is_array($onSuccess) === true) {
			$set = ($onSuccess['set'] ?? null);
		}

		if (is_array($set) === false) {
			return [];
		}

		return $set;
	}//end successSet()

	/**
	 * The errors for `onSuccess.set` keys the source schema does not define.
	 *
	 * @param HandoffAnnotationEntry $entry The entry (for the source properties).
	 * @param array<mixed, mixed>    $set   The `set` map.
	 *
	 * @return array<int, array{code: string, message: string}> One error per undefined property.
	 */
	private function undefinedPropertyErrors(HandoffAnnotationEntry $entry, array $set): array {
		$errors = [];
		foreach (array_keys($set) as $property) {
			if (array_key_exists((string) $property, $entry->properties) === false) {
				$format = 'onSuccess.set names property "%s" which the source schema does not define.';
				$errors[] = $entry->fault(code: 'handoff-bad-success-update', message: sprintf($format, (string) $property));
			}
		}

		return $errors;
	}//end undefinedPropertyErrors()
}//end class
