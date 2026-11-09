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

namespace OCA\OpenRegister\Search\Service\Quality;

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
 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-nominated-property-warns-when-its-value-already-exists-req-dmd-004
 */
class UniqueHintAnnotationValidator implements UniqueHintAnnotationValidatorInterface {

	/**
	 * Validate the `x-openregister-unique-hint` annotation against the
	 * properties the schema declares.
	 *
	 * @param array<string, mixed> $schema Shape with `properties` and `x-openregister-unique-hint`.
	 *
	 * @return array<int, array{code: string, property: string, message: string}> Errors; empty when valid.
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-nominated-property-warns-when-its-value-already-exists-req-dmd-004
	 */
	public function validate(array $schema): array {
		$annotation = ($schema['x-openregister-unique-hint'] ?? null);
		if ($annotation === null) {
			return [];
		}

		if (is_array($annotation) === false) {
			return [$this->error(code: 'unique-hint.not-list', message: 'x-openregister-unique-hint must be an array of property names.')];
		}

		$properties = ($schema['properties'] ?? []);
		if (is_array($properties) === false) {
			$properties = [];
		}

		return $this->entryErrors(annotation: $annotation, properties: $properties);
	}//end validate()

	/**
	 * One error per entry that is not a non-empty name, or names a property
	 * the schema does not declare, in annotation order.
	 *
	 * @param array<mixed>         $annotation The annotation list.
	 * @param array<string, mixed> $properties The declared properties.
	 *
	 * @return array<int, array{code: string, property: string, message: string}>
	 */
	private function entryErrors(array $annotation, array $properties): array {
		$errors = [];
		foreach ($annotation as $index => $name) {
			if ($this->isName(name: $name) === false) {
				$message = sprintf('x-openregister-unique-hint entry #%d must be a non-empty property name.', (int)$index);
				$errors[] = $this->error(code: 'unique-hint.not-a-name', message: $message);
			} else if (array_key_exists($name, $properties) === false) {
				$message = sprintf('x-openregister-unique-hint names "%s", which this schema does not declare.', $name);
				$errors[] = array_replace($this->error(code: 'unique-hint.unknown-property', message: $message), ['property' => $name]);
			}
		}

		return $errors;
	}//end entryErrors()

	/**
	 * One validation error; `property` is '' unless the caller replaces it.
	 *
	 * @param string $code    The error code.
	 * @param string $message The error message.
	 *
	 * @return array{code: string, property: string, message: string}
	 */
	private function error(string $code, string $message): array {
		return ['code' => $code, 'property' => '', 'message' => $message];
	}//end error()

	/**
	 * Whether an annotation entry is a non-empty string.
	 *
	 * @param mixed $name The entry.
	 *
	 * @return bool
	 */
	private function isName(mixed $name): bool {
		return is_string($name) === true && $name !== '';
	}//end isName()

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
	 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-nominated-property-warns-when-its-value-already-exists-req-dmd-004
	 */
	public function nominated(array $configuration): array {
		$annotation = ($configuration['x-openregister-unique-hint'] ?? null);
		if (is_array($annotation) === false) {
			return [];
		}

		return array_values(array_unique(array_filter($annotation, fn (mixed $name): bool => $this->isName(name: $name))));
	}//end nominated()
}//end class
