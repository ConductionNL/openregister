<?php

/**
 * Where a choice property's answers come from, checked once at schema save.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/property-code-list-from-concept-scheme/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schemas;

use OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclaration;
use OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclarationFactory;

/**
 * Refuses a choice property whose answers cannot be resolved to one list.
 *
 * 🔴 EVERY REFUSAL HERE IS A FIELD THAT LOOKS CONFIGURED AND OFFERS NOTHING,
 * OR OFFERS TWO THINGS. None of them error at save time today, and none of them
 * error at read time either: the form draws an empty select, or the validator
 * checks against one list while the editor shows another. A handler meets it as
 * "the dropdown is empty" weeks later, and nothing in the logs says why.
 *
 * Two refusals, and each one is a different way of saying nothing:
 *
 *   1. BOTH SPELLINGS. `x-openregister-concepts` and `conceptScheme` are one
 *      binding read by one factory, and a property carrying both names two
 *      schemes. Whichever wins, the author meant the other half the time.
 *      {@see CodedPropertyDeclarationFactory::competingSpellings()} answers
 *      this, and until this class called it, nothing did: a reporter with no
 *      caller is the same as no check at all.
 *   2. A SCHEME BESIDE A LITERAL `enum`. Two sources for one field, and the
 *      dangerous version is the silent one: the validator checks the scheme,
 *      the form renders the enum, and nothing says which a handler will see.
 *
 * A third refusal was written here and then removed: an empty `enum` with no
 * scheme. `PropertyValidatorHandler` already refuses that, with a test on the
 * sentence, and task 4.1 of this change asked for a refusal that existed. A
 * second one shadowed the first with different words for one defect.
 *
 * WHAT IT DELIBERATELY DOES NOT REFUSE: a stored schema already carrying two
 * spellings still LOADS, because `CodedPropertyDeclarationFactory` resolves it
 * on the richer one. Refusing at load would make an existing schema unopenable,
 * which turns a reportable authoring mistake into an outage. The refusal is on
 * the SAVE, where somebody is there to read it.
 *
 * @spec openspec/changes/property-code-list-from-concept-scheme/specs/skos-concept-registers/spec.md
 */
final class CodedChoiceDeclaration {

	/**
	 * Refuse a property whose choices cannot be resolved to one list.
	 *
	 * Static, like {@see GeneratedIdentifierDeclaration::fromProperty()}, so
	 * `PropertyValidatorHandler::validateProperty()` can call it without taking
	 * a constructor argument. That handler is built in a dozen places and by
	 * the container; widening its constructor to reach one factory would be a
	 * blast radius out of all proportion to the check.
	 *
	 * @param array<string,mixed> $property The schema property definition.
	 * @param string              $path     The property path, for the message.
	 *
	 * @return void
	 *
	 * @throws CodedChoiceException When the choices resolve to nothing or to two things.
	 *
	 * @spec openspec/changes/property-code-list-from-concept-scheme/specs/skos-concept-registers/spec.md
	 */
	public static function assert(array $property, string $path = ''): void {
		$factory = new CodedPropertyDeclarationFactory();

		if ($factory->competingSpellings(property: $property) === true) {
			throw new CodedChoiceException(
				sprintf(
					'The property at \'%s\' declares its code list twice, as \'%s\' and as \'%s\'. '
					. 'They are one binding: keep the one naming the scheme you mean.',
					$path,
					CodedPropertyDeclaration::ANNOTATION,
					CodedPropertyDeclaration::SIMPLE_ANNOTATION
				),
				path: $path,
				code: 'coded-choice-two-spellings'
			);
		}

		$coded = ($factory->fromProperty(property: $property) !== null);
		$enum = ($property['enum'] ?? null);

		if ($coded === true && is_array($enum) === true && $enum !== []) {
			throw new CodedChoiceException(
				sprintf(
					'The property at \'%s\' takes its choices from a concept scheme AND lists them in '
					. '\'enum\'. Two sources is one too many: drop the list, or drop the scheme.',
					$path
				),
				path: $path,
				code: 'coded-choice-and-enum'
			);
		}

		// AN EMPTY `enum` IS ALREADY REFUSED, and this class deliberately does
		// not refuse it again. `PropertyValidatorHandler` throws "'enum' at
		// '<path>' must be a non-empty array" further down, with a test on it.
		// Task 4.1 of this change asked for that refusal and it was already
		// there; adding a second one here shadowed the first with a different
		// sentence for the same defect, which is how two error messages for one
		// mistake get written. Found by running the suite, not by reading.
	}//end assert()
}//end class
