<?php

/**
 * A choice property resolves to exactly one list of answers, or is refused.
 *
 * 🔴 THE DEFECTS THIS REFUSES ALL LOOK LIKE "the dropdown is empty" WEEKS
 * LATER. A property naming two schemes, or a scheme beside a literal list,
 * saves happily today and then behaves differently in the validator and in the
 * form. Nothing in the logs says why, because nothing considered it wrong.
 *
 * The refusal is on the SAVE, where somebody is there to read the sentence, and
 * deliberately not on the load: a schema already stored with two spellings must
 * still open, or a reportable authoring mistake becomes an outage.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schemas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Schemas;

use OCA\OpenRegister\Service\Schemas\CodedChoiceDeclaration;
use OCA\OpenRegister\Service\Schemas\CodedChoiceException;
use OCA\OpenRegister\Service\Schemas\PropertyVocabularyException;
use OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclaration;
use PHPUnit\Framework\TestCase;

/**
 * The save-time guard on a coded choice.
 *
 * @covers \OCA\OpenRegister\Service\Schemas\CodedChoiceDeclaration
 */
class CodedChoiceDeclarationTest extends TestCase {

	/**
	 * A property with one source passes, in either spelling.
	 *
	 * The control, and it runs first. Without it every refusal below is
	 * satisfied by a guard that refuses everything.
	 *
	 * @return void
	 */
	public function testOneSourceIsFine(): void {
		CodedChoiceDeclaration::assert(
			property: ['type' => 'string', CodedPropertyDeclaration::SIMPLE_ANNOTATION => 'wijken'],
			path: '/properties/wijk'
		);
		CodedChoiceDeclaration::assert(
			property: [
				'type' => 'string',
				CodedPropertyDeclaration::ANNOTATION => ['scheme' => 'https://example.org/wijken'],
			],
			path: '/properties/wijk'
		);
		CodedChoiceDeclaration::assert(
			property: ['type' => 'string', 'enum' => ['Centrum', 'Noord']],
			path: '/properties/wijk'
		);
		CodedChoiceDeclaration::assert(property: ['type' => 'string'], path: '/properties/titel');

		$this->addToAssertionCount(4);
	}//end testOneSourceIsFine()

	/**
	 * Two spellings of the binding are refused, naming both.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/property-code-list-from-concept-scheme/specs/skos-concept-registers/spec.md
	 */
	public function testTwoSpellingsAreRefused(): void {
		try {
			CodedChoiceDeclaration::assert(
				property: [
					'type' => 'string',
					CodedPropertyDeclaration::ANNOTATION => ['scheme' => 'https://example.org/wijken'],
					CodedPropertyDeclaration::SIMPLE_ANNOTATION => 'buurten',
				],
				path: '/properties/wijk'
			);
			$this->fail('a property naming two schemes must be refused');
		} catch (CodedChoiceException $refusal) {
			// The sentence has to name the property and BOTH spellings, or an
			// author reads "declared twice" and cannot tell which two.
			$this->assertStringContainsString('/properties/wijk', $refusal->getMessage());
			$this->assertStringContainsString(CodedPropertyDeclaration::ANNOTATION, $refusal->getMessage());
			$this->assertStringContainsString(CodedPropertyDeclaration::SIMPLE_ANNOTATION, $refusal->getMessage());
			$this->assertSame('coded-choice-two-spellings', $refusal->getErrors()[0]['code']);
		}
	}//end testTwoSpellingsAreRefused()

	/**
	 * A scheme beside a literal list is refused, in either spelling.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/property-code-list-from-concept-scheme/specs/skos-concept-registers/spec.md
	 */
	public function testASchemeBesideAnEnumIsRefused(): void {
		foreach (
			[
				[CodedPropertyDeclaration::SIMPLE_ANNOTATION => 'wijken'],
				[CodedPropertyDeclaration::ANNOTATION => ['scheme' => 'https://example.org/wijken']],
			] as $binding
		) {
			try {
				CodedChoiceDeclaration::assert(
					property: array_merge(['type' => 'string', 'enum' => ['Centrum']], $binding),
					path: '/properties/wijk'
				);
				$this->fail('a scheme beside an enum must be refused, whichever spelling declared it');
			} catch (CodedChoiceException $refusal) {
				$this->assertSame('coded-choice-and-enum', $refusal->getErrors()[0]['code']);
			}
		}
	}//end testASchemeBesideAnEnumIsRefused()

	/**
	 * An EMPTY enum beside a scheme is not this refusal.
	 *
	 * The boundary the "two sources" rule needs, and it is not pedantry: an
	 * empty array is what an editor writes for "no values typed yet", so
	 * refusing it as a competing source would refuse the ordinary act of
	 * binding a scheme to a field that once had none.
	 *
	 * @return void
	 */
	public function testAnEmptyEnumBesideASchemeIsNotACompetingSource(): void {
		CodedChoiceDeclaration::assert(
			property: [
				'type' => 'string',
				CodedPropertyDeclaration::SIMPLE_ANNOTATION => 'wijken',
				'enum' => [],
			],
			path: '/properties/wijk'
		);

		$this->addToAssertionCount(1);
	}//end testAnEmptyEnumBesideASchemeIsNotACompetingSource()

	/**
	 * The refusal answers as a vocabulary refusal, so every save path knows it.
	 *
	 * 🔑 THIS IS WHY NO CONTROLLER HAD TO LEARN ABOUT THE ANNOTATION. Every
	 * schema-save path already answers `PropertyVocabularyException` as a 422
	 * naming the property. A new exception type outside that family would have
	 * been a 500 on every one of them, which is the same refusal delivered as
	 * an outage.
	 *
	 * @return void
	 */
	public function testTheRefusalIsAVocabularyRefusal(): void {
		$this->expectException(PropertyVocabularyException::class);

		CodedChoiceDeclaration::assert(
			property: [
				'type' => 'string',
				CodedPropertyDeclaration::SIMPLE_ANNOTATION => 'wijken',
				'enum' => ['Centrum'],
			],
			path: '/properties/wijk'
		);
	}//end testTheRefusalIsAVocabularyRefusal()
}//end class
