<?php

/**
 * OpenRegister DependentValuePairCheck
 *
 * Checks the pairs of a dependent value table against the two enums, at schema save.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

/**
 * Every pair of a well-shaped table, judged against what both properties can take.
 *
 * Asked by {@see DependentValueValidator} once the table's shape is known to
 * hold: `controlledBy` names a declared property and `allowed` is a non-empty map.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class DependentValuePairCheck {

	/**
	 * The sentence refusing a listed value: property, value, property, controlling value.
	 *
	 * @var string
	 */
	private const UNKNOWN_VALUE = 'Property "%s": "%s" is not a value "%s" can take, so pairing it with "%s" '
		. 'allows a value the property already refuses.';

	/**
	 * The errors of every pair, in the order they are declared.
	 *
	 * @param DependentValueDeclaration $declaration The table as declared.
	 *
	 * @return array<int, array{code: string, message: string}> The errors.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function errors(DependentValueDeclaration $declaration): array {
		$errors = [];
		foreach (array_keys($declaration->entry(key: 'allowed')) as $controllingValue) {
			$errors = array_merge($errors, $this->pairErrors(declaration: $declaration, controllingValue: (string)$controllingValue));
		}

		return $errors;
	}//end errors()

	/**
	 * The errors of one pair.
	 *
	 * @param DependentValueDeclaration $declaration      The table as declared.
	 * @param string                    $controllingValue The pair's controlling value.
	 *
	 * @return array<int, array{code: string, message: string}> The errors.
	 */
	private function pairErrors(DependentValueDeclaration $declaration, string $controllingValue): array {
		$values = $declaration->valuesFor(controllingValue: $controllingValue);
		if (is_array($values) === false || $values === []) {
			$message = 'Property "%s": "%s" must be paired with a non-empty list of allowed values.';
			return [$this->error(code: DependentValueValidator::CODE_MALFORMED, message: sprintf($message, $declaration->name, $controllingValue))];
		}

		return array_merge(
			$this->controllingValueErrors(declaration: $declaration, controllingValue: $controllingValue),
			$this->listedValueErrors(declaration: $declaration, controllingValue: $controllingValue)
		);
	}//end pairErrors()

	/**
	 * Refuse a controlling value the controlling property cannot take.
	 *
	 * @param DependentValueDeclaration $declaration      The table as declared.
	 * @param string                    $controllingValue The pair's controlling value.
	 *
	 * @return array<int, array{code: string, message: string}> The error, or none.
	 */
	private function controllingValueErrors(DependentValueDeclaration $declaration, string $controllingValue): array {
		$enum = $declaration->controllingEnum();
		if ($enum === null || in_array($controllingValue, $enum, true) === true) {
			return [];
		}

		$message = sprintf(
			'Property "%s": "%s" is not a value "%s" can take.',
			$declaration->name,
			$controllingValue,
			(string)$declaration->entry(key: 'controlledBy')
		);

		return [$this->error(code: DependentValueValidator::CODE_UNKNOWN_CONTROLLING_VALUE, message: $message)];
	}//end controllingValueErrors()

	/**
	 * Refuse every listed value the dependent property itself cannot take.
	 *
	 * @param DependentValueDeclaration $declaration      The table as declared.
	 * @param string                    $controllingValue The pair's controlling value.
	 *
	 * @return array<int, array{code: string, message: string}> The errors.
	 */
	private function listedValueErrors(DependentValueDeclaration $declaration, string $controllingValue): array {
		$errors = [];
		foreach ($this->refusedValues(declaration: $declaration, controllingValue: $controllingValue) as $rendered) {
			$message = sprintf(self::UNKNOWN_VALUE, $declaration->name, $rendered, $declaration->name, $controllingValue);
			$errors[] = $this->error(code: DependentValueValidator::CODE_UNKNOWN_VALUE, message: $message);
		}

		return $errors;
	}//end listedValueErrors()

	/**
	 * The listed values the dependent property's own enum does not hold, rendered.
	 *
	 * @param DependentValueDeclaration $declaration      The table as declared.
	 * @param string                    $controllingValue The pair's controlling value.
	 *
	 * @return array<int, string> The refused values, in the order they are listed; none when the property declares no enum.
	 */
	private function refusedValues(DependentValueDeclaration $declaration, string $controllingValue): array {
		$enum = $declaration->ownEnum();
		if ($enum === null) {
			return [];
		}

		$refused = [];
		foreach ($declaration->valuesFor(controllingValue: $controllingValue) as $value) {
			if (is_scalar($value) === false || in_array((string)$value, $enum, true) === false) {
				$refused[] = $this->rendered(value: $value);
			}
		}

		return $refused;
	}//end refusedValues()

	/**
	 * One listed value, rendered for the message that refuses it.
	 *
	 * A non-scalar is named by its type rather than serialised: the author
	 * needs to know an array turned up where a value belongs, not to read it.
	 *
	 * @param mixed $value The listed value.
	 *
	 * @return string The rendering.
	 */
	private function rendered(mixed $value): string {
		if (is_scalar($value) === true) {
			return (string)$value;
		}

		return gettype($value);
	}//end rendered()

	/**
	 * Shape one error.
	 *
	 * @param string $code    One of the DependentValueValidator CODE_ constants.
	 * @param string $message The sentence that names the pair and what is wrong with it.
	 *
	 * @return array{code: string, message: string} The error.
	 */
	private function error(string $code, string $message): array {
		return ['code' => $code, 'message' => $message];
	}//end error()
}//end class
