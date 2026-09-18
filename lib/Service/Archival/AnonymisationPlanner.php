<?php

/**
 * Reads a schema's anonymisation profile and says what it would do.
 *
 * Split from the act on purpose. A planner that only reads can be asked "what
 * would this leave" before anything is irreversible, and the answer is the same
 * list the report prints afterwards. An anonymisation is not undoable, so the
 * chance to look at it first is not a convenience.
 *
 * 🔴 IT REFUSES A PROFILE THAT NAMES A PROPERTY THE SCHEMA DOES NOT DECLARE.
 * Silently skipping one is the failure that matters here: the profile says the
 * citizen's name is removed, the schema spells that property differently, and
 * the run reports success over a record that still holds the name. Every
 * instrument would say the record was anonymised. Nothing would be.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use InvalidArgumentException;

/**
 * Turns a declared profile into a plan, or refuses it.
 */
class AnonymisationPlanner {

	/**
	 * Read the profile out of a schema's archival annotation.
	 *
	 * @param array<string, mixed> $annotation The `x-openregister-archival` block.
	 *
	 * @return array<string, array<string, mixed>> Property name to its treatment.
	 */
	public function profileOf(array $annotation): array {
		$profile = ($annotation[AnonymisationProfile::ANNOTATION_KEY] ?? null);
		if (is_array($profile) === false) {
			return [];
		}

		$read = [];
		foreach ($profile as $property => $treatment) {
			if (is_string($property) === false || $property === '') {
				continue;
			}

			if (is_array($treatment) === true) {
				$read[$property] = $treatment;
				continue;
			}

			$read[$property] = ['treatment' => $treatment];
		}

		return $read;
	}//end profileOf()

	/**
	 * Refuse a profile that cannot mean what it says.
	 *
	 * Three refusals, and each one is a way a run could otherwise report success
	 * over a record it did not change:
	 *
	 *  - a property the schema does not declare, which would be skipped;
	 *  - a treatment the vocabulary does not know, which would be skipped;
	 *  - `fixed` with no value and `generalise` with no grain or an unknown one,
	 *    neither of which can be carried out.
	 *
	 * @param array<string, mixed> $annotation         The archival annotation.
	 * @param string[]             $declaredProperties The property names the schema declares.
	 *
	 * @return string[] The refusals, empty when the profile is sound.
	 */
	public function refusals(array $annotation, array $declaredProperties): array {
		$refusals = [];
		$declared = array_flip($declaredProperties);

		foreach ($this->profileOf(annotation: $annotation) as $property => $rule) {
			if (isset($declared[$property]) === false) {
				$refusals[] = sprintf(
					'The anonymisation profile names "%s", which this schema does not declare. It would be '
					.'skipped and the run would report success over a record that still holds it.',
					$property
				);
				continue;
			}

			$refusals = array_merge($refusals, $this->refuseRule(property: $property, rule: $rule));
		}

		return $refusals;
	}//end refusals()

	/**
	 * What would happen to one record, without touching it.
	 *
	 * @param array<string, mixed> $annotation The archival annotation.
	 * @param array<string, mixed> $payload    The record's own properties.
	 *
	 * @return array{changed: string[], kept: string[]} The two lists, both sorted.
	 */
	public function plan(array $annotation, array $payload): array {
		$profile = $this->profileOf(annotation: $annotation);
		$changed = [];
		$kept = [];

		foreach (array_keys($payload) as $property) {
			if (isset($profile[$property]) === true) {
				$changed[] = (string)$property;
				continue;
			}

			// 🔴 KEPT IS A LIST, NOT A REMAINDER. "We anonymised it" without
			// saying what stayed is a claim nobody can check, and what stays is
			// the whole question: a record with the name removed and the date of
			// birth, the postcode and the case number intact is not anonymous.
			$kept[] = (string)$property;
		}

		sort($changed);
		sort($kept);

		return ['changed' => $changed, 'kept' => $kept];
	}//end plan()

	/**
	 * Refuse one rule that cannot be carried out.
	 *
	 * @param string $property The property it is declared on.
	 * @param mixed  $rule     The declared rule.
	 *
	 * @return string[] The refusals for this rule.
	 */
	private function refuseRule(string $property, mixed $rule): array {
		$treatment = $rule;
		if (is_array($rule) === true) {
			$treatment = ($rule['treatment'] ?? null);
		}

		if (in_array($treatment, AnonymisationProfile::TREATMENTS, true) === false) {
			return [
				sprintf(
					'The anonymisation profile gives "%s" the treatment "%s", which is not one of: %s.',
					$property,
					$this->describe(value: $treatment),
					implode(', ', AnonymisationProfile::TREATMENTS)
				),
			];
		}

		if ($treatment === AnonymisationProfile::FIXED && array_key_exists('value', (array)$rule) === false) {
			return [sprintf('The anonymisation profile gives "%s" the treatment "fixed" without saying which value to write.', $property)];
		}

		if ($treatment === AnonymisationProfile::GENERALISE) {
			$grain = ((array)$rule)['grain'] ?? null;
			if (in_array($grain, AnonymisationProfile::GRAINS, true) === false) {
				return [
					sprintf(
						'The anonymisation profile generalises "%s" to "%s", which is not one of: %s.',
						$property,
						$this->describe(value: $grain),
						implode(', ', AnonymisationProfile::GRAINS)
					),
				];
			}
		}

		return [];
	}//end refuseRule()

	/**
	 * One value as something a refusal can name.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string Its text, or its type when it has none.
	 */
	private function describe(mixed $value): string {
		if (is_scalar($value) === true) {
			return (string)$value;
		}

		return gettype($value);
	}//end describe()

	/**
	 * Refuse loudly, for a caller that wants an exception rather than a list.
	 *
	 * @param array<string, mixed> $annotation         The archival annotation.
	 * @param string[]             $declaredProperties The property names the schema declares.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the profile cannot mean what it says.
	 */
	public function assertSound(array $annotation, array $declaredProperties): void {
		$refusals = $this->refusals(annotation: $annotation, declaredProperties: $declaredProperties);
		if ($refusals === []) {
			return;
		}

		throw new InvalidArgumentException(implode(' ', $refusals));
	}//end assertSound()
}//end class
