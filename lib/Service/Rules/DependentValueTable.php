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
 * @spec openspec/specs/object-lifecycle/spec.md
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
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class DependentValueTable implements DependentValueTableInterface {

	/**
	 * The property annotation this class reads.
	 *
	 * @var string
	 */
	public const ANNOTATION = 'x-openregister-dependent-values';

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
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function tablesOf(array $properties): array {
		$tables = [];
		foreach ((new AnnotatedProperties())->annotations(properties: $properties, annotation: self::ANNOTATION) as $name => $declaration) {
			$table = $this->tableOf(declaration: $declaration);
			if ($table !== null) {
				$tables[(string)$name] = $table;
			}
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
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function tableOf(mixed $declaration): ?array {
		$controlledBy = $this->controlledByOf(declaration: $declaration);
		if ($controlledBy === null || is_array(($declaration['allowed'] ?? null)) === false) {
			return null;
		}

		return ['controlledBy' => $controlledBy, 'allowed' => $this->pairsOf(allowed: $declaration['allowed'])];
	}//end tableOf()

	/**
	 * The property a declaration names as controlling, when it names one.
	 *
	 * @param mixed $declaration The raw annotation value.
	 *
	 * @return string|null The controlling property, or null when the declaration names none.
	 */
	private function controlledByOf(mixed $declaration): ?string {
		$controlledBy = null;
		if (is_array($declaration) === true) {
			$controlledBy = ($declaration['controlledBy'] ?? null);
		}

		if (is_string($controlledBy) === true && $controlledBy !== '') {
			return $controlledBy;
		}

		return null;
	}//end controlledByOf()

	/**
	 * The pairs of a table, with every listed value as a string.
	 *
	 * @param array<mixed, mixed> $allowed The raw pairs.
	 *
	 * @return array<string, array<int, string>> Controlling value => allowed values; a pair that is not a list is skipped.
	 */
	private function pairsOf(array $allowed): array {
		$pairs = [];
		foreach ($allowed as $controllingValue => $values) {
			if (is_array($values) === true) {
				$pairs[(string)$controllingValue] = array_values(array_map('strval', $values));
			}
		}

		return $pairs;
	}//end pairsOf()

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
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function violations(array $properties, array $data, array $stored = []): array {
		$write = new DependentValueWrite(data: $data, stored: $stored);
		$violations = [];
		foreach ($this->tablesOf(properties: $properties) as $name => $table) {
			$violation = $this->violationOf(table: ['property' => $name] + $table, write: $write);
			if ($violation !== null) {
				$violations[] = $violation;
			}
		}

		return $violations;
	}//end violations()

	/**
	 * The violation one table finds in a write, or null when the write keeps to it.
	 *
	 * @param array{property: string|int, controlledBy: string, allowed: array<string, array<int, string>>} $table The table,
	 *   with the property it constrains.
	 * @param DependentValueWrite $write The save being judged.
	 *
	 * @return array{property: string, controlledBy: string, value: string, controllingValue: string, message: string}|null The violation.
	 */
	private function violationOf(array $table, DependentValueWrite $write): ?array {
		$value = $write->proposed(name: $table['property']);
		$controllingValue = $write->controllingValue(name: $table['controlledBy']);
		if ($value === null || $controllingValue === null) {
			return null;
		}

		// An unlisted controlling value constrains nothing, so it allows the
		// value proposed. See the class docblock: that is what makes a partial
		// table usable.
		if (in_array($value, ($table['allowed'][$controllingValue] ?? [$value]), true) === true) {
			return null;
		}

		return $this->violation(found: ['value' => $value, 'controllingValue' => $controllingValue] + $table);
	}//end violationOf()

	/**
	 * Shape one violation, with the sentence that names both properties.
	 *
	 * @param array<string, mixed> $found The property, controlledBy, value, controllingValue and the table's allowed pairs.
	 *
	 * @return array{property: string, controlledBy: string, value: string, controllingValue: string, message: string} The violation.
	 */
	private function violation(array $found): array {
		return [
			'property' => $found['property'],
			'controlledBy' => $found['controlledBy'],
			'value' => $found['value'],
			'controllingValue' => $found['controllingValue'],
			'message' => $this->message(found: $found),
		];
	}//end violation()

	/**
	 * The sentence a violation carries: both properties, both values, and what is allowed.
	 *
	 * @param array<string, mixed> $found The property, controlledBy, value, controllingValue and the table's allowed pairs.
	 *
	 * @return string The sentence.
	 */
	private function message(array $found): string {
		return sprintf(
			'"%s" cannot be "%s" while "%s" is "%s". Allowed: %s.',
			$found['property'],
			$found['value'],
			$found['controlledBy'],
			$found['controllingValue'],
			implode(', ', $found['allowed'][$found['controllingValue']])
		);
	}//end message()

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
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function declaresAny(array $properties): bool {
		return (new AnnotatedProperties())->declares(properties: $properties, annotation: self::ANNOTATION);
	}//end declaresAny()
}//end class
