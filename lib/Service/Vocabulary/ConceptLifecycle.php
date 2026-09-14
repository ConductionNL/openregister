<?php

/**
 * OpenRegister ConceptLifecycle
 *
 * A code-list value is retired, never removed. This class is the one place
 * that answers what "retired" means, and it answers it twice on purpose:
 * a concept outside its validity window is no longer OFFERABLE, and it is
 * still RESOLVABLE. Those are different questions, and conflating them is
 * what breaks a dossier from 2019 when a gemeente retires a resultaattype
 * in 2026 (design.md D-1).
 *
 * `deprecated`, which the importer already sets, answers "the source dropped
 * it". A validity window answers "the organisation retired it on this date".
 * Both are kept, because they are not the same fact.
 *
 * Everything here is pure: it takes a concept's decoded object data and
 * returns an answer. No database, no request, no clock of its own, which is
 * what lets the window be tested at a named instant rather than at "now".
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vocabulary
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Vocabulary;

use DateTimeImmutable;
use DateTimeInterface;
use Throwable;

/**
 * Reads the lifecycle facts off a concept: its window, its weight, its
 * exclusive group, and whether the scheme's declared shape is satisfied.
 */
class ConceptLifecycle {

	/**
	 * The concept property holding the first instant the value may be chosen.
	 *
	 * @var string
	 */
	public const FIELD_VALID_FROM = 'validFrom';

	/**
	 * The concept property holding the last instant the value may be chosen.
	 *
	 * @var string
	 */
	public const FIELD_VALID_UNTIL = 'validUntil';

	/**
	 * The concept property naming the mutually exclusive group it belongs to.
	 *
	 * @var string
	 */
	public const FIELD_EXCLUSIVE_GROUP = 'exclusiveGroup';

	/**
	 * The concept property carrying its numeric weight.
	 *
	 * @var string
	 */
	public const FIELD_WEIGHT = 'weight';

	/**
	 * The concept property marking a value the product itself defines.
	 *
	 * @var string
	 */
	public const FIELD_SYSTEM_DEFINED = 'systemDefined';

	/**
	 * The concept property holding the scheme-declared fields of this value.
	 *
	 * @var string
	 */
	public const FIELD_FIELDS = 'fields';

	/**
	 * The conceptScheme property declaring the shape of its concepts.
	 *
	 * @var string
	 */
	public const SCHEME_FIELD_SHAPE = 'conceptShape';

	/**
	 * Whether the concept may be OFFERED as an option at the given instant.
	 *
	 * A deprecated concept and an out-of-window concept are both unofferable,
	 * for different reasons, and a caller that wants the reason asks
	 * {@see self::describeWindow()}.
	 *
	 * @param array<string,mixed> $concept The concept's decoded object data.
	 * @param DateTimeInterface $at The instant to judge the window at.
	 * @param boolean $allowDeprecated Whether a deprecated concept still counts as offerable.
	 *
	 * @return boolean True when the value may be put in front of someone.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
	 */
	public function isOfferable(array $concept, DateTimeInterface $at, bool $allowDeprecated = false): bool {
		if ($allowDeprecated === false && ($concept['deprecated'] ?? false) === true) {
			return false;
		}

		return $this->isWithinWindow(concept: $concept, at: $at);
	}//end isOfferable()

	/**
	 * Whether the concept's validity window contains the given instant.
	 *
	 * A concept with neither bound is always within its window, which is what
	 * makes every scheme written before this change behave exactly as it did.
	 * An unparseable bound is treated as absent rather than as closed: a typo
	 * in an administered date must not silently retire a live value.
	 *
	 * @param array<string,mixed> $concept The concept's decoded object data.
	 * @param DateTimeInterface $at The instant to judge.
	 *
	 * @return boolean True when `$at` is inside the window.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
	 */
	public function isWithinWindow(array $concept, DateTimeInterface $at): bool {
		$from = $this->parseBound(value: ($concept[self::FIELD_VALID_FROM] ?? null));
		$until = $this->parseBound(value: ($concept[self::FIELD_VALID_UNTIL] ?? null));

		if ($from !== null && $at < $from) {
			return false;
		}

		if ($until !== null && $at > $until) {
			return false;
		}

		return true;
	}//end isWithinWindow()

	/**
	 * The window in words, for a refusal that has to name it.
	 *
	 * @param array<string,mixed> $concept The concept's decoded object data.
	 *
	 * @return string A human-readable window, e.g. "valid from 2019-01-01 until 2025-12-31".
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
	 */
	public function describeWindow(array $concept): string {
		$from = $this->parseBound(value: ($concept[self::FIELD_VALID_FROM] ?? null));
		$until = $this->parseBound(value: ($concept[self::FIELD_VALID_UNTIL] ?? null));

		if ($from === null && $until === null) {
			return 'no validity window';
		}

		if ($from !== null && $until !== null) {
			return 'valid from ' . $from->format('Y-m-d') . ' until ' . $until->format('Y-m-d');
		}

		if ($from !== null) {
			return 'valid from ' . $from->format('Y-m-d');
		}

		if ($until !== null) {
			return 'valid until ' . $until->format('Y-m-d');
		}

		return 'no validity window';
	}//end describeWindow()

	/**
	 * The concept's exclusive group, or null when it belongs to none.
	 *
	 * @param array<string,mixed> $concept The concept's decoded object data.
	 *
	 * @return string|null The group name.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
	 */
	public function exclusiveGroupOf(array $concept): ?string {
		$group = ($concept[self::FIELD_EXCLUSIVE_GROUP] ?? null);
		if (is_string($group) === false) {
			return null;
		}

		$group = trim($group);
		if ($group === '') {
			return null;
		}

		return $group;
	}//end exclusiveGroupOf()

	/**
	 * The concept's weight, defaulting to zero.
	 *
	 * A concept with no weight contributes nothing to a rolled-up score, which
	 * is the only reading under which a partially weighted scheme still adds up.
	 *
	 * @param array<string,mixed> $concept The concept's decoded object data.
	 *
	 * @return float The weight.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
	 */
	public function weightOf(array $concept): float {
		$weight = ($concept[self::FIELD_WEIGHT] ?? null);
		if (is_int($weight) === true || is_float($weight) === true) {
			return (float)$weight;
		}

		if (is_string($weight) === true && is_numeric($weight) === true) {
			return (float)$weight;
		}

		return 0.0;
	}//end weightOf()

	/**
	 * Whether the concept is one the product itself defines.
	 *
	 * A system-defined value may be retired by closing its window; it may not
	 * be deleted, because the code that switches on it does not stop existing
	 * when the row does.
	 *
	 * @param array<string,mixed> $concept The concept's decoded object data.
	 *
	 * @return boolean True when the value is system-defined.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
	 */
	public function isSystemDefined(array $concept): bool {
		return ($concept[self::FIELD_SYSTEM_DEFINED] ?? false) === true;
	}//end isSystemDefined()

	/**
	 * Sum the weights of the concepts an object holds.
	 *
	 * @param array<int,array<string,mixed>> $concepts The held concepts' decoded object data.
	 *
	 * @return float The rolled-up score.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
	 */
	public function rollUpWeights(array $concepts): float {
		$total = 0.0;
		foreach ($concepts as $concept) {
			if (is_array($concept) === false) {
				continue;
			}

			$total += $this->weightOf(concept: $concept);
		}

		return $total;
	}//end rollUpWeights()

	/**
	 * Validate a concept's own fields against the shape its scheme declares.
	 *
	 * The shape is a property map in the same dialect a schema uses, so a
	 * resultaattype scheme declaring `bewaartermijn` (integer, required) and
	 * `grondslag` (string, required) validates its concepts the way any other
	 * object is validated (design.md D-2). The return is the list of field
	 * names that failed, so a caller can name them, which is what the import
	 * report has to do.
	 *
	 * @param array<string,mixed> $concept The concept's decoded object data.
	 * @param array<string,mixed> $shape The scheme's declared concept shape.
	 *
	 * @return array<int,string> The names of the fields that are missing or ill-typed.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
	 */
	public function validateAgainstShape(array $concept, array $shape): array {
		$properties = ($shape['properties'] ?? null);
		if (is_array($properties) === false) {
			return [];
		}

		$required = ($shape['required'] ?? []);
		if (is_array($required) === false) {
			$required = [];
		}

		$fields = ($concept[self::FIELD_FIELDS] ?? []);
		if (is_array($fields) === false) {
			$fields = [];
		}

		$failed = [];
		foreach ($properties as $name => $definition) {
			$name = (string)$name;
			$present = array_key_exists($name, $fields);
			$value = ($fields[$name] ?? null);

			if (($present === false || $value === null) && in_array($name, $required, true) === true) {
				$failed[] = $name;
				continue;
			}

			if ($present === false || $value === null) {
				continue;
			}

			if ($this->matchesDeclaredType(value: $value, definition: $definition) === false) {
				$failed[] = $name;
			}
		}//end foreach

		return $failed;
	}//end validateAgainstShape()

	/**
	 * Whether a value satisfies the `type` a shape property declares.
	 *
	 * Only the JSON-Schema primitives a code-list field realistically uses are
	 * checked. An undeclared or unknown type accepts anything, because the
	 * shape is a contract the scheme's author writes, not one this class invents.
	 *
	 * @param mixed $value The submitted value.
	 * @param mixed $definition The shape property's definition.
	 *
	 * @return boolean True when the value satisfies the declared type.
	 */
	private function matchesDeclaredType(mixed $value, mixed $definition): bool {
		if (is_array($definition) === false) {
			return true;
		}

		$type = ($definition['type'] ?? null);
		if (is_string($type) === false) {
			return true;
		}

		return match ($type) {
			'string' => is_string($value),
			'integer' => is_int($value) || (is_string($value) === true && ctype_digit($value) === true),
			'number' => is_int($value) || is_float($value) || (is_string($value) === true && is_numeric($value) === true),
			'boolean' => is_bool($value),
			'array' => is_array($value),
			'object' => is_array($value),
			default => true,
		};
	}//end matchesDeclaredType()

	/**
	 * Parse a window bound, treating an unparseable value as absent.
	 *
	 * @param mixed $value The raw bound as stored.
	 *
	 * @return DateTimeImmutable|null The parsed instant, or null.
	 */
	private function parseBound(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false) {
			return null;
		}

		$value = trim($value);
		if ($value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Throwable $unparseable) {
			return null;
		}
	}//end parseBound()
}//end class
