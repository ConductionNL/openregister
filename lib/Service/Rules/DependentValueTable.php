<?php

/**
 * OpenRegister DependentValueTable
 *
 * The `x-openregister-dependent-values` annotation: which values of one
 * property are allowed follows from the value chosen in another, administered
 * as a table of pairs rather than as one rule per pair.
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
 * One table, read at schema save and at object save.
 *
 * WHY A TABLE AND NOT A RULE PER PAIR. zaaktype to resultaattype is the Dutch
 * case, and a municipality's Selectielijst has hundreds of pairs in it. Written
 * as conditions that is hundreds of rules in the inventory, none of which can
 * be read as the table it is. Written as a table it is one rule, and the
 * inventory can show an administrator what it actually allows.
 *
 * WHAT AN UNLISTED CONTROLLING VALUE MEANS. The table is a positive list per
 * controlling value: a controlling value the table does not mention leaves the
 * dependent property unconstrained. That is deliberate and it is what lets a
 * table be introduced for the three zaaktypen that need one without having to
 * enumerate every other zaaktype in the register first. A table that must
 * constrain everything is written by giving every controlling value an entry.
 *
 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
 */
final class DependentValueTable {

	/**
	 * The property annotation this class reads.
	 *
	 * @var string
	 */
	public const ANNOTATION = 'x-openregister-dependent-values';

	/**
	 * The refusal code an object save carries when a value is outside the table.
	 *
	 * @var string
	 */
	public const CODE_VALUE_NOT_ALLOWED = 'dependent-value-not-allowed';

	/**
	 * Every declared table, keyed by the property it constrains.
	 *
	 * Malformed declarations are skipped here rather than raised: schema save
	 * is where a malformed table is refused, and the runtime read must not
	 * throw on data that was somehow stored anyway.
	 *
	 * @param array<string, mixed> $properties The schema's properties block.
	 *
	 * @return array<string, array{controlledBy: string, allowed: array<string, array<int, string>>}> The tables.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function tablesOf(array $properties): array {
		$tables = [];
		foreach ($properties as $name => $property) {
			if (is_array($property) === false || isset($property[self::ANNOTATION]) === false) {
				continue;
			}

			$table = $this->tableOf(declaration: $property[self::ANNOTATION]);
			if ($table === null) {
				continue;
			}

			$tables[(string)$name] = $table;
		}

		return $tables;
	}//end tablesOf()

	/**
	 * One declaration, read into the shape the runtime uses, or null.
	 *
	 * A malformed declaration answers null rather than throwing. Schema save is
	 * where a malformed table is refused; this read must survive data that was
	 * somehow stored anyway, because the alternative is one bad annotation
	 * making every object of that schema unsaveable.
	 *
	 * @param mixed $declaration The raw annotation value.
	 *
	 * @return array{controlledBy: string, allowed: array<string, array<int, string>>}|null The table, or null.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	private function tableOf(mixed $declaration): ?array {
		if (is_array($declaration) === false) {
			return null;
		}

		$controlledBy = ($declaration['controlledBy'] ?? null);
		$allowed = ($declaration['allowed'] ?? null);
		if (is_string($controlledBy) === false || $controlledBy === '' || is_array($allowed) === false) {
			return null;
		}

		$pairs = [];
		foreach ($allowed as $controllingValue => $values) {
			if (is_array($values) === true) {
				$pairs[(string)$controllingValue] = array_values(array_map('strval', $values));
			}
		}

		return ['controlledBy' => $controlledBy, 'allowed' => $pairs];
	}//end tableOf()

	/**
	 * The properties whose tables the object being saved breaks.
	 *
	 * The controlling value is read from the payload when the payload carries
	 * it and from the stored object otherwise, because a PATCH that changes
	 * only the dependent property is still judged against the zaaktype the
	 * object actually has.
	 *
	 * @param array<string, mixed> $properties The schema's properties block.
	 * @param array<string, mixed> $data The object as it would be saved.
	 * @param array<string, mixed> $stored The object as currently stored, or an empty array on create.
	 *
	 * @return array<int, array{property: string, controlledBy: string, value: string, controllingValue: string, message: string}> The violations.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function violations(array $properties, array $data, array $stored = []): array {
		$violations = [];
		foreach ($this->tablesOf(properties: $properties) as $name => $table) {
			$value = ($data[$name] ?? null);
			if ($value === null || $value === '' || is_scalar($value) === false) {
				// Nothing was proposed for the dependent property, so the table
				// has nothing to say. Whether the property may be empty at all
				// is `required`'s question, not this one's.
				continue;
			}

			$controllingValue = $this->controllingValue(
				name: $table['controlledBy'],
				data: $data,
				stored: $stored
			);
			if ($controllingValue === null) {
				continue;
			}

			if (array_key_exists($controllingValue, $table['allowed']) === false) {
				// An unlisted controlling value constrains nothing. See the
				// class docblock: that is what makes a partial table usable.
				continue;
			}

			if (in_array((string)$value, $table['allowed'][$controllingValue], true) === true) {
				continue;
			}

			$violations[] = [
				'property' => $name,
				'controlledBy' => $table['controlledBy'],
				'value' => (string)$value,
				'controllingValue' => $controllingValue,
				'message' => sprintf(
					'"%s" cannot be "%s" while "%s" is "%s". Allowed: %s.',
					$name,
					(string)$value,
					$table['controlledBy'],
					$controllingValue,
					implode(', ', $table['allowed'][$controllingValue])
				),
			];
		}//end foreach

		return $violations;
	}//end violations()

	/**
	 * Whether any property of this schema declares a table at all.
	 *
	 * The save path asks this before it does anything else, so a schema that
	 * declares no table costs one array scan and no database read, which is
	 * the backwards compatibility task 5.3 asserts.
	 *
	 * @param array<string, mixed> $properties The schema's properties block.
	 *
	 * @return bool True when at least one property declares a table.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function declaresAny(array $properties): bool {
		foreach ($properties as $property) {
			if (is_array($property) === true && isset($property[self::ANNOTATION]) === true) {
				return true;
			}
		}

		return false;
	}//end declaresAny()

	/**
	 * The controlling property's value, from the payload or from the store.
	 *
	 * @param string $name The controlling property's name.
	 * @param array<string, mixed> $data The object as it would be saved.
	 * @param array<string, mixed> $stored The object as currently stored.
	 *
	 * @return string|null The value as a string, or null when there is none to read.
	 */
	private function controllingValue(string $name, array $data, array $stored): ?string {
		$value = ($data[$name] ?? null);
		if ($value === null) {
			$value = ($stored[$name] ?? null);
		}

		if ($value === null || is_scalar($value) === false) {
			return null;
		}

		return (string)$value;
	}//end controllingValue()
}//end class
