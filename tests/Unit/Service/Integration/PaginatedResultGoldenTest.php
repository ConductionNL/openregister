<?php

/**
 * Golden record of PaginatedResult::fromMixed().
 *
 * Non-arrays, an existing instance, flat lists and keyed arrays, and
 * envelopes with `items` and/or `results` (arrays, nulls, scalars), numeric,
 * non-numeric and missing `total`, and `nextCursor` values of every type.
 * Each answer is pinned through toArray().
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec exclude Pins the paginated-result normalisation.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

use OCA\OpenRegister\Service\Integration\PaginatedResult;
use PHPUnit\Framework\TestCase;

/**
 * fromMixed(), recorded.
 *
 * @covers \OCA\OpenRegister\Service\Integration\PaginatedResult
 */
class PaginatedResultGoldenTest extends TestCase {

	/**
	 * The inputs and their toArray() answers.
	 *
	 * @return array<string, array{0: mixed, 1: array<string, mixed>}>
	 */
	public static function cases(): array {
		$rows = [['id' => 1], ['id' => 2]];
		$out = static fn (array $items, int $total, ?string $cursor = null): array => [
			'items' => $items, 'results' => $items, 'total' => $total, 'nextCursor' => $cursor,
		];
		return [
			'null' => [null, $out([], 0)],
			'string' => ['rows', $out([], 0)],
			'int' => [3, $out([], 0)],
			'object' => [new \stdClass(), $out([], 0)],
			'empty array' => [[], $out([], 0)],
			'flat list' => [$rows, $out($rows, 2)],
			'keyed flat array' => [['a' => 'x', 'b' => 'y', 'total' => 9], $out(['x', 'y', 9], 3)],
			'items envelope' => [['items' => ['k' => 'v', 'w'], 'total' => '7', 'nextCursor' => 12], $out(['v', 'w'], 7, '12')],
			'results envelope' => [['results' => $rows, 'total' => 1.9, 'nextCursor' => 'c2'], $out($rows, 1, 'c2')],
			'items wins over results' => [['items' => [1], 'results' => [2, 3]], $out([1], 1)],
			'null items fall back to results' => [['items' => null, 'results' => [2, 3]], $out([2, 3], 2)],
			'scalar items' => [['items' => 'nope', 'results' => [1]], $out([], 0)],
			'null results' => [['results' => null], $out([], 0)],
			'non-numeric total' => [['results' => $rows, 'total' => 'many'], $out($rows, 2)],
			'null total' => [['results' => $rows, 'total' => null], $out($rows, 2)],
			'numeric-string total' => [['results' => $rows, 'total' => ' 5'], $out($rows, 5)],
			'null cursor' => [['results' => [], 'nextCursor' => null], $out([], 0)],
			'false cursor' => [['results' => [], 'nextCursor' => false], $out([], 0, '')],
			'zero cursor' => [['results' => [], 'nextCursor' => 0], $out([], 0, '0')],
			'empty cursor' => [['results' => [], 'nextCursor' => ''], $out([], 0, '')],
		];
	}//end cases()

	/**
	 * Each input normalises as pinned.
	 *
	 * @param mixed                $value    The input.
	 * @param array<string, mixed> $expected The toArray() answer.
	 *
	 * @dataProvider cases
	 *
	 * @return void
	 */
	public function testFromMixedMatchesTheGoldenRecord(mixed $value, array $expected): void {
		$this->assertSame($expected, PaginatedResult::fromMixed($value)->toArray());
	}//end testFromMixedMatchesTheGoldenRecord()

	/**
	 * An instance passes through as itself.
	 *
	 * @return void
	 */
	public function testAnInstanceIsReturnedAsIs(): void {
		$result = new PaginatedResult([['id' => 1]], 40, 'next');
		$this->assertSame($result, PaginatedResult::fromMixed($result));
		$this->assertSame(['items' => [['id' => 1]], 'results' => [['id' => 1]], 'total' => 40, 'nextCursor' => 'next'], $result->toArray());
	}//end testAnInstanceIsReturnedAsIs()
}//end class
