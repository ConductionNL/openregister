<?php

/**
 * OpenRegister repeating-group validation.
 *
 * Enforces the bounds and the per-row shape of a property declared
 * `repeatingGroup: true`, and names the row and the member on every refusal.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/runtime-schema-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\Schema;

/**
 * Validates repeating groups row by row.
 *
 * WHY THIS IS NOT LEFT TO THE JSON-SCHEMA VALIDATOR.
 *
 * Opis already refuses an array of objects that does not match its `items`
 * schema, and it does so with a message built from a JSON pointer. "One of the
 * percelen is invalid" is not a message anybody can act on (D-2), and the
 * pointer is not a row number. The whole difference between an array and an
 * authorable repeating group is that a refusal says which row and which field.
 *
 * The second reason is that Opis only runs when the schema has hard validation
 * switched on, and most do not. A declared group is a declaration about what
 * the register holds, so its bounds hold on every write, not on the subset of
 * schemas whose administrator turned hard validation on.
 *
 * WHAT IT DELIBERATELY DOES NOT DO. It checks the members a group declares,
 * not the full JSON Schema vocabulary inside a row: formats, patterns, `$ref`
 * and the rest stay with the validator that already owns them. Duplicating
 * half of Opis here would leave two answers to the same question and no rule
 * about which one wins.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The complexity is one refusal per rule and one
 *              arm per checked type, each in its own method and each well under the method
 *              thresholds. Splitting the six-arm type match into a class of its own would move the
 *              number without making anything easier to read, and it is the only thing left to move.
 */
class RepeatingGroupValidator {

	/**
	 * The JSON-Schema types this validator checks a member value against.
	 *
	 * Everything else a member may be declared (file, geo, recurrence, the
	 * `Nc*` linked-entity types) is left to the validator that defines it.
	 *
	 * @var array<int, string>
	 */
	private const CHECKED_TYPES = ['string', 'number', 'integer', 'boolean', 'array', 'object'];

	/**
	 * Collect every repeating-group violation in one object.
	 *
	 * Returns a list, empty when the object is compliant. Callers MUST refuse
	 * the write when this returns a non-empty list.
	 *
	 * Each violation carries `property`, the group's name; `position`, the row
	 * counted from one as a person reads it, or null when the refusal is about
	 * the group as a whole; `member`, the field inside the row, or null; and a
	 * `message` that names all of them.
	 *
	 * @param array $object The candidate object body.
	 * @param Schema $schema The schema whose declarations drive enforcement.
	 *
	 * @return array<int, array{property: string, position: int|null, member: string|null, code: string, message: string}>
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function validate(array $object, Schema $schema): array {
		$violations = [];
		foreach ($this->declaredGroups(schema: $schema) as $name => $definition) {
			$name = (string)$name;

			// Omitted is not a violation. An absent group is the required-value
			// rule's business, and answering it here would refuse every partial
			// update that happens not to mention the group.
			if (array_key_exists($name, $object) === true) {
				$group = new RepeatingGroupPlace(name: $name, definition: $definition);
				$violations = array_merge($violations, $this->validateGroup(group: $group, value: $object[$name]));
			}
		}

		return $violations;
	}//end validate()

	/**
	 * The properties a schema declares as a repeating group.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array<int|string, array> The declarations by property name.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	private function declaredGroups(Schema $schema): array {
		$properties = $schema->getProperties();
		if (is_array($properties) === false) {
			return [];
		}

		return array_filter(
			$properties,
			fn ($definition): bool => is_array($definition) === true
				&& ($definition[Schema::REPEATING_GROUP_PROPERTY_KEYWORD] ?? false) === true
		);
	}//end declaredGroups()

	/**
	 * Validate one group's value: its shape, its bounds and its rows.
	 *
	 * @param RepeatingGroupPlace $group The group.
	 * @param mixed               $value The submitted value.
	 *
	 * @return array<int, array{property: string, position: int|null, member: string|null, code: string, message: string}>
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	private function validateGroup(RepeatingGroupPlace $group, mixed $value): array {
		$rows = $this->rowsOf(value: $value);
		if ($rows === null) {
			return [$group->violation(code: 'repeating-group-not-a-list', message: "'{$group->name}' is a repeating group, so it takes a list of rows.")];
		}

		$violations = $this->validateBounds(group: $group, count: count($rows));
		foreach ($rows as $index => $row) {
			$violations = array_merge($violations, $this->validateRow(place: $group->atRow(position: ((int)$index + 1)), row: $row));
		}

		return $violations;
	}//end validateGroup()

	/**
	 * The rows of a submitted value, or null when it is no list.
	 *
	 * An empty value clears the group. That is the minimum's business, never a
	 * shape complaint, so null and '' are the empty list.
	 *
	 * @param mixed $value The submitted value.
	 *
	 * @return array|null The rows.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	private function rowsOf(mixed $value): ?array {
		if (in_array($value, [null, ''], true) === true) {
			return [];
		}

		if (is_array($value) === false || array_is_list($value) === false) {
			return null;
		}

		return $value;
	}//end rowsOf()

	/**
	 * Enforce `minItems` and `maxItems` on a group.
	 *
	 * The bounds are the ordinary array keywords rather than keywords of their
	 * own, so a schema that already declares them keeps meaning exactly what it
	 * meant before this change.
	 *
	 * @param RepeatingGroupPlace $group The group.
	 * @param integer             $count How many rows were submitted.
	 *
	 * @return array<int, array{property: string, position: int|null, member: string|null, code: string, message: string}>
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	private function validateBounds(RepeatingGroupPlace $group, int $count): array {
		$violations = [];
		$name = $group->name;

		$minimum = $group->bound(keyword: 'minItems');
		if ($minimum !== null && $count < $minimum) {
			$violations[] = $group->violation(code: 'repeating-group-below-minimum', message: "'$name' needs at least $minimum rows. It was sent $count.");
		}

		$maximum = $group->bound(keyword: 'maxItems');
		if ($maximum !== null && $count > $maximum) {
			$violations[] = $group->violation(code: 'repeating-group-above-maximum', message: "'$name' takes at most $maximum rows. It was sent $count.");
		}

		return $violations;
	}//end validateBounds()

	/**
	 * Validate one row against the group's declared members.
	 *
	 * @param RepeatingGroupPlace $place The group at this row.
	 * @param mixed               $row   The submitted row.
	 *
	 * @return array<int, array{property: string, position: int|null, member: string|null, code: string, message: string}>
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	private function validateRow(RepeatingGroupPlace $place, mixed $row): array {
		if (is_array($row) === false || array_is_list($row) === true) {
			return [
				$place->violation(
					code: 'repeating-group-row-not-an-object',
					message: "Row {$place->position()} of '{$place->name}' has to be a set of fields."
				),
			];
		}

		return array_merge($this->missingMembers(place: $place, row: $row), $this->mistypedMembers(place: $place, row: $row));
	}//end validateRow()

	/**
	 * The members a row had to answer and did not.
	 *
	 * @param RepeatingGroupPlace $place The group at this row.
	 * @param array               $row   The submitted row.
	 *
	 * @return array<int, array{property: string, position: int|null, member: string|null, code: string, message: string}>
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	private function missingMembers(RepeatingGroupPlace $place, array $row): array {
		$violations = [];
		foreach ($place->required() as $member) {
			$member = (string)$member;
			if ($this->answers(row: $row, member: $member) === false) {
				$violations[] = $place->atMember(member: $member)->violation(
					code: 'repeating-group-member-required',
					message: "Row {$place->position()} of '{$place->name}' is missing '$member'."
				);
			}
		}

		return $violations;
	}//end missingMembers()

	/**
	 * The members a row answered with the wrong kind of value.
	 *
	 * An unanswered member is {@see self::missingMembers()}'s business, and a
	 * member whose declared type this class does not check is left to the
	 * validator that defines it.
	 *
	 * @param RepeatingGroupPlace $place The group at this row.
	 * @param array               $row   The submitted row.
	 *
	 * @return array<int, array{property: string, position: int|null, member: string|null, code: string, message: string}>
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	private function mistypedMembers(RepeatingGroupPlace $place, array $row): array {
		$violations = [];
		foreach ($place->members() as $member => $declaration) {
			$member = (string)$member;
			$expected = $this->checkableType(declaration: $declaration);
			if ($expected !== null && $this->answers(row: $row, member: $member) === true
				&& $this->matchesType(value: $row[$member], expected: $expected) === false
			) {
				$violations[] = $place->atMember(member: $member)->violation(
					code: 'repeating-group-member-type',
					message: "Row {$place->position()} of '{$place->name}' has the wrong kind of value in '$member'. It takes a $expected."
				);
			}
		}

		return $violations;
	}//end mistypedMembers()

	/**
	 * The declared type of a member, when it is one this class checks.
	 *
	 * @param mixed $declaration The member's declaration.
	 *
	 * @return string|null The type to check against, or null to leave it alone.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	private function checkableType(mixed $declaration): ?string {
		if (is_array($declaration) === false) {
			return null;
		}

		$expected = ($declaration['type'] ?? null);
		if (is_string($expected) === false || in_array($expected, self::CHECKED_TYPES, true) === false) {
			return null;
		}

		return $expected;
	}//end checkableType()

	/**
	 * Whether a row answers a member at all.
	 *
	 * @param array $row The submitted row.
	 * @param string $member The member name.
	 *
	 * @return boolean Whether there is a value to check.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	private function answers(array $row, string $member): bool {
		if (array_key_exists($member, $row) === false) {
			return false;
		}

		return ($row[$member] !== null && $row[$member] !== '');
	}//end answers()

	/**
	 * Whether a submitted value matches a declared JSON-Schema type.
	 *
	 * Numeric strings count as numbers and the strings that spell a boolean
	 * count as booleans, because form posts and query strings carry every
	 * value as text and refusing those would refuse the ordinary HTML form.
	 * That is the same leniency the type conversion notes in the property
	 * vocabulary describe, so the two agree.
	 *
	 * @param mixed $value The submitted value.
	 * @param string $expected The declared type.
	 *
	 * @return boolean Whether the value matches.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	private function matchesType(mixed $value, string $expected): bool {
		return match ($expected) {
			'string' => (is_string($value) === true || is_numeric($value) === true),
			'number' => is_numeric($value),
			'integer' => self::isIntegerLike(value: $value),
			'boolean' => self::isBooleanLike(value: $value),
			'array' => self::isListValue(value: $value),
			'object' => self::isMapValue(value: $value),
			default => true,
		};
	}//end matchesType()

	/**
	 * Whether a value is an integer or a numeric string that is one.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True for an integer
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	private static function isIntegerLike(mixed $value): bool {
		return is_int($value) === true || (is_numeric($value) === true && (string)(int)$value === (string)$value);
	}//end isIntegerLike()

	/**
	 * Whether a value is a boolean or one of its accepted spellings.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True for a boolean
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	private static function isBooleanLike(mixed $value): bool {
		return is_bool($value) === true || in_array($value, ['true', 'false', 0, 1, '0', '1'], true) === true;
	}//end isBooleanLike()

	/**
	 * Whether a value is an array that is empty or a list.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True for a list
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	private static function isListValue(mixed $value): bool {
		return is_array($value) === true && ($value === [] || array_is_list($value) === true);
	}//end isListValue()

	/**
	 * Whether a value is an array that is empty or not a list.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True for a map
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	private static function isMapValue(mixed $value): bool {
		return is_array($value) === true && ($value === [] || array_is_list($value) === false);
	}//end isMapValue()
}//end class
