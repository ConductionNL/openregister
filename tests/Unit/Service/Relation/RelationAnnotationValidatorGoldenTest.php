<?php

/**
 * Golden for RelationAnnotationValidator::validate
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Relation
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Relation;

use OCA\OpenRegister\Service\Relation\RelationAnnotationValidator;
use PHPUnit\Framework\TestCase;

/**
 * Every refusal, code and message, for schemas that touch each relation rule.
 * Set OR_GOLDEN_RECORD=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Relation\RelationAnnotationValidator
 * @uses \OCA\OpenRegister\Service\Relation\DeclaredProperty
 */
class RelationAnnotationValidatorGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/relation-annotation-validator-golden.json';

	/**
	 * Schemas covering the vocabulary, property, declaration and inheritance rules.
	 *
	 * @return array<string, array>
	 */
	private static function schemas(): array {
		$rel = RelationAnnotationValidator::PROPERTY_ANNOTATION;
		$voc = RelationAnnotationValidator::VOCABULARY_ANNOTATION;
		$ref = ['type' => 'object', '$ref' => '#/components/schemas/x'];

		return [
			'none'               => ['properties' => ['a' => ['type' => 'string']]],
			'properties-scalar'  => ['properties' => 'nope', $voc => [['key' => 'k%s', 'label' => 'K']]],
			'vocabulary-scalar'  => [$voc => 'nope'],
			'vocabulary-entries' => [
				$voc => [
					'x',
					7 => ['label' => 'no key'],
					['key' => '   '],
					['key' => ' parent ', 'label' => 'Parent', 'inverseLabel' => ['nl' => 'Kind'], 'inherits' => ['classification']],
					['key' => 'parent', 'label' => 'again'],
					(object) ['key' => 'obj', 'symmetric' => 'yes', 'label' => ['nl' => 3], 'inverseLabel' => ''],
					['key' => 'sym', 'symmetric' => true, 'inverseLabel' => 'Other', 'label' => []],
					['key' => 'inh', 'inherits' => (object) ['confidentiality' => 'conf', 'responsible' => ' ', 'colour' => 'c']],
					['key' => 'inh2', 'inherits' => ['classification', 5, 'secret', ['a']]],
					['key' => 'inh3', 'inherits' => 'all'],
					['key' => 'inh4', 'inherits' => ['classification' => 4]],
					['key' => 'lbl', 'label' => ['nl' => 'A', 0 => 'B']],
					['key' => 'symOnly', 'symmetric' => true, 'label' => (object) ['nl' => 'S']],
					['key' => 'objLabel', 'label' => (object) [], 'inverseLabel' => (object) ['nl' => 7]],
				],
			],
			'properties'         => [
				$voc         => [['key' => 'parent', 'label' => 'Parent']],
				'properties' => [
					'plain'      => ['type' => 'string'],
					'scalarDecl' => $ref + [$rel => 'parent'],
					'noRef'      => ['type' => 'string', $rel => ['label' => 'L']],
					'okType'     => $ref + [$rel => ['type' => ' parent ']],
					'badType'    => $ref + [$rel => ['type' => 'child%d']],
					'blankType'  => $ref + [$rel => ['type' => ' ']],
					'intType'    => $ref + [$rel => ['type' => 3]],
					'noLabel'    => $ref + [$rel => ['symmetric' => false]],
					'items'      => ['type' => 'array', 'items' => $ref + [$rel => ['label' => 'I', 'symmetric' => true, 'inverseLabel' => 'J']]],
					'both'       => $ref + [$rel => ['type' => 'nope', 'label' => 5, 'inherits' => ['x']]],
					7            => ['type' => 'string', $rel => ['label' => ['en' => 'E'], 'inherits' => ['responsible' => '']]],
					'objProp'    => (object) ['$ref' => 'r', $rel => (object) ['symmetric' => 1]],
					'objItems'   => ['type' => 'array', 'items' => (object) ['$ref' => 'r', $rel => (object) ['inherits' => (object) []]]],
				],
			],
		];
	}//end schemas()

	/**
	 * Property shapes for the two static readers: arrays, decoded objects, items, scalars.
	 *
	 * @return array<int, mixed>
	 */
	private static function properties(): array {
		$rel = RelationAnnotationValidator::PROPERTY_ANNOTATION;
		return [
			null,
			'x',
			[],
			['$ref' => 'r'],
			['inversedBy' => 'i'],
			[$rel => ['label' => 'L']],
			[$rel => (object) ['label' => 'O']],
			[$rel => 'scalar'],
			['items' => ['$ref' => 'r', $rel => ['label' => 'I']]],
			['items' => (object) ['inversedBy' => 'i', $rel => (object) ['type' => 't']]],
			['items' => 'x', $rel => null],
			['items' => ['type' => 'string']],
			[$rel => ['label' => 'root'], 'items' => [$rel => ['label' => 'items']]],
			(object) ['$ref' => 'r', $rel => ['label' => 'P']],
			(object) ['items' => (object) ['$ref' => 'r']],
		];
	}//end properties()

	public function testTheStaticReadersMatchTheGolden(): void {
		$actual = [];
		foreach (self::properties() as $property) {
			$actual[] = [
				RelationAnnotationValidator::declarationOf(property: $property),
				RelationAnnotationValidator::isReferenceProperty(property: $property),
			];
		}

		$fixture = str_replace('.json', '-readers.json', self::FIXTURE);
		if (getenv('OR_GOLDEN_RECORD') === '1') {
			file_put_contents($fixture, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame(expected: json_decode((string) file_get_contents($fixture), true), actual: json_decode((string) json_encode($actual), true));
	}//end testTheStaticReadersMatchTheGolden()

	public function testEveryRefusalMatchesTheGolden(): void {
		$validator = new RelationAnnotationValidator();
		$actual    = [];
		foreach (self::schemas() as $name => $schema) {
			$actual[$name] = $validator->validate(schema: $schema);
		}

		if (getenv('OR_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(expected: $expected, actual: $actual);
	}//end testEveryRefusalMatchesTheGolden()
}//end class
