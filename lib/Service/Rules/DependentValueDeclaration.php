<?php

/**
 * OpenRegister DependentValueDeclaration
 *
 * One property's dependent value table as declared, read for the schema-save check.
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
 * The raw table a property declares, with the facts the validator asks about it.
 *
 * Nothing here judges the table: every accessor answers what was written,
 * and {@see DependentValueValidator} decides whether that is sound.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class DependentValueDeclaration {

	/**
	 * Constructor.
	 *
	 * @param string               $name       The property the table is declared on.
	 * @param array<string, mixed> $properties Every declared property of the schema.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $name,
		public readonly array $properties,
	) {
	}//end __construct()

	/**
	 * The raw annotation value.
	 *
	 * @return mixed The table as declared.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function table(): mixed {
		return $this->properties[$this->name][DependentValueTable::ANNOTATION];
	}//end table()

	/**
	 * One key of the table, when the table is a map.
	 *
	 * @param string $key The key, `controlledBy` or `allowed`.
	 *
	 * @return mixed The value, or null.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function entry(string $key): mixed {
		$table = $this->table();
		if (is_array($table) === false) {
			return null;
		}

		return ($table[$key] ?? null);
	}//end entry()

	/**
	 * The values paired with one controlling value.
	 *
	 * @param string $controllingValue The controlling value, as a key of `allowed`.
	 *
	 * @return mixed The listed values, as declared.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function valuesFor(string $controllingValue): mixed {
		return $this->entry(key: 'allowed')[$controllingValue];
	}//end valuesFor()

	/**
	 * The controlling property's enum, or null when it declares none.
	 *
	 * @return array<int, string>|null The enum.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function controllingEnum(): ?array {
		return $this->enumOf(property: ($this->properties[(string)$this->entry(key: 'controlledBy')] ?? []));
	}//end controllingEnum()

	/**
	 * The dependent property's own enum, or null when it declares none.
	 *
	 * @return array<int, string>|null The enum.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function ownEnum(): ?array {
		return $this->enumOf(property: $this->properties[$this->name]);
	}//end ownEnum()

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

		$enum = array_values(array_map('strval', array_filter($property['enum'], 'is_scalar')));
		if ($enum === []) {
			return null;
		}

		return $enum;
	}//end enumOf()
}//end class
