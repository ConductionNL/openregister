<?php

/**
 * The required-when grammar: what it reads, what it refuses, and how each
 * operator compares, the way nextcloud-vue's form does.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schemas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service\Schemas;

use OCA\OpenRegister\Service\Schemas\PropertyVocabularyException;
use OCA\OpenRegister\Service\Schemas\RequiredWhenDeclaration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Schemas\RequiredWhenDeclaration
 * @uses   \OCA\OpenRegister\Service\Schemas\PropertyVocabularyException
 *
 * @spec openspec/changes/modelling-required-when-enforced/specs/object-lifecycle/spec.md
 */
class RequiredWhenDeclarationTest extends TestCase {

	/**
	 * A declaration over the given annotation value.
	 *
	 * @param mixed $annotation The annotation's value.
	 *
	 * @return RequiredWhenDeclaration|null The declaration.
	 */
	private function declaration(mixed $annotation): ?RequiredWhenDeclaration {
		return RequiredWhenDeclaration::fromProperty(
			property: ['type' => 'string', RequiredWhenDeclaration::ANNOTATION => $annotation],
			path: '/complaintCategory'
		);
	}//end declaration()

	/**
	 * A property without the annotation, or with a null one, declares nothing.
	 *
	 * @return void
	 */
	public function testNoAnnotationIsNoDeclaration(): void {
		$this->assertNull(RequiredWhenDeclaration::fromProperty(property: ['type' => 'string']));
		$this->assertNull($this->declaration(annotation: null));
	}//end testNoAnnotationIsNoDeclaration()

	/**
	 * Every operator against values that make it hold and not hold.
	 *
	 * @return array<string, array{0: string, 1: mixed, 2: mixed, 3: bool}>
	 */
	public static function comparisons(): array {
		return [
			'eq holds'                    => ['eq', 'Klacht', 'Klacht', true],
			'eq fails'                    => ['eq', 'Klacht', 'Vraag', false],
			'eq number against its string' => ['eq', 3, '3', true],
			'eq string against a number'  => ['eq', '3', 3, true],
			'eq true against "true"'      => ['eq', true, 'true', true],
			'eq missing against a value'  => ['eq', 'Klacht', null, false],
			'neq holds'                   => ['neq', 'Klacht', 'Vraag', true],
			'neq fails loosely'           => ['neq', 3, '3', false],
			'gt holds'                    => ['gt', 10, 11, true],
			'gt on a numeric string'      => ['gt', 10, '11', true],
			'gt fails on equal'           => ['gt', 10, 10, false],
			'gte holds on equal'          => ['gte', 10, 10, true],
			'lt holds'                    => ['lt', 10, 9.5, true],
			'lt fails'                    => ['lt', 10, 11, false],
			'lte holds on equal'          => ['lte', '10', 10, true],
			'gt compares dates as moments' => ['gt', '2026-01-01', '2026-03-01', true],
			'lt on dates fails when later' => ['lt', '2026-01-01', '2026-03-01', false],
			'gt on text never holds'      => ['gt', 10, 'veel', false],
		];
	}//end comparisons()

	/**
	 * Each operator compares the object's value with the condition's.
	 *
	 * @param string $operator The operator.
	 * @param mixed  $expected The condition's value.
	 * @param mixed  $actual   The object's value of the field.
	 * @param bool   $holds    Whether the condition holds.
	 *
	 * @return void
	 */
	#[DataProvider('comparisons')]
	public function testEachOperatorComparesTheWayTheFormDoes(string $operator, mixed $expected, mixed $actual, bool $holds): void {
		$declaration = $this->declaration(annotation: ['field' => 'requestType', 'op' => $operator, 'value' => $expected]);

		$this->assertSame($holds, $declaration->holds(data: ['requestType' => $actual]));
	}//end testEachOperatorComparesTheWayTheFormDoes()

	/**
	 * `empty` and `notEmpty` take no value; null, '' and an empty list are empty, 0 and false are not.
	 *
	 * @return void
	 */
	public function testEmptyAndNotEmptyTakeNoValue(): void {
		$empty    = $this->declaration(annotation: ['field' => 'kvkNummer', 'op' => 'empty']);
		$notEmpty = $this->declaration(annotation: ['field' => 'kvkNummer', 'op' => 'notEmpty']);

		foreach ([null, '', []] as $blank) {
			$this->assertTrue($empty->holds(data: ['kvkNummer' => $blank]));
			$this->assertFalse($notEmpty->holds(data: ['kvkNummer' => $blank]));
		}

		$this->assertTrue($empty->holds(data: []));
		foreach ([0, false, '12345678'] as $given) {
			$this->assertFalse($empty->holds(data: ['kvkNummer' => $given]));
			$this->assertTrue($notEmpty->holds(data: ['kvkNummer' => $given]));
		}
	}//end testEmptyAndNotEmptyTakeNoValue()

	/**
	 * A list of conditions holds only when every one of them holds.
	 *
	 * @return void
	 */
	public function testAListOfConditionsMustAllHold(): void {
		$declaration = $this->declaration(
			annotation: [
				['field' => 'requestType', 'op' => 'eq', 'value' => 'Klacht'],
				['field' => 'amount', 'op' => 'gte', 'value' => 100],
			]
		);

		$this->assertTrue($declaration->holds(data: ['requestType' => 'Klacht', 'amount' => 250]));
		$this->assertFalse($declaration->holds(data: ['requestType' => 'Klacht', 'amount' => 50]));
		$this->assertFalse($declaration->holds(data: ['requestType' => 'Vraag', 'amount' => 250]));
		$this->assertSame('Required when requestType eq Klacht and amount gte 100', $declaration->describe());
	}//end testAListOfConditionsMustAllHold()

	/**
	 * A dotted field reads a nested value; a path that is not there reads as missing.
	 *
	 * @return void
	 */
	public function testADottedFieldReadsANestedValue(): void {
		$declaration = $this->declaration(annotation: ['field' => 'adres.land', 'op' => 'eq', 'value' => 'NL']);

		$this->assertTrue($declaration->holds(data: ['adres' => ['land' => 'NL']]));
		$this->assertFalse($declaration->holds(data: ['adres' => 'Utrecht']));
		$this->assertFalse($declaration->holds(data: []));
	}//end testADottedFieldReadsANestedValue()

	/**
	 * An omitted operator is `eq`, and the description names the condition.
	 *
	 * @return void
	 */
	public function testAnOmittedOperatorIsEq(): void {
		$declaration = $this->declaration(annotation: ['field' => 'requestType', 'value' => 'Klacht']);

		$this->assertSame([['field' => 'requestType', 'op' => 'eq', 'value' => 'Klacht']], $declaration->conditions);
		$this->assertSame('Required when requestType eq Klacht', $declaration->describe());
		$this->assertSame(
			'Required when kvkNummer empty',
			$this->declaration(annotation: ['field' => 'kvkNummer', 'op' => 'empty'])->describe()
		);
	}//end testAnOmittedOperatorIsEq()

	/**
	 * Malformed declarations, each with the words its refusal must carry.
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function malformed(): array {
		return [
			'a scalar'           => ['Klacht', 'must be a condition'],
			'an empty list'      => [[], 'must be a condition'],
			'a list of scalars'  => [['requestType'], 'must be a condition'],
			'no field'           => [['op' => 'eq', 'value' => 'x'], 'names no field'],
			'a blank field'      => [['field' => ' ', 'op' => 'eq', 'value' => 'x'], 'names no field'],
			'an unknown op'      => [['field' => 'requestType', 'op' => 'in', 'value' => ['x']], "operator 'in'"],
			'a comparison without value' => [['field' => 'requestType', 'op' => 'gt'], "compares with 'gt'"],
		];
	}//end malformed()

	/**
	 * A declaration that cannot be evaluated is refused at schema save, naming the property.
	 *
	 * @param mixed  $annotation The annotation's value.
	 * @param string $words      What the refusal says.
	 *
	 * @return void
	 */
	#[DataProvider('malformed')]
	public function testAMalformedDeclarationIsRefusedNamingTheProperty(mixed $annotation, string $words): void {
		try {
			$this->declaration(annotation: $annotation);
			$this->fail('Expected a PropertyVocabularyException.');
		} catch (PropertyVocabularyException $refusal) {
			$this->assertStringContainsString('/complaintCategory', $refusal->getMessage());
			$this->assertStringContainsString($words, $refusal->getMessage());
		}
	}//end testAMalformedDeclarationIsRefusedNamingTheProperty()

	/**
	 * A condition on an undeclared field or on the property itself is refused; a declared one passes.
	 *
	 * @return void
	 */
	public function testTheConditionMustNameAnotherDeclaredField(): void {
		$declared = ['requestType', 'complaintCategory', 'adres'];

		$this->declaration(annotation: ['field' => 'adres.land', 'op' => 'eq', 'value' => 'NL'])
			->assertFieldsDeclared(declared: $declared, property: 'complaintCategory', path: '/complaintCategory');

		foreach (['soort' => "depends on 'soort'", 'complaintCategory' => 'the property itself'] as $field => $words) {
			try {
				$this->declaration(annotation: ['field' => $field, 'op' => 'notEmpty'])
					->assertFieldsDeclared(declared: $declared, property: 'complaintCategory', path: '/complaintCategory');
				$this->fail('Expected a refusal for ' . $field);
			} catch (PropertyVocabularyException $refusal) {
				$this->assertStringContainsString($words, $refusal->getMessage());
				$this->assertStringContainsString('/complaintCategory', $refusal->getMessage());
			}
		}
	}//end testTheConditionMustNameAnotherDeclaredField()
}//end class
