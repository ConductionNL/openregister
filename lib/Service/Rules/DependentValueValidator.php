<?php

/**
 * OpenRegister DependentValueValidator
 *
 * Refuses a dependent value table that names a property the schema does not
 * declare or a value the controlling property cannot take, at schema save.
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
 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

/**
 * Every way a table can be wrong, refused where the author can still fix it.
 *
 * A table naming a property that does not exist is not a rule that never
 * fires, it is a rule that constrains nothing, and nothing about the saved
 * object says so. That is the silent no-op this validator exists to turn into
 * a refusal at the moment the table is written.
 *
 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
 */
final class DependentValueValidator {

	/**
	 * The table is not an object, or `allowed` is not a map of lists.
	 *
	 * @var string
	 */
	public const CODE_MALFORMED = 'dependent-values-malformed';

	/**
	 * `controlledBy` names a property the schema does not declare.
	 *
	 * @var string
	 */
	public const CODE_UNKNOWN_PROPERTY = 'dependent-values-unknown-property';

	/**
	 * A key of `allowed` is a value the controlling property cannot take.
	 *
	 * @var string
	 */
	public const CODE_UNKNOWN_CONTROLLING_VALUE = 'dependent-values-unknown-controlling-value';

	/**
	 * A listed value is one the dependent property itself cannot take.
	 *
	 * @var string
	 */
	public const CODE_UNKNOWN_VALUE = 'dependent-values-unknown-value';

	/**
	 * `controlledBy` names the property the table is declared on.
	 *
	 * @var string
	 */
	public const CODE_SELF_CONTROLLED = 'dependent-values-self-controlled';

	/**
	 * Validate every dependent value table a schema declares.
	 *
	 * @param array<string, mixed> $schema The schema shape, with its `properties` block.
	 *
	 * @return array<int, array{code: string, message: string}> The errors, empty when every table is sound.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) One branch per way a table can be
	 *   wrong, each with the message that tells the author which one it is. Splitting
	 *   them would move the branch, not remove it.
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function validate(array $schema): array {
		$properties = ($schema['properties'] ?? []);
		if (is_array($properties) === false) {
			return [];
		}

		$errors = [];
		foreach ($properties as $name => $property) {
			if (is_array($property) === false
				|| array_key_exists(DependentValueTable::ANNOTATION, $property) === false
			) {
				continue;
			}

			$this->validateTable(
				name: (string)$name,
				table: $property[DependentValueTable::ANNOTATION],
				property: $property,
				properties: $properties,
				errors: $errors
			);
		}

		return $errors;
	}//end validate()

	/**
	 * Validate one property's table.
	 *
	 * @param string $name The property the table is declared on.
	 * @param mixed $table The raw annotation value.
	 * @param array<string, mixed> $property The property's own declaration.
	 * @param array<string, mixed> $properties Every declared property.
	 * @param array<int, array{code: string, message: string}> $errors Mutable error accumulator.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) See validate().
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	private function validateTable(
		string $name,
		mixed $table,
		array $property,
		array $properties,
		array &$errors,
	): void {
		if (is_array($table) === false || $table === []) {
			$errors[] = [
				'code' => self::CODE_MALFORMED,
				'message' => sprintf(
					'Property "%s": %s must be an object with "controlledBy" and "allowed".',
					$name,
					DependentValueTable::ANNOTATION
				),
			];
			return;
		}

		$controlledBy = ($table['controlledBy'] ?? null);
		if (is_string($controlledBy) === false || $controlledBy === '') {
			$errors[] = [
				'code' => self::CODE_MALFORMED,
				'message' => sprintf('Property "%s": "controlledBy" must name a property.', $name),
			];
			return;
		}

		if ($controlledBy === $name) {
			$errors[] = [
				'code' => self::CODE_SELF_CONTROLLED,
				'message' => sprintf(
					'Property "%s" cannot control its own allowed values.',
					$name
				),
			];
			return;
		}

		if (array_key_exists($controlledBy, $properties) === false) {
			$errors[] = [
				'code' => self::CODE_UNKNOWN_PROPERTY,
				'message' => sprintf(
					'Property "%s": "controlledBy" names "%s", which this schema does not declare.',
					$name,
					$controlledBy
				),
			];
			return;
		}

		$allowed = ($table['allowed'] ?? null);
		if (is_array($allowed) === false || $allowed === []) {
			$errors[] = [
				'code' => self::CODE_MALFORMED,
				'message' => sprintf(
					'Property "%s": "allowed" must pair at least one value of "%s" with the values it permits.',
					$name,
					$controlledBy
				),
			];
			return;
		}

		$this->validatePairs(
			name: $name,
			controlledBy: $controlledBy,
			allowed: $allowed,
			controllingEnum: $this->enumOf(property: ($properties[$controlledBy] ?? [])),
			ownEnum: $this->enumOf(property: $property),
			errors: $errors
		);
	}//end validateTable()

	/**
	 * Validate the pairs of one table against the two enums.
	 *
	 * @param string $name The dependent property.
	 * @param string $controlledBy The controlling property.
	 * @param array<mixed, mixed> $allowed The raw pairs.
	 * @param array<int, string>|null $controllingEnum The controlling property's enum, or null when it declares none.
	 * @param array<int, string>|null $ownEnum The dependent property's enum, or null when it declares none.
	 * @param array<int, array{code: string, message: string}> $errors Mutable error accumulator.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Five facts about one table, all read.
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) See validate().
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	private function validatePairs(
		string $name,
		string $controlledBy,
		array $allowed,
		?array $controllingEnum,
		?array $ownEnum,
		array &$errors,
	): void {
		foreach ($allowed as $controllingValue => $values) {
			$controllingValue = (string)$controllingValue;

			if (is_array($values) === false || $values === []) {
				$errors[] = [
					'code' => self::CODE_MALFORMED,
					'message' => sprintf(
						'Property "%s": "%s" must be paired with a non-empty list of allowed values.',
						$name,
						$controllingValue
					),
				];
				continue;
			}

			if ($controllingEnum !== null && in_array($controllingValue, $controllingEnum, true) === false) {
				$errors[] = [
					'code' => self::CODE_UNKNOWN_CONTROLLING_VALUE,
					'message' => sprintf(
						'Property "%s": "%s" is not a value "%s" can take.',
						$name,
						$controllingValue,
						$controlledBy
					),
				];
			}

			if ($ownEnum === null) {
				continue;
			}

			foreach ($values as $value) {
				if (is_scalar($value) === true && in_array((string)$value, $ownEnum, true) === true) {
					continue;
				}

				$errors[] = [
					'code' => self::CODE_UNKNOWN_VALUE,
					'message' => sprintf(
						'Property "%s": "%s" is not a value "%s" can take, so pairing it with "%s" '
						. 'allows a value the property already refuses.',
						$name,
						(is_scalar($value) === true ? (string)$value : gettype($value)),
						$name,
						$controllingValue
					),
				];
			}
		}//end foreach
	}//end validatePairs()

	/**
	 * A property's enum as a list of strings, or null when it declares none.
	 *
	 * @param mixed $property The property declaration.
	 *
	 * @return array<int, string>|null The enum, or null.
	 */
	private function enumOf(mixed $property): ?array {
		if (is_array($property) === false || is_array(($property['enum'] ?? null)) === false) {
			return null;
		}

		$enum = [];
		foreach ($property['enum'] as $value) {
			if (is_scalar($value) === true) {
				$enum[] = (string)$value;
			}
		}

		if ($enum === []) {
			return null;
		}

		return $enum;
	}//end enumOf()
}//end class
