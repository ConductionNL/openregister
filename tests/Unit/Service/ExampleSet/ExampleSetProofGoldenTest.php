<?php

/**
 * Golden test for ExampleSetProof: the lost paths lostPaths() names for a
 * fixed table of declared and stored values that reaches every branch.
 * Re-record with UPDATE_GOLDEN=1.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetProof;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetProof
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetProofPlace
 */
class ExampleSetProofGoldenTest extends TestCase {
	private const FIXTURE = __DIR__.'/fixtures/example-set-proof-golden.json';

	public function testLostPathsMatchGolden(): void {
		$proof  = new ExampleSetProof();
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$actual[$name] = $proof->lostPaths(declared: $case[0], stored: $case[1], path: ($case[2] ?? ''));
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(array_keys($expected), array_keys($actual));
		foreach ($expected as $name => $lost) {
			$this->assertSame($lost, $actual[$name], $name);
		}
	}

	/**
	 * @return array<string, array<int, mixed>> Declared, stored and, optionally, the path so far.
	 */
	private function cases(): array {
		return [
			'same scalar'                  => ['a', 'a'],
			'other scalar'                 => ['a', 'b'],
			'other scalar with path'       => ['a', 'b', 'x.y'],
			'empty text as null'           => ['', null],
			'null as empty text'           => [null, ''],
			'equal numbers'                => [1, '1.0'],
			'unequal numbers'              => [1, '2'],
			'number and text'              => [1, 'one'],
			'same moment two offsets'      => ['2026-01-02T09:12:00+02:00', '2026-01-02T07:12:00Z'],
			'other moment'                 => ['2026-01-02T09:12:00+02:00', '2026-01-02T09:12:00Z'],
			'moment not a moment'          => ['2026-01-02T', 'garbage'],
			'moment stored not text'       => ['2026-01-02T09:12:00Z', 5],
			'date without time'            => ['2026-01-02', '2026-01-02T00:00:00Z'],
			'bad moment both'              => ['2026-99-99Tx', 'nope'],
			'true and 1'                   => [true, 1],
			'flat map kept'                => [['a' => 1, 'b' => 'x'], ['a' => 1, 'b' => 'x', 'c' => 3]],
			'flat map lost one'            => [['a' => 1, 'b' => 'x'], ['a' => 1, 'b' => 'y']],
			'missing key'                  => [['a' => 1, 'b' => 2], ['a' => 1]],
			'missing key empty'            => [['a' => [], 'b' => null], []],
			'missing key with path'        => [['a' => 1], [], 'root'],
			'nested lost'                  => [['a' => ['b' => ['c' => 1, 'd' => 2]]], ['a' => ['b' => ['c' => 1, 'd' => 3]]]],
			'nested missing map'           => [['a' => ['b' => 1]], ['x' => 1]],
			'stored scalar for map'        => [['a' => 1], 'text'],
			'stored null for map'          => [['a' => 1, 'b' => []], null],
			'list kept'                    => [[1, 2], [1, 2, 3]],
			'list lost'                    => [[1, 2], [2, 1]],
			'json text stored'             => [['a' => 1], '{"a":1}'],
			'json text stored lost'        => [['a' => 1], '{"a":2}'],
			'json text declared'           => ['{"a":1}', ['a' => 1]],
			'json text declared lost'      => ['{"a":1,"b":2}', ['a' => 1]],
			'json scalar text declared'    => ['5', ['a' => 1]],
			'text declared map stored'     => ['plain', ['a' => 1]],
			'language map read'            => [['nl' => 'Huis', 'en' => 'House'], 'House'],
			'language map other text'      => [['nl' => 'Huis', 'en' => 'House'], 'Haus'],
			'language map region codes'    => [['nl-NL' => 'Huis', 'en_GB' => 'House'], 'Huis'],
			'language map bad code'        => [['dutch' => 'Huis'], 'Huis'],
			'language map numeric key'     => [['Huis'], 'Huis'],
			'language map non text'        => [['nl' => 1], 1],
			'language map non text stored' => [['nl' => 'Huis'], 'nope'],
			'empty map vs text'            => [[], 'Huis'],
			'empty map vs map'             => [[], []],
			'language map json stored'     => [['nl' => 'Huis'], '{"nl":"Huis"}'],
			'language map json other'      => [['nl' => 'Huis'], '{"nl":"Thuis"}'],
			'nested language map'          => [['t' => ['nl' => 'Huis', 'en' => 'House']], ['t' => 'House']],
			'numeric keys path'            => [['a' => [['b' => 1], ['b' => 2]]], ['a' => [['b' => 1], ['b' => 3]]]],
			'nested json text'             => [['a' => ['b' => 1]], ['a' => '{"b":2}']],
		];
	}
}
