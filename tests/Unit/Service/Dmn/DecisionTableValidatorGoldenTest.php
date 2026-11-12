<?php

/**
 * Golden test for DecisionTableValidator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Dmn
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Dmn;

use OCA\OpenRegister\Service\Dmn\DecisionTableValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Each case validates one decision table and records the exact list of
 * problems (or the error thrown). Compared with
 * fixtures/decision-table-validator-golden.json; set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Service\Dmn\DecisionTableValidator
 */
class DecisionTableValidatorGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/decision-table-validator-golden.json';

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$inputs  = [['name' => 'age', 'type' => 'number'], ['name' => 'city', 'type' => 'string']];
		$outputs = [['name' => 'fee']];
		$rule    = ['id' => 'r1', 'inputEntries' => ['>= 18', '"Utrecht"'], 'outputEntries' => ['10']];
		$valid   = ['hitPolicy' => 'UNIQUE', 'inputs' => $inputs, 'outputs' => $outputs, 'rules' => [$rule]];
		return [
			'valid'                       => [$valid],
			'empty table'                 => [[]],
			'hit policy lower-case'       => [['hitPolicy' => ' first '] + $valid],
			'hit policy unknown'          => [['hitPolicy' => 'RULE ORDER'] + $valid],
			'hit policy numeric'          => [['hitPolicy' => 7] + $valid],
			'inputs scalar'               => [['inputs' => 'x'] + $valid],
			'outputs empty'               => [['outputs' => []] + $valid],
			'column not object'           => [['inputs' => ['age', ['name' => 'city']]] + $valid],
			'column no name'              => [['inputs' => [['name' => '  '], ['type' => 'number'], ['name' => 'city']]] + $valid],
			'column duplicate name'       => [['inputs' => [['name' => 'age'], ['name' => ' age '], ['name' => 'city']]] + $valid],
			'column keyed list'           => [['inputs' => ['a' => ['name' => 'age', 'type' => 'number'], 'b' => ['name' => 'city']]] + $valid],
			'column type alias'           => [['inputs' => [['name' => 'age', 'type' => 'integer'], ['name' => 'city', 'type' => 'weird']]] + $valid],
			'output duplicate'            => [['outputs' => [['name' => 'fee'], ['name' => 'fee']]] + $valid],
			'rules scalar'                => [['rules' => 'x'] + $valid],
			'rules empty'                 => [['rules' => []] + $valid],
			'rule not object'             => [['rules' => ['x', $rule]] + $valid],
			'rule without id'             => [['rules' => [['inputEntries' => ['-', '-'], 'outputEntries' => ['1']], ['id' => '  ', 'inputEntries' => ['-'], 'outputEntries' => []]]] + $valid],
			'rule short row'              => [['rules' => [['id' => 'r', 'inputEntries' => ['>= 18'], 'outputEntries' => ['1']]]] + $valid],
			'rule long row'               => [['rules' => [['id' => 'r', 'inputEntries' => ['1', '"x"', '2'], 'outputEntries' => ['1', '2']]]] + $valid],
			'rule entries not lists'      => [['rules' => [['id' => 'r', 'inputEntries' => 'x', 'outputEntries' => 5]]] + $valid],
			'rule entries keyed'          => [['rules' => [['id' => 'r', 'inputEntries' => ['a' => '>= 18', 'b' => '"x"'], 'outputEntries' => ['z' => '1']]]] + $valid],
			'rule priority int'           => [['rules' => [$rule + ['priority' => 3]]] + $valid],
			'rule priority string'        => [['rules' => [$rule + ['priority' => '3']]] + $valid],
			'rule priority null'          => [['rules' => [$rule + ['priority' => null]]] + $valid],
			'rule bad cells'              => [['rules' => [['id' => 'r', 'inputEntries' => ['>= abc', '[1..'], 'outputEntries' => ['1']]]] + $valid],
			'rule numeric cell'           => [['rules' => [['id' => 'r', 'inputEntries' => [18, true], 'outputEntries' => ['1']]]] + $valid],
			'rule cells on dropped input' => [['inputs' => [['name' => 'age', 'type' => 'number'], 'bad', ['name' => 'when', 'type' => 'date']], 'rules' => [['id' => 'r', 'inputEntries' => ['> 1', 'x', '"2026-01-01"'], 'outputEntries' => ['1']]]] + $valid],
			'boolean column'              => [['inputs' => [['name' => 'ok', 'type' => 'boolean']], 'rules' => [['id' => 'r', 'inputEntries' => ['true'], 'outputEntries' => ['1']], ['id' => 's', 'inputEntries' => ['maybe'], 'outputEntries' => ['1']]]] + $valid],
			'everything wrong'            => [['hitPolicy' => 'X', 'inputs' => null, 'outputs' => 'y', 'rules' => null]],
			'no columns but rules'        => [['inputs' => [], 'outputs' => [], 'rules' => [['id' => 'r', 'inputEntries' => ['1'], 'outputEntries' => ['1']]]]],
		];
	}

	/**
	 * @param array<string, mixed> $table The table.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testValidate(array $table): void {
		try {
			$actual = (new DecisionTableValidator())->validate($table);
		} catch (\Throwable $e) {
			$actual = ['thrown' => get_class($e)];
		}

		$case = (string) $this->dataName();
		$golden = [];
		if (file_exists(self::FIXTURE) === true) {
			$golden = json_decode((string) file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$case] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($case, $golden, 'No golden entry; record with UPDATE_GOLDEN=1.');
		$this->assertSame($golden[$case], $actual);
	}
}
