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
 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/runtime-schema-api/spec.md
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
	 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/runtime-schema-api/spec.md
	 */
	public function validate(array $object, Schema $schema): array {
		$properties = $schema->getProperties();
		if (is_array($properties) === false || $properties === []) {
			return [];
		}

		$violations = [];
		foreach ($properties as $name => $definition) {
			if (is_array($definition) === false) {
				continue;
			}

			if (($definition[Schema::REPEATING_GROUP_PROPERTY_KEYWORD] ?? false) !== true) {
				continue;
			}

			$name = (string)$name;

			// Omitted is not a violation. An absent group is the required-value
			// rule's business, and answering it here would refuse every partial
			// update that happens not to mention the group.
			if (array_key_exists($name, $object) === false) {
				continue;
			}

			$violations = array_merge(
				$violations,
				$this->validateGroup(name: $name, value: $object[$name], definition: $definition)
			);
		}//end foreach

		return $violations;
	}//end validate()

	/**
	 * Validate one group's value: its shape, its bounds and its rows.
	 *
	 * @param string $name The group's property name.
	 * @param mixed $value The submitted value.
	 * @param array $definition The group's declaration.
	 *
	 * @return array<int, array{property: string, position: int|null, member: string|null, code: string, message: string}>
	 *
	 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/runtime-schema-api/spec.md
	 */
	private function validateGroup(string $name, mixed $value, array $definition): array {
		// An empty value clears the group. It is the minimum's business below,
		// never a shape complaint, so null and '' become the empty list here.
		if ($value === null || $value === '') {
			$value = [];
		}

		if (is_array($value) === false || ($value !== [] && array_is_list($value) === false)) {
			return [
				$this->violation(
					property: $name,
					position: null,
					member: null,
					code: 'repeating-group-not-a-list',
					message: "'$name' is a repeating group, so it takes a list of rows."
				),
			];
		}

		$violations = $this->validateBounds(name: $name, count: count($value), definition: $definition);

		$members = ($definition['items']['properties'] ?? []);
		if (is_array($members) === false) {
			$members = [];
		}

		$required = ($definition['items']['required'] ?? []);
		if (is_array($required) === false) {
			$required = [];
		}

		foreach ($value as $index => $row) {
			$violations = array_merge(
				$violations,
				$this->validateRow(
					name: $name,
					position: ((int)$index + 1),
					row: $row,
					members: $members,
					required: $required
				)
			);
		}

		return $violations;
	}//end validateGroup()

	/**
	 * Enforce `minItems` and `maxItems` on a group.
	 *
	 * The bounds are the ordinary array keywords rather than keywords of their
	 * own, so a schema that already declares them keeps meaning exactly what it
	 * meant before this change.
	 *
	 * @param string $name The group's property name.
	 * @param integer $count How many rows were submitted.
	 * @param array $definition The group's declaration.
	 *
	 * @return array<int, array{property: string, position: int|null, member: string|null, code: string, message: string}>
	 *
	 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/runtime-schema-api/spec.md
	 */
	private function validateBounds(string $name, int $count, array $definition): array {
		$violations = [];

		$minimum = ($definition['minItems'] ?? null);
		if (is_numeric($minimum) === true && $count < (int)$minimum) {
			$violations[] = $this->violation(
				property: $name,
				position: null,
				member: null,
				code: 'repeating-group-below-minimum',
				message: "'$name' needs at least " . (int)$minimum . " rows. It was sent $count."
			);
		}

		$maximum = ($definition['maxItems'] ?? null);
		if (is_numeric($maximum) === true && $count > (int)$maximum) {
			$violations[] = $this->violation(
				property: $name,
				position: null,
				member: null,
				code: 'repeating-group-above-maximum',
				message: "'$name' takes at most " . (int)$maximum . " rows. It was sent $count."
			);
		}

		return $violations;
	}//end validateBounds()

	/**
	 * Validate one row against the group's declared members.
	 *
	 * @param string $name The group's property name.
	 * @param integer $position The row, counted from one.
	 * @param mixed $row The submitted row.
	 * @param array $members The declared members.
	 * @param array $required The members a row has to answer.
	 *
	 * @return array<int, array{property: string, position: int|null, member: string|null, code: string, message: string}>
	 *
	 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/runtime-schema-api/spec.md
	 */
	private function validateRow(string $name, int $position, mixed $row, array $members, array $required): array {
		if (is_array($row) === false || array_is_list($row) === true) {
			return [
				$this->violation(
					property: $name,
					position: $position,
					member: null,
					code: 'repeating-group-row-not-an-object',
					message: "Row $position of '$name' has to be a set of fields."
				),
			];
		}

		return array_merge(
			$this->missingMembers(name: $name, position: $position, row: $row, required: $required),
			$this->mistypedMembers(name: $name, position: $position, row: $row, members: $members)
		);
	}//end validateRow()

	/**
	 * The members a row had to answer and did not.
	 *
	 * @param string $name The group's property name.
	 * @param integer $position The row, counted from one.
	 * @param array $row The submitted row.
	 * @param array $required The members a row has to answer.
	 *
	 * @return array<int, array{property: string, position: int|null, member: string|null, code: string, message: string}>
	 *
	 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/runtime-schema-api/spec.md
	 */
	private function missingMembers(string $name, int $position, array $row, array $required): array {
		$violations = [];
		foreach ($required as $member) {
			$member = (string)$member;
			$answered = (array_key_exists($member, $row) === true && $row[$member] !== null && $row[$member] !== '');
			if ($answered === true) {
				continue;
			}

			$violations[] = $this->violation(
				property: $name,
				position: $position,
				member: $member,
				code: 'repeating-group-member-required',
				message: "Row $position of '$name' is missing '$member'."
			);
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
	 * @param string $name The group's property name.
	 * @param integer $position The row, counted from one.
	 * @param array $row The submitted row.
	 * @param array $members The declared members.
	 *
	 * @return array<int, array{property: string, position: int|null, member: string|null, code: string, message: string}>
	 *
	 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/runtime-schema-api/spec.md
	 */
	private function mistypedMembers(string $name, int $position, array $row, array $members): array {
		$violations = [];
		foreach ($members as $member => $declaration) {
			$member = (string)$member;
			$expected = $this->checkableType(declaration: $declaration);
			if ($expected === null || $this->answers(row: $row, member: $member) === false) {
				continue;
			}

			if ($this->matchesType(value: $row[$member], expected: $expected) === true) {
				continue;
			}

			$violations[] = $this->violation(
				property: $name,
				position: $position,
				member: $member,
				code: 'repeating-group-member-type',
				message: "Row $position of '$name' has the wrong kind of value in '$member'. It takes a $expected."
			);
		}//end foreach

		return $violations;
	}//end mistypedMembers()

	/**
	 * The declared type of a member, when it is one this class checks.
	 *
	 * @param mixed $declaration The member's declaration.
	 *
	 * @return string|null The type to check against, or null to leave it alone.
	 *
	 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/runtime-schema-api/spec.md
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
	 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/runtime-schema-api/spec.md
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
	 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/runtime-schema-api/spec.md
	 */
	private function matchesType(mixed $value, string $expected): bool {
		return match ($expected) {
			'string' => (is_string($value) === true || is_numeric($value) === true),
			'number' => is_numeric($value),
			'integer' => (is_int($value) === true || (is_numeric($value) === true && (string)(int)$value === (string)$value)),
			'boolean' => (is_bool($value) === true || in_array($value, ['true', 'false', 0, 1, '0', '1'], true) === true),
			'array' => (is_array($value) === true && ($value === [] || array_is_list($value) === true)),
			'object' => (is_array($value) === true && ($value === [] || array_is_list($value) === false)),
			default => true,
		};
	}//end matchesType()

	/**
	 * Build one violation in the shape every caller reads.
	 *
	 * @param string $property The group's property name.
	 * @param integer|null $position The row, counted from one, or null for the group.
	 * @param string|null $member The member inside the row, or null.
	 * @param string $code The machine-readable refusal code.
	 * @param string $message The sentence a person reads.
	 *
	 * @return array{property: string, position: int|null, member: string|null, code: string, message: string}
	 *
	 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/runtime-schema-api/spec.md
	 */
	private function violation(string $property, ?int $position, ?string $member, string $code, string $message): array {
		return [
			'property' => $property,
			'position' => $position,
			'member' => $member,
			'code' => $code,
			'message' => $message,
		];
	}//end violation()
}//end class
