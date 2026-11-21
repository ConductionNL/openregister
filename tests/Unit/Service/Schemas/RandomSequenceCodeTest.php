<?php

/**
 * RandomSequenceCode: a keyed permutation of the counter onto codes over an alphabet.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\\OpenRegister\\Tests\\Unit\\Service\\Schemas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-sequence-may-be-random-rather-than-counted-req-gid-004
 */

declare(strict_types=1);

namespace Unit\Service\Schemas;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Schemas\RandomSequenceCode;
use OverflowException;
use PHPUnit\Framework\TestCase;

/**
 * REQ-GID-004: every number of the counter gets its own code, codes do not
 * follow the counter, and the code space is bounded.
 *
 * @covers \OCA\OpenRegister\Service\Schemas\RandomSequenceCode
 */
final class RandomSequenceCodeTest extends TestCase {

	/**
	 * Every number of a small code space gets a distinct code: the map is a bijection.
	 *
	 * @return void
	 */
	public function testEveryNumberOfTheSpaceGetsItsOwnCode(): void {
		$code = new RandomSequenceCode(alphabet: '0123456789', length: 3, key: 'k1');
		$codes = array_map(static fn (int $n): string => $code->encode(number: $n), range(1, 1000));

		$this->assertSame(expected: 1000, actual: $code->capacity());
		$this->assertCount(expectedCount: 1000, haystack: array_unique($codes));
		foreach ($codes as $value) {
			$this->assertMatchesRegularExpression(pattern: '/^[0-9]{3}$/', string: $value);
		}
	}//end testEveryNumberOfTheSpaceGetsItsOwnCode()

	/**
	 * A space that is not a power of two is covered too (cycle walking).
	 *
	 * @return void
	 */
	public function testAnOddSizedSpaceIsCoveredExactly(): void {
		$code = new RandomSequenceCode(alphabet: 'ABC', length: 4, key: 'k1');
		$codes = array_map(static fn (int $n): string => $code->encode(number: $n), range(1, 81));

		$this->assertCount(expectedCount: 81, haystack: array_unique($codes));
	}//end testAnOddSizedSpaceIsCoveredExactly()

	/**
	 * The same key gives the same code; another key gives another order.
	 *
	 * @return void
	 */
	public function testTheCodesDependOnTheKey(): void {
		$first = new RandomSequenceCode(alphabet: '0123456789ABCDEF', length: 8, key: 'k1');
		$again = new RandomSequenceCode(alphabet: '0123456789ABCDEF', length: 8, key: 'k1');
		$other = new RandomSequenceCode(alphabet: '0123456789ABCDEF', length: 8, key: 'k2');
		$numbers = range(1, 20);

		$mine = array_map(static fn (int $n): string => $first->encode(number: $n), $numbers);
		$this->assertSame(expected: $mine, actual: array_map(static fn (int $n): string => $again->encode(number: $n), $numbers));
		$this->assertNotSame(expected: $mine, actual: array_map(static fn (int $n): string => $other->encode(number: $n), $numbers));
	}//end testTheCodesDependOnTheKey()

	/**
	 * Consecutive numbers do not give ordered codes, so the volume is not readable.
	 *
	 * @return void
	 */
	public function testConsecutiveNumbersAreNotInOrder(): void {
		$code = new RandomSequenceCode(alphabet: '0123456789', length: 10, key: 'k1');
		$codes = array_map(static fn (int $n): string => $code->encode(number: $n), range(1, 20));
		$sorted = $codes;
		sort($sorted);

		$this->assertNotSame(expected: $sorted, actual: $codes);
		$this->assertNotSame(expected: '0000000001', actual: $codes[0]);
	}//end testConsecutiveNumbersAreNotInOrder()

	/**
	 * A number past the code space is refused: the sequence is spent.
	 *
	 * @return void
	 */
	public function testANumberPastTheSpaceIsRefused(): void {
		$code = new RandomSequenceCode(alphabet: '01', length: 2, key: 'k1');
		$code->encode(number: 4);

		$this->expectException(exception: OverflowException::class);
		$code->encode(number: 5);
	}//end testANumberPastTheSpaceIsRefused()

	/**
	 * Zero is refused: the counter starts at one.
	 *
	 * @return void
	 */
	public function testZeroIsRefused(): void {
		$this->expectException(exception: OverflowException::class);
		(new RandomSequenceCode(alphabet: '01', length: 2, key: 'k1'))->encode(number: 0);
	}//end testZeroIsRefused()

	/**
	 * An unusable alphabet, length, key or space size is refused.
	 *
	 * @return array<string, array{0: string, 1: int, 2: string}>
	 */
	public static function refusedShapes(): array {
		return [
			'one character'   => ['A', 4, 'k'],
			'repeated'        => ['ABA', 4, 'k'],
			'zero length'     => ['AB', 0, 'k'],
			'no key'          => ['AB', 4, ''],
			'too large space' => ['0123456789', 19, 'k'],
		];
	}//end refusedShapes()

	/**
	 * @param string $alphabet The alphabet.
	 * @param int    $length   The length.
	 * @param string $key      The key.
	 *
	 * @return void
	 * @dataProvider refusedShapes
	 */
	public function testAnUnusableShapeIsRefused(string $alphabet, int $length, string $key): void {
		$this->expectException(exception: InvalidArgumentException::class);
		new RandomSequenceCode(alphabet: $alphabet, length: $length, key: $key);
	}//end testAnUnusableShapeIsRefused()
}//end class
