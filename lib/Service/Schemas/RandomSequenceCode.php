<?php

/**
 * A random-looking code for each number of a counter.
 *
 * A random generated identifier is a counter whose numbers are shown through a
 * keyed permutation (generated-identifier, D-C41-1): the counter keeps the one
 * allocation path and its lock, so two creates still never get the same number,
 * and the permutation turns number n into a code of `length` characters from
 * `alphabet` that nobody without the key can map back to n or to its
 * neighbours. A permutation never sends two numbers to one code, so uniqueness
 * is the counter's own and needs no lookup of the codes already issued.
 *
 * The permutation is a four-round Feistel network over the smallest
 * even-bit-width domain that holds every code, with HMAC-SHA256 as the round
 * function, walked in cycles until it lands inside the code space. Feistel is
 * a bijection on its domain, and cycle walking restricts a bijection to a
 * subset as a bijection, so every number from 1 to capacity() gets a code of
 * its own.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-sequence-may-be-random-rather-than-counted-req-gid-004
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schemas;

use InvalidArgumentException;
use OverflowException;

/**
 * Maps the numbers of a counter one-to-one onto codes over an alphabet.
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-sequence-may-be-random-rather-than-counted-req-gid-004
 */
final class RandomSequenceCode {

	/**
	 * The Feistel rounds.
	 */
	private const ROUNDS = 4;

	/**
	 * The largest code space supported, so every intermediate value fits a 64-bit integer.
	 */
	public const MAX_CAPACITY = 1 << 60;

	/**
	 * The characters of the alphabet, in order.
	 *
	 * @var array<int, string>
	 */
	private readonly array $symbols;

	/**
	 * How many codes there are: the alphabet size to the power of the length.
	 *
	 * @var integer
	 */
	private readonly int $capacity;

	/**
	 * The characters per code.
	 *
	 * @var integer
	 */
	private readonly int $length;

	/**
	 * Half the bit width of the Feistel domain.
	 *
	 * @var integer
	 */
	private readonly int $halfBits;

	/**
	 * Constructor.
	 *
	 * @param string $alphabet The characters a code is written in, each once, at least two.
	 * @param int    $length   The characters per code, at least one.
	 * @param string $key      The secret that keys the permutation.
	 *
	 * @throws InvalidArgumentException When the alphabet, length or key is unusable, or the code space is too large.
	 */
	public function __construct(string $alphabet, int $length, private readonly string $key) {
		$this->symbols = self::symbolsOf(alphabet: $alphabet);
		$this->capacity = self::capacityOf(base: count($this->symbols), length: $length);
		if ($this->key === '') {
			throw new InvalidArgumentException('A random sequence needs a key.');
		}

		$bits = 2;
		while ((1 << $bits) < $this->capacity) {
			$bits += 2;
		}

		$this->halfBits = intdiv($bits, 2);
		$this->length = $length;
	}//end __construct()

	/**
	 * The distinct characters of an alphabet, refused when there are fewer than two or one repeats.
	 *
	 * @param string $alphabet The alphabet.
	 *
	 * @return array<int, string> The characters.
	 *
	 * @throws InvalidArgumentException When the alphabet is unusable.
	 */
	public static function symbolsOf(string $alphabet): array {
		$symbols = mb_str_split($alphabet);
		if (count($symbols) < 2 || count(array_unique($symbols)) !== count($symbols)) {
			throw new InvalidArgumentException('The alphabet needs at least two characters, none repeated.');
		}

		return $symbols;
	}//end symbolsOf()

	/**
	 * How many codes of this length an alphabet of this size writes.
	 *
	 * @param int $base   The alphabet size.
	 * @param int $length The characters per code.
	 *
	 * @return int The count.
	 *
	 * @throws InvalidArgumentException When the length is below one or the count exceeds MAX_CAPACITY.
	 */
	public static function capacityOf(int $base, int $length): int {
		if ($length < 1) {
			throw new InvalidArgumentException('A random sequence needs a length of at least one.');
		}

		$capacity = 1;
		for ($i = 0; $i < $length; $i++) {
			if ($capacity > intdiv(self::MAX_CAPACITY, $base)) {
				throw new InvalidArgumentException('The code space of this alphabet and length is too large.');
			}

			$capacity *= $base;
		}

		return $capacity;
	}//end capacityOf()

	/**
	 * How many distinct codes this sequence can issue.
	 *
	 * @return int The count.
	 */
	public function capacity(): int {
		return $this->capacity;
	}//end capacity()

	/**
	 * The code for one number of the counter.
	 *
	 * @param int $number The counter value, from 1 to capacity().
	 *
	 * @return string The code: `length` characters of the alphabet.
	 *
	 * @throws OverflowException When the number is outside the code space, so the sequence is spent.
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-sequence-may-be-random-rather-than-counted-req-gid-004
	 */
	public function encode(int $number): string {
		if ($number < 1 || $number > $this->capacity) {
			throw new OverflowException('The random sequence has issued every code it has.');
		}

		$index = $this->permute(value: ($number - 1));
		while ($index >= $this->capacity) {
			$index = $this->permute(value: $index);
		}

		return $this->write(index: $index);
	}//end encode()

	/**
	 * One pass of the Feistel network over the even-bit-width domain.
	 *
	 * @param int $value A value inside the domain.
	 *
	 * @return int Its image inside the domain.
	 */
	private function permute(int $value): int {
		$mask = ((1 << $this->halfBits) - 1);
		$left = ($value >> $this->halfBits) & $mask;
		$right = $value & $mask;
		for ($round = 0; $round < self::ROUNDS; $round++) {
			[$left, $right] = [$right, ($left ^ $this->round(round: $round, half: $right)) & $mask];
		}

		return ($left << $this->halfBits) | $right;
	}//end permute()

	/**
	 * The keyed round function: the first eight bytes of an HMAC, reduced to the half width.
	 *
	 * @param int $round The round number.
	 * @param int $half  The half being mixed in.
	 *
	 * @return int The round value.
	 */
	private function round(int $round, int $half): int {
		$digest = hash_hmac('sha256', $round.':'.$half, $this->key, true);
		$value = unpack('J', substr($digest, 0, 8))[1];

		return $value & ((1 << $this->halfBits) - 1);
	}//end round()

	/**
	 * An index written in the alphabet, left-padded to the code length.
	 *
	 * @param int $index A value below capacity().
	 *
	 * @return string The code.
	 */
	private function write(int $index): string {
		$base = count($this->symbols);
		$code = '';
		for ($i = 0; $i < $this->length; $i++) {
			$code = $this->symbols[($index % $base)].$code;
			$index = intdiv($index, $base);
		}

		return $code;
	}//end write()
}//end class
