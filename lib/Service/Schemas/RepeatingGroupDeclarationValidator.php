<?php

/**
 * OpenRegister repeating-group declarations.
 *
 * Checks that a property declared `repeatingGroup: true` describes a shape
 * somebody can author rows against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/runtime-schema-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schemas;

/**
 * The save-time rules a repeating group's declaration has to satisfy.
 *
 * Its own class rather than four more methods on `PropertyValidatorHandler`,
 * which is the file every property-shaped change edits and which these methods
 * pushed past the thousand-line mark. The rules are about one keyword and read
 * better together.
 *
 * Checked when the schema is saved rather than when an object is written
 * against it: a group whose members nobody declared is a schema mistake, and
 * the schema's author is the only person who can fix it.
 */
class RepeatingGroupDeclarationValidator {

	/**
	 * Check that a repeating group declares a shape somebody can author.
	 *
	 * Three things have to hold. The group has to be a list, because rows are a
	 * list and nothing else. Its `items` have to name the members, because a
	 * row with no declared members is an untyped blob and the per-row refusal
	 * this change promises has nothing to name. And `groupLabel`, when it is
	 * there, has to point at a member that exists.
	 *
	 * `groupOrdered` needs no check beyond the group itself: it is a boolean
	 * and either value is meaningful.
	 *
	 * @param array $property The property definition to check.
	 * @param string $path The current path in the schema, for the message.
	 *
	 * @throws PropertyVocabularyException When the declaration does not hold together.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/runtime-schema-api/spec.md
	 */
	public function validate(array $property, string $path): void {
		$isGroup = (($property['repeatingGroup'] ?? false) === true);

		// Present AND non-null, which is what every other check in the
		// validator asks. A null says the key is spelled correctly and asserts
		// nothing about its value, and `PropertyVocabularyTest` probes the whole
		// published vocabulary that way: reading a null as a declaration made
		// two published keys impossible to save on their own.
		$hasGroupKey = (
			($property['groupOrdered'] ?? null) !== null
			|| ($property['groupLabel'] ?? null) !== null
		);

		if ($isGroup === false) {
			if ($hasGroupKey === false) {
				return;
			}

			$this->refuse(
				code: 'repeating-group-not-declared',
				path: $path,
				message: "'groupOrdered' and 'groupLabel' at '$path' need 'repeatingGroup: true'."
			);
		}

		if (($property['type'] ?? null) !== 'array') {
			$this->refuse(
				code: 'repeating-group-not-an-array',
				path: $path,
				message: "A repeating group at '$path' has to be type 'array'. Rows are a list."
			);
		}

		$members = ($property['items']['properties'] ?? null);
		if (is_array($members) === false || $members === []) {
			$this->refuse(
				code: 'repeating-group-without-members',
				path: $path,
				message: "A repeating group at '$path' has to declare its members under 'items.properties'."
			);
		}

		$this->validateLabel(label: ($property['groupLabel'] ?? null), members: $members, path: $path);
	}//end validate()

	/**
	 * Check that `groupLabel`, when declared, names a member that exists.
	 *
	 * A label pointing at a member nobody declared renders blank on every row
	 * and reads as a data problem for as long as anybody is willing to look.
	 *
	 * @param mixed $label The declared label member, or null.
	 * @param array $members The group's declared members.
	 * @param string $path The current path in the schema, for the message.
	 *
	 * @throws PropertyVocabularyException When the label names nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/runtime-schema-api/spec.md
	 */
	private function validateLabel(mixed $label, array $members, string $path): void {
		if ($label === null) {
			return;
		}

		if (is_string($label) === true && array_key_exists($label, $members) === true) {
			return;
		}

		$known = implode(', ', array_map('strval', array_keys($members)));
		$this->refuse(
			code: 'repeating-group-unknown-label-member',
			path: $path,
			message: "'groupLabel' at '$path' names a member that is not declared. Members are: $known."
		);
	}//end validateLabel()

	/**
	 * Refuse a declaration, in the vocabulary's own error shape.
	 *
	 * Extracted so the four refusals above read as four rules rather than as
	 * four copies of the same six lines.
	 *
	 * @param string $code The machine-readable refusal code.
	 * @param string $path The property path the refusal is about.
	 * @param string $message The sentence a schema author reads.
	 *
	 * @throws PropertyVocabularyException Always.
	 *
	 * @return never
	 *
	 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/runtime-schema-api/spec.md
	 */
	private function refuse(string $code, string $path, string $message): never {
		throw new PropertyVocabularyException(
			$message,
			[
				[
					'code' => $code,
					'key' => 'repeatingGroup',
					'path' => $path,
					'message' => $message,
				],
			]
		);
	}//end refuse()
}//end class
