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
 * @spec openspec/specs/object-lifecycle/spec.md
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
 * @spec openspec/specs/object-lifecycle/spec.md
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
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function validate(array $schema): array {
		$errors = [];
		foreach ($this->declarations(schema: $schema) as $declaration) {
			$errors = array_merge($errors, $this->tableErrors(declaration: $declaration));
		}

		return $errors;
	}//end validate()

	/**
	 * Every property of the schema that declares a table, in declaration order.
	 *
	 * @param array<string, mixed> $schema The schema shape, with its `properties` block.
	 *
	 * @return array<int, DependentValueDeclaration> The declared tables.
	 */
	private function declarations(array $schema): array {
		$declared = ($schema['properties'] ?? []);
		if (is_array($declared) === false) {
			return [];
		}

		$tables = [];
		foreach ($declared as $name => $property) {
			if (is_array($property) === true && array_key_exists(DependentValueTable::ANNOTATION, $property) === true) {
				$tables[] = new DependentValueDeclaration(name: (string)$name, properties: $declared);
			}
		}

		return $tables;
	}//end declarations()

	/**
	 * Validate one property's table: its shape first, then every pair.
	 *
	 * The first way the shape is wrong is the only one reported, because each
	 * check below it reads what the one above it proved.
	 *
	 * @param DependentValueDeclaration $declaration The table as declared.
	 *
	 * @return array<int, array{code: string, message: string}> The errors.
	 */
	private function tableErrors(DependentValueDeclaration $declaration): array {
		$refusal = ($this->objectRefusal(declaration: $declaration)
			?? $this->controlledByRefusal(declaration: $declaration)
			?? $this->controllerRefusal(declaration: $declaration)
			?? $this->allowedRefusal(declaration: $declaration));
		if ($refusal !== null) {
			return [$refusal];
		}

		return (new DependentValuePairCheck())->errors(declaration: $declaration);
	}//end tableErrors()

	/**
	 * Refuse a table that is not a non-empty object.
	 *
	 * @param DependentValueDeclaration $declaration The table as declared.
	 *
	 * @return array{code: string, message: string}|null The error, or null when the table is an object.
	 */
	private function objectRefusal(DependentValueDeclaration $declaration): ?array {
		$table = $declaration->table();
		if (is_array($table) === true && $table !== []) {
			return null;
		}

		$message = 'Property "%s": %s must be an object with "controlledBy" and "allowed".';

		return $this->error(code: self::CODE_MALFORMED, message: sprintf($message, $declaration->name, DependentValueTable::ANNOTATION));
	}//end objectRefusal()

	/**
	 * Refuse a table that names no controlling property.
	 *
	 * @param DependentValueDeclaration $declaration The table as declared.
	 *
	 * @return array{code: string, message: string}|null The error, or null when `controlledBy` names something.
	 */
	private function controlledByRefusal(DependentValueDeclaration $declaration): ?array {
		$controlledBy = $declaration->entry(key: 'controlledBy');
		if (is_string($controlledBy) === true && $controlledBy !== '') {
			return null;
		}

		$message = 'Property "%s": "controlledBy" must name a property.';

		return $this->error(code: self::CODE_MALFORMED, message: sprintf($message, $declaration->name));
	}//end controlledByRefusal()

	/**
	 * Refuse a table controlled by itself or by a property the schema does not declare.
	 *
	 * @param DependentValueDeclaration $declaration The table as declared, with a controlling property named.
	 *
	 * @return array{code: string, message: string}|null The error, or null when the controller holds.
	 */
	private function controllerRefusal(DependentValueDeclaration $declaration): ?array {
		$controlledBy = (string)$declaration->entry(key: 'controlledBy');
		if ($controlledBy === $declaration->name) {
			$message = 'Property "%s" cannot control its own allowed values.';
			return $this->error(code: self::CODE_SELF_CONTROLLED, message: sprintf($message, $declaration->name));
		}

		if (array_key_exists($controlledBy, $declaration->properties) === false) {
			$message = 'Property "%s": "controlledBy" names "%s", which this schema does not declare.';
			return $this->error(code: self::CODE_UNKNOWN_PROPERTY, message: sprintf($message, $declaration->name, $controlledBy));
		}

		return null;
	}//end controllerRefusal()

	/**
	 * Refuse a table whose `allowed` pairs nothing.
	 *
	 * @param DependentValueDeclaration $declaration The table as declared.
	 *
	 * @return array{code: string, message: string}|null The error, or null when `allowed` is a non-empty map.
	 */
	private function allowedRefusal(DependentValueDeclaration $declaration): ?array {
		$allowed = $declaration->entry(key: 'allowed');
		if (is_array($allowed) === true && $allowed !== []) {
			return null;
		}

		$message = 'Property "%s": "allowed" must pair at least one value of "%s" with the values it permits.';
		$controlledBy = (string)$declaration->entry(key: 'controlledBy');

		return $this->error(code: self::CODE_MALFORMED, message: sprintf($message, $declaration->name, $controlledBy));
	}//end allowedRefusal()

	/**
	 * Shape one error.
	 *
	 * @param string $code    One of the CODE_ constants.
	 * @param string $message The sentence that tells the author which way the table is wrong.
	 *
	 * @return array{code: string, message: string} The error.
	 */
	private function error(string $code, string $message): array {
		return ['code' => $code, 'message' => $message];
	}//end error()
}//end class
