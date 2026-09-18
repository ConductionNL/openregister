<?php

/**
 * One reader for a coded property, whichever spelling declared it.
 *
 * 🔴 TWO SPELLINGS ARRIVED FROM TWO DIRECTIONS AND NEITHER KNEW ABOUT THE
 * OTHER. `x-openregister-concepts` came with `code-list-lifecycle-and-hierarchy`
 * as the full binding: a scheme plus a branch, a depth and a context property.
 * `conceptScheme` came with openregister#3883 as the published vocabulary
 * modifier, because that is what an extending form can forward and what a
 * case-type editor writes. Both mean "this field's values are the concepts of
 * scheme X".
 *
 * Left alone, the validator would have read one and the editor the other, and
 * the disagreement would surface as a field that saves any value on an instance
 * whose schema clearly declares a code list. So the factory reads both into ONE
 * declaration, and a property carrying both is reported rather than resolved by
 * precedence: precedence is a rule somebody has to know, and the author who
 * wrote both did not know it.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Vocabulary
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

namespace OCA\OpenRegister\Tests\Unit\Service\Vocabulary;

use OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclaration;
use OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclarationFactory;
use PHPUnit\Framework\TestCase;

/**
 * The factory reads both spellings, and refuses both at once.
 *
 * @covers \OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclarationFactory
 */
class CodedPropertyDeclarationFactoryTest extends TestCase {

	/**
	 * The factory under test.
	 *
	 * @var CodedPropertyDeclarationFactory
	 */
	private CodedPropertyDeclarationFactory $factory;

	/**
	 * Build the factory.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->factory = new CodedPropertyDeclarationFactory();
	}//end setUp()

	/**
	 * The full annotation still reads exactly as it did.
	 *
	 * @return void
	 */
	public function testTheFullAnnotationIsUnchanged(): void {
		$declaration = $this->factory->fromProperty(
			property: [
				'type' => 'string',
				CodedPropertyDeclaration::ANNOTATION => [
					'scheme' => 'https://example.org/wijken',
					'store' => 'notation',
					'allowDeprecated' => true,
				],
			]
		);

		$this->assertInstanceOf(CodedPropertyDeclaration::class, $declaration);
		$this->assertSame('https://example.org/wijken', $declaration->scheme);
		$this->assertSame('notation', $declaration->store);
		$this->assertTrue($declaration->allowDeprecated);
	}//end testTheFullAnnotationIsUnchanged()

	/**
	 * The simple spelling declares the same binding.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/property-code-list-from-concept-scheme/specs/skos-concept-registers/spec.md
	 */
	public function testTheSimpleSpellingDeclaresACodedProperty(): void {
		$declaration = $this->factory->fromProperty(
			property: ['type' => 'string', CodedPropertyDeclaration::SIMPLE_ANNOTATION => 'wijken']
		);

		$this->assertInstanceOf(
			CodedPropertyDeclaration::class,
			$declaration,
			'a field declaring conceptScheme is a coded field, or the guard never checks its values'
		);
		$this->assertSame('wijken', $declaration->scheme);
		// The defaults the full annotation would have given it. A simple
		// spelling is the same binding with nothing else said, not a weaker one.
		$this->assertSame('uri', $declaration->store);
		$this->assertFalse($declaration->allowDeprecated);
	}//end testTheSimpleSpellingDeclaresACodedProperty()

	/**
	 * An object-shaped property is read the same way.
	 *
	 * Schemas arrive as stdClass from the JSON decode on one path and as arrays
	 * on another, and the reader this replaced handled both. A simple spelling
	 * that only worked on arrays would be a binding that is enforced on one
	 * code path and not the other.
	 *
	 * @return void
	 */
	public function testTheSimpleSpellingIsReadOffAnObjectToo(): void {
		$property = (object)['type' => 'string', 'conceptScheme' => 'wijken'];

		$declaration = $this->factory->fromProperty(property: $property);

		$this->assertInstanceOf(CodedPropertyDeclaration::class, $declaration);
		$this->assertSame('wijken', $declaration->scheme);
	}//end testTheSimpleSpellingIsReadOffAnObjectToo()

	/**
	 * A property that declares neither is not coded.
	 *
	 * The control. Without it every assertion above passes on a factory that
	 * returns a declaration for anything.
	 *
	 * @return void
	 */
	public function testAPlainPropertyIsNotCoded(): void {
		$this->assertNull($this->factory->fromProperty(property: ['type' => 'string']));
		$this->assertNull($this->factory->fromProperty(property: ['type' => 'string', 'conceptScheme' => '']));
		$this->assertNull($this->factory->fromProperty(property: ['type' => 'string', 'conceptScheme' => '   ']));
	}//end testAPlainPropertyIsNotCoded()

	/**
	 * Both spellings on one property is reported, not resolved.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/property-code-list-from-concept-scheme/specs/skos-concept-registers/spec.md
	 */
	public function testBothSpellingsAtOnceAreReported(): void {
		$property = [
			'type' => 'string',
			CodedPropertyDeclaration::ANNOTATION => ['scheme' => 'https://example.org/wijken'],
			CodedPropertyDeclaration::SIMPLE_ANNOTATION => 'buurten',
		];

		$this->assertTrue(
			$this->factory->competingSpellings(property: $property),
			'two schemes on one field must be reported; whichever one wins, the author meant the other half the time'
		);

		// And each one alone is not a competition.
		$this->assertFalse($this->factory->competingSpellings(
			property: ['type' => 'string', CodedPropertyDeclaration::SIMPLE_ANNOTATION => 'wijken']
		));
		$this->assertFalse($this->factory->competingSpellings(
			property: ['type' => 'string', CodedPropertyDeclaration::ANNOTATION => ['scheme' => 'wijken']]
		));
		$this->assertFalse($this->factory->competingSpellings(property: ['type' => 'string']));
	}//end testBothSpellingsAtOnceAreReported()

	/**
	 * The full annotation wins when both are present, so nothing crashes.
	 *
	 * Reporting is not refusing. A schema already stored with both still has to
	 * load, and it loads on the richer of the two, because that is the one
	 * carrying the branch and depth the option builder needs.
	 *
	 * @return void
	 */
	public function testTheRicherSpellingIsTheOneThatLoads(): void {
		$declaration = $this->factory->fromProperty(
			property: [
				'type' => 'string',
				CodedPropertyDeclaration::ANNOTATION => ['scheme' => 'https://example.org/wijken'],
				CodedPropertyDeclaration::SIMPLE_ANNOTATION => 'buurten',
			]
		);

		$this->assertSame('https://example.org/wijken', $declaration?->scheme);
	}//end testTheRicherSpellingIsTheOneThatLoads()

	/**
	 * Every coded property on a schema is found, in either spelling.
	 *
	 * @return void
	 */
	public function testBothSpellingsAreFoundAcrossASchema(): void {
		$declarations = $this->factory->fromProperties(
			properties: [
				'wijk' => ['type' => 'string', CodedPropertyDeclaration::SIMPLE_ANNOTATION => 'wijken'],
				'soort' => [
					'type' => 'string',
					CodedPropertyDeclaration::ANNOTATION => ['scheme' => 'soorten'],
				],
				'titel' => ['type' => 'string'],
			]
		);

		$this->assertSame(['wijk', 'soort'], array_keys($declarations));
		$this->assertSame('wijken', $declarations['wijk']->scheme);
		$this->assertSame('soorten', $declarations['soort']->scheme);
	}//end testBothSpellingsAreFoundAcrossASchema()
}//end class
