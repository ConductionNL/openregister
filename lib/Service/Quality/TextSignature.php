<?php

/**
 * OpenRegister TextSignature
 *
 * The MinHash signature of a document's text, used to find near-identical
 * documents without comparing their text pair by pair.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Quality
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-every-extracted-file-carries-a-text-signature-req-ndt-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Quality;

/**
 * Normalises a text, cuts it into word 5-shingles and computes 128 MinHash values over them.
 *
 * Deterministic on every instance: the hash functions are `(a * x + b) mod p`
 * with `p` the Mersenne prime 2^31 - 1, `x` the CRC32 of a shingle, and `a`
 * and `b` derived from fixed seed strings, so the same text gives the same
 * signature everywhere and signatures from different servers compare.
 *
 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-every-extracted-file-carries-a-text-signature-req-ndt-001
 */
final class TextSignature {

	/**
	 * The number of MinHash values in a signature.
	 *
	 * @var int
	 */
	public const HASHES = 128;

	/**
	 * The number of words in a shingle.
	 *
	 * @var int
	 */
	public const SHINGLE_WORDS = 5;

	/**
	 * Fewer shingles than this and a text gets no signature.
	 *
	 * @var int
	 */
	public const MIN_SHINGLES = 50;

	/**
	 * The modulus of the hash functions: 2^31 - 1, so `a * x` fits a 64-bit integer.
	 *
	 * @var int
	 */
	private const PRIME = 2147483647;

	/**
	 * The coefficients of the hash functions, built once.
	 *
	 * @var list<array{0: int, 1: int}>|null
	 */
	private static ?array $coefficients = null;

	/**
	 * The signature of a text, its shingle count and the SHA-256 of its normalised form.
	 *
	 * A text with fewer than MIN_SHINGLES shingles gets a null signature, so a
	 * short cover note is never grouped by accident; the count still says why.
	 *
	 * @param string $text The extracted text.
	 *
	 * @return array{signature: list<int>|null, shingleCount: int, textSha256: string} The signature record.
	 *
	 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-every-extracted-file-carries-a-text-signature-req-ndt-001
	 */
	public function sign(string $text): array {
		$normalised = $this->normalise(text: $text);
		$shingles   = $this->shingles(normalised: $normalised);
		$signature  = null;
		if (count($shingles) >= self::MIN_SHINGLES) {
			$signature = $this->minHash(shingles: $shingles);
		}

		return [
			'signature'    => $signature,
			'shingleCount' => count($shingles),
			'textSha256'   => hash('sha256', $normalised),
		];
	}//end sign()

	/**
	 * Lower-case the text, strip punctuation and fold whitespace.
	 *
	 * Punctuation becomes a space, so `end.Next` stays two words.
	 *
	 * @param string $text The text.
	 *
	 * @return string The normalised text.
	 *
	 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-every-extracted-file-carries-a-text-signature-req-ndt-001
	 */
	public function normalise(string $text): string {
		$lower    = mb_strtolower($text, 'UTF-8');
		$stripped = (string)preg_replace('/[\p{P}\p{S}]+/u', ' ', $lower);

		return trim((string)preg_replace('/\s+/u', ' ', $stripped));
	}//end normalise()

	/**
	 * The distinct word 5-shingles of a normalised text, in first-seen order.
	 *
	 * @param string $normalised The normalised text.
	 *
	 * @return list<string> The shingles.
	 *
	 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-every-extracted-file-carries-a-text-signature-req-ndt-001
	 */
	public function shingles(string $normalised): array {
		if ($normalised === '') {
			return [];
		}

		$words = explode(' ', $normalised);
		$last  = count($words) - self::SHINGLE_WORDS;
		$seen  = [];
		for ($i = 0; $i <= $last; $i++) {
			$seen[implode(' ', array_slice($words, $i, self::SHINGLE_WORDS))] = true;
		}

		return array_map('strval', array_keys($seen));
	}//end shingles()

	/**
	 * The estimated Jaccard similarity of two signatures: the share of positions that agree.
	 *
	 * @param list<int> $first  A signature.
	 * @param list<int> $second Another signature.
	 *
	 * @return float The estimate, 0 to 1; 0 when the lengths differ or either is empty.
	 *
	 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-near-identical-documents-are-grouped-by-their-text-req-ndt-002
	 */
	public function estimate(array $first, array $second): float {
		$length = count($first);
		if ($length === 0 || $length !== count($second)) {
			return 0.0;
		}

		$agree = 0;
		foreach ($first as $position => $value) {
			if ($value === ($second[$position] ?? null)) {
				$agree++;
			}
		}

		return $agree / $length;
	}//end estimate()

	/**
	 * The MinHash values of a set of shingles.
	 *
	 * @param list<string> $shingles The shingles, at least one.
	 *
	 * @return list<int> HASHES values.
	 */
	private function minHash(array $shingles): array {
		$bases = array_map(static fn (string $shingle): int => crc32($shingle) % self::PRIME, $shingles);
		$mins  = [];
		foreach (self::coefficients() as [$multiplier, $offset]) {
			$min = self::PRIME;
			foreach ($bases as $base) {
				$hash = (($multiplier * $base) + $offset) % self::PRIME;
				if ($hash < $min) {
					$min = $hash;
				}
			}

			$mins[] = $min;
		}

		return $mins;
	}//end minHash()

	/**
	 * The fixed coefficients of the HASHES hash functions.
	 *
	 * @return list<array{0: int, 1: int}> The multiplier (never 0) and the offset of each.
	 */
	private static function coefficients(): array {
		if (self::$coefficients === null) {
			$built = [];
			for ($i = 0; $i < self::HASHES; $i++) {
				$built[] = [
					1 + (crc32('openregister-minhash-a-' . $i) % (self::PRIME - 1)),
					crc32('openregister-minhash-b-' . $i) % self::PRIME,
				];
			}

			self::$coefficients = $built;
		}

		return self::$coefficients;
	}//end coefficients()
}//end class
