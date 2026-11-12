<?php

/**
 * Golden test for the form field compatibility rules.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Form
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-openregister-must-judge-a-forms-mapping-against-its-destination-schema
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Form;

use OCA\OpenRegister\Service\Form\FormFieldRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Each case calls every rule on one field/property pair, and fixedValueFits()
 * with a set of values; the answers are compared with
 * fixtures/form-field-rules-golden.json. Set UPDATE_GOLDEN=1 to rewrite it.
 */
class FormFieldRulesGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/form-field-rules-golden.json';

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	public static function cases(): array {
		$cases = [];
		foreach (['text', 'EMAIL', 'url', 'datetime', 'reference', 'number', 'integer', 'checkbox', 'multichoice', 'object', 'file', 'widget', ''] as $type) {
			foreach (['string', 'number', 'integer', 'boolean', 'array', 'object', ['string', 'null'], ['integer', 5], '', null] as $propertyType) {
				$cases['type ' . $type . ' into ' . json_encode($propertyType)] = [['type' => $type], ['type' => $propertyType]];
			}
		}

		$cases['format explicit'] = [['type' => 'text', 'format' => ' uri '], ['type' => 'string', 'format' => 'url']];
		$cases['format implied'] = [['type' => 'datetime'], ['type' => 'string', 'format' => 'DATE-TIME']];
		$cases['format missing'] = [['type' => 'text'], ['type' => 'string', 'format' => 'email']];
		$cases['format differs'] = [['type' => 'date'], ['type' => 'string', 'format' => 'date-time']];
		$cases['format blank demanded'] = [['type' => 'text', 'format' => 'x'], ['type' => 'string', 'format' => '  ']];
		$cases['enum on property'] = [['type' => 'choice', 'options' => ['a', ['value' => 'b', 'label' => 'B'], ['label' => 'no value'], 3]], ['type' => 'string', 'enum' => ['x' => 'a', 'y' => 'b']]];
		$cases['enum on items'] = [['type' => 'multichoice', 'options' => 'a,b'], ['type' => 'array', 'items' => ['enum' => ['a', 'b']]]];
		$cases['enum items scalar'] = [['type' => 'multichoice', 'options' => []], ['type' => 'array', 'items' => 'x', 'enum' => 'nope']];
		$cases['bounds all tighter'] = [['maxLength' => 10, 'maximum' => 5, 'maxItems' => 2, 'minLength' => 3, 'minimum' => 1, 'minItems' => 1, 'pattern' => '^a'], ['maxLength' => 100, 'maximum' => '5', 'maxItems' => 2, 'minLength' => 1, 'minimum' => 0.5, 'minItems' => 1, 'pattern' => '^b']];
		$cases['bounds all looser'] = [['maxLength' => 500, 'maximum' => 5.5, 'maxItems' => 3, 'minLength' => 0, 'minimum' => -1, 'minItems' => 0, 'pattern' => ' '], ['maxLength' => 100, 'maximum' => 5, 'maxItems' => 2, 'minLength' => 1, 'minimum' => 0, 'minItems' => 1, 'pattern' => '^b']];
		$cases['bounds field open'] = [[], ['maxLength' => 100, 'minimum' => -3, 'pattern' => 'x']];
		$cases['bounds property open'] = [['maxLength' => 1, 'minimum' => 9], ['maxLength' => 'abc', 'pattern' => '']];
		$cases['bounds field not numeric'] = [['maxItems' => 'many', 'minItems' => null], ['maxItems' => 3, 'minItems' => '2']];
		$cases['bounds negative'] = [['minimum' => -5, 'maximum' => -1], ['minimum' => -4, 'maximum' => -2]];
		return $cases;
	}//end cases()

	/**
	 * @param array<string, mixed> $field
	 * @param array<string, mixed> $property
	 */
	#[DataProvider('cases')]
	public function testMatchesGolden(array $field, array $property): void {
		$rules = new FormFieldRules();
		$fixed = [];
		foreach ([null, true, 0, 1, 1.5, 'a', 'b', '', [], [1, 2], ['k' => 'v'], new \stdClass()] as $index => $value) {
			$fixed[$index] = $rules->fixedValueFits($value, $property);
		}

		$this->assertGolden(
			[
				'typeFits' => $rules->typeFits($field, $property),
				'effectiveFormat' => $rules->effectiveFormat($field),
				'formatFits' => $rules->formatFits($field, $property),
				'enumOf' => $rules->enumOf($property),
				'optionsOf' => $rules->optionsOf($field),
				'looserConstraints' => $rules->looserConstraints($field, $property),
				'fixedValueFits' => $fixed,
			]
		);
	}//end testMatchesGolden()

	/**
	 * @param array<string, mixed> $actual
	 */
	private function assertGolden(array $actual): void {
		$actual = json_decode(json_encode($actual), true);
		$name = $this->dataName();
		$golden = [];
		if (is_file(self::FIXTURE) === true) {
			$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recording for this case.');
		$this->assertSame($golden[$name], $actual);
	}//end assertGolden()
}//end class
