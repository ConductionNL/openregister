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
 * @spec openspec/specs/runtime-schema-api/spec.md
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
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function validate(array $property, string $path): void {
		$run = new RepeatingGroupDeclarationRun(property: $property, path: $path);
		if (($property['repeatingGroup'] ?? false) !== true) {
			$this->assertNoGroupKeys(run: $run);
			return;
		}

		$this->validateLabel(run: $run, members: $this->declaredMembers(run: $run));
	}//end validate()

	/**
	 * Refuse group keys on a property that is not a repeating group.
	 *
	 * @param RepeatingGroupDeclarationRun $run The property under check and its path.
	 *
	 * @throws PropertyVocabularyException When a group key is declared without the group.
	 *
	 * @return void
	 */
	private function assertNoGroupKeys(RepeatingGroupDeclarationRun $run): void {
		// Present AND non-null, which is what every other check in the
		// validator asks. A null says the key is spelled correctly and asserts
		// nothing about its value, and `PropertyVocabularyTest` probes the whole
		// published vocabulary that way: reading a null as a declaration made
		// two published keys impossible to save on their own.
		$hasGroupKey = (
			($run->property['groupOrdered'] ?? null) !== null
			|| ($run->property['groupLabel'] ?? null) !== null
		);

		if ($hasGroupKey === false) {
			return;
		}

		$run->refuse(
			code: 'repeating-group-not-declared',
			message: "'groupOrdered' and 'groupLabel' at '{$run->path}' need 'repeatingGroup: true'."
		);
	}//end assertNoGroupKeys()

	/**
	 * The members a repeating group declares, refusing a group that is no list or names none.
	 *
	 * @param RepeatingGroupDeclarationRun $run The property under check and its path.
	 *
	 * @throws PropertyVocabularyException When the group is not an array or declares no members.
	 *
	 * @return array The declared members.
	 */
	private function declaredMembers(RepeatingGroupDeclarationRun $run): array {
		if (($run->property['type'] ?? null) !== 'array') {
			$run->refuse(code: 'repeating-group-not-an-array', message: "A repeating group at '{$run->path}' has to be type 'array'. Rows are a list.");
		}

		$members = ($run->property['items']['properties'] ?? null);
		if (is_array($members) === false || $members === []) {
			$message = "A repeating group at '{$run->path}' has to declare its members under 'items.properties'.";
			$run->refuse(code: 'repeating-group-without-members', message: $message);
		}

		return $members;
	}//end declaredMembers()

	/**
	 * Check that `groupLabel`, when declared, names a member that exists.
	 *
	 * A label pointing at a member nobody declared renders blank on every row
	 * and reads as a data problem for as long as anybody is willing to look.
	 *
	 * @param RepeatingGroupDeclarationRun $run     The property under check and its path.
	 * @param array                        $members The group's declared members.
	 *
	 * @throws PropertyVocabularyException When the label names nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	private function validateLabel(RepeatingGroupDeclarationRun $run, array $members): void {
		$label = ($run->property['groupLabel'] ?? null);
		if ($label === null) {
			return;
		}

		if (is_string($label) === true && array_key_exists($label, $members) === true) {
			return;
		}

		$known = implode(', ', array_map('strval', array_keys($members)));
		$run->refuse(
			code: 'repeating-group-unknown-label-member',
			message: "'groupLabel' at '{$run->path}' names a member that is not declared. Members are: $known."
		);
	}//end validateLabel()
}//end class
