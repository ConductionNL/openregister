<?php

/**
 * Finds where a detected text value sits among OCR words.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-images-and-image-content-are-detected-through-one-seam-req-ais-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

/**
 * Matches a value's tokens against consecutive words on one page and answers
 * the box that covers every matched word, once per occurrence, in reading order.
 *
 * Tokens are compared case-insensitively with punctuation at their edges
 * dropped, so "Jansen," in the OCR matches the entity value "Jansen".
 *
 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-images-and-image-content-are-detected-through-one-seam-req-ais-001
 */
class OcrWordLocator {

	/**
	 * Every occurrence of a value among the words.
	 *
	 * @param string                                                                                  $value The value.
	 * @param list<array{text: string, page: int|null, box: array{x: float, y: float, w: float, h: float}}> $words The OCR words.
	 *
	 * @return list<array{page: int|null, box: array{x: float, y: float, w: float, h: float}}> The occurrences.
	 *
	 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-images-and-image-content-are-detected-through-one-seam-req-ais-001
	 */
	public function locate(string $value, array $words): array {
		$tokens = $this->tokens(text: $value);
		if ($tokens === []) {
			return [];
		}

		$normalised = [];
		foreach ($words as $word) {
			$normalised[] = implode(' ', $this->tokens(text: $word['text']));
		}

		$found = [];
		$count = count($words);
		$index = 0;
		while ($index < $count) {
			$end = $this->matchAt(tokens: $tokens, normalised: $normalised, words: $words, start: $index);
			if ($end === null) {
				$index++;
				continue;
			}

			$found[] = [
				'page' => $words[$index]['page'],
				'box' => $this->union(words: array_slice($words, $index, ($end - $index + 1))),
			];
			$index = ($end + 1);
		}

		return $found;
	}//end locate()

	/**
	 * The index of the last word of a match starting at $start, or null.
	 *
	 * @param list<string>                     $tokens     The value's tokens.
	 * @param list<string>                     $normalised The words, normalised.
	 * @param list<array{page: int|null}>      $words      The words, for their page.
	 * @param int                              $start      Where to start.
	 *
	 * @return int|null The last word's index.
	 */
	private function matchAt(array $tokens, array $normalised, array $words, int $start): ?int {
		$wanted = $tokens;
		$index = $start;
		$count = count($normalised);
		while ($wanted !== []) {
			if ($index >= $count || $words[$index]['page'] !== $words[$start]['page']) {
				return null;
			}

			if ($normalised[$index] === '') {
				// A word that is only punctuation sits between tokens without breaking the match.
				if ($index === $start) {
					return null;
				}

				$index++;
				continue;
			}

			$wordTokens = explode(' ', $normalised[$index]);
			if (array_slice($wanted, 0, count($wordTokens)) !== $wordTokens) {
				return null;
			}

			$wanted = array_slice($wanted, count($wordTokens));
			$index++;
		}//end while

		return ($index - 1);
	}//end matchAt()

	/**
	 * Lower-cased tokens with their edge punctuation dropped.
	 *
	 * @param string $text The text.
	 *
	 * @return list<string> The tokens.
	 */
	private function tokens(string $text): array {
		$out = [];
		foreach (preg_split('/\s+/u', mb_strtolower(trim($text))) as $token) {
			$token = preg_replace('/^[\p{P}\p{S}]+|[\p{P}\p{S}]+$/u', '', $token);
			if ($token !== null && $token !== '') {
				$out[] = $token;
			}
		}

		return $out;
	}//end tokens()

	/**
	 * The box that covers every given word.
	 *
	 * @param list<array{box: array{x: float, y: float, w: float, h: float}}> $words The words.
	 *
	 * @return array{x: float, y: float, w: float, h: float} The box.
	 */
	private function union(array $words): array {
		$left = 1.0;
		$top = 1.0;
		$right = 0.0;
		$bottom = 0.0;
		foreach ($words as $word) {
			$box = $word['box'];
			$left = min($left, $box['x']);
			$top = min($top, $box['y']);
			$right = max($right, ($box['x'] + $box['w']));
			$bottom = max($bottom, ($box['y'] + $box['h']));
		}

		return ['x' => $left, 'y' => $top, 'w' => ($right - $left), 'h' => ($bottom - $top)];
	}//end union()
}//end class
