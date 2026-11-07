<?php

/**
 * Golden test for SimilarityCalculator.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service\Quality
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Search\Service\Quality;

use OCA\OpenRegister\Search\Service\Quality\SimilarityCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Pins similarity() for every method over a set of value pairs, and
 * blockingToken() for every value. Scores are compared as exact floats.
 * Compared with fixtures/similarity-golden.json; set UPDATE_GOLDEN=1 to
 * rewrite it.
 *
 * @covers \OCA\OpenRegister\Search\Service\Quality\SimilarityCalculator
 */
class SimilarityCalculatorGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/similarity-golden.json';

	private const METHODS = ['exact', 'normalized', 'levenshtein', 'jaro', ''];

	/**
	 * Case name => [a, b].
	 *
	 * @return array<string, array{0: mixed, 1: mixed}>
	 */
	private static function pairs(): array {
		$long = str_repeat('abcdefghij', 30);
		return [
			'identical' => ['Acme', 'Acme'],
			'case' => ['Acme', 'acme'],
			'whitespace' => ["  Acme \t\n BV ", 'acme bv'],
			'accent' => ['Café', 'cafe'],
			'one edit' => ['Jansen', 'Janssen'],
			'different' => ['Acme', 'Globex Corp'],
			'empty both' => ['', ''],
			'empty one' => ['', 'abc'],
			'blank vs empty' => ['   ', ''],
			'numbers' => [12, '12'],
			'float' => [1.5, '1.50'],
			'bool' => [true, '1'],
			'false vs empty' => [false, ''],
			'null' => [null, 'a'],
			'array' => [['a'], 'a'],
			'long equal prefix' => [$long . 'X', $long . 'Y'],
			'long vs short' => [$long, 'abc'],
			'long one edit early' => ['Z' . $long, $long],
			'exactly 255' => [substr($long, 0, 255), substr($long, 0, 254) . 'Q'],
		];
	}//end pairs()

	public function testScoresMatchGolden(): void {
		$calculator = new SimilarityCalculator();
		$actual     = [];
		foreach (self::pairs() as $name => [$left, $right]) {
			foreach (self::METHODS as $method) {
				$actual[$name][$method] = $calculator->similarity($method, $left, $right);
				$actual[$name]['token ' . $method] = [$calculator->blockingToken($method, $left), $calculator->blockingToken($method, $right)];
			}
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(array_keys($expected), array_keys($actual));
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name], $name);
		}
	}//end testScoresMatchGolden()
}//end class
