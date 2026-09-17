<?php

/**
 * OpenRegister UniqueHintAnnotationValidator
 *
 * Validates `x-openregister-unique-hint`: the properties a schema nominates as
 * effectively unique, so a save whose value already exists elsewhere warns.
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
 * Shape validator for the `x-openregister-unique-hint` annotation.
 *
 * The annotation is a LIST OF PROPERTY NAMES on the schema, not a flag on each
 * property, and the reason is the one case that has to be catchable: a
 * nomination of a property the schema does not declare. A flag living on the
 * property could never name an absent property, so the mistake it is most
 * important to catch would be unrepresentable — and a typo would just silently
 * nominate nothing.
 *
 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-nominated-property-warns-when-its-value-already-exists-req-dmd-004
 */
class UniqueHintAnnotationValidator {

	/**
	 * Validate the `x-openregister-unique-hint` annotation against the
	 * properties the schema declares.
	 *
	 * @param array<string, mixed> $schema Shape with `properties` and `x-openregister-unique-hint`.
	 *
	 * @return array<int, array{code: string, property: string, message: string}> Errors; empty when valid.
	 *
	 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-nominated-property-warns-when-its-value-already-exists-req-dmd-004
	 */
	public function validate(array $schema): array {
		$annotation = ($schema['x-openregister-unique-hint'] ?? null);
		if ($annotation === null) {
			return [];
		}

		if (is_array($annotation) === false) {
			return [
				[
					'code' => 'unique-hint.not-list',
					'property' => '',
					'message' => 'x-openregister-unique-hint must be an array of property names.',
				],
			];
		}

		$properties = ($schema['properties'] ?? []);
		if (is_array($properties) === false) {
			$properties = [];
		}

		$errors = [];
		foreach ($annotation as $index => $name) {
			if (is_string($name) === false || $name === '') {
				$errors[] = [
					'code' => 'unique-hint.not-a-name',
					'property' => '',
					'message' => sprintf('x-openregister-unique-hint entry #%d must be a non-empty property name.', (int)$index),
				];
				continue;
			}

			if (array_key_exists($name, $properties) === true) {
				continue;
			}

			$errors[] = [
				'code' => 'unique-hint.unknown-property',
				'property' => $name,
				'message' => sprintf('x-openregister-unique-hint names "%s", which this schema does not declare.', $name),
			];
		}//end foreach

		return $errors;
	}//end validate()

	/**
	 * The property names a schema configuration nominates, cleaned.
	 *
	 * Reading is separated from validating so the save path never has to
	 * repeat the shape guards: by the time an object is written the schema has
	 * already been through {@see validate()}, and a nomination that survived
	 * that is a declared property name.
	 *
	 * @param array<string, mixed> $configuration The schema configuration block.
	 *
	 * @return array<int, string> The nominated property names.
	 *
	 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-nominated-property-warns-when-its-value-already-exists-req-dmd-004
	 */
	public function nominated(array $configuration): array {
		$annotation = ($configuration['x-openregister-unique-hint'] ?? null);
		if (is_array($annotation) === false) {
			return [];
		}

		$names = [];
		foreach ($annotation as $name) {
			if (is_string($name) === true && $name !== '') {
				$names[] = $name;
			}
		}

		return array_values(array_unique($names));
	}//end nominated()
}//end class
