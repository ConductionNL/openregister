<?php

/**
 * OCR words with their positions, as a provider hands them over.
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

use InvalidArgumentException;

/**
 * Validates and normalises a `words: [{text, page, box}]` list.
 *
 * A box is `{x, y, w, h}`, each between 0 and 1 relative to the image, so a
 * word box survives any re-rendering of the page at another resolution.
 *
 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-images-and-image-content-are-detected-through-one-seam-req-ais-001
 */
final class OcrWords {

	/**
	 * The most words one file may carry.
	 */
	public const MAX_WORDS = 200000;

	/**
	 * Normalise a submitted word list.
	 *
	 * @param array<mixed> $words The submitted words.
	 *
	 * @return list<array{text: string, page: int|null, box: array{x: float, y: float, w: float, h: float}}> The words.
	 *
	 * @throws InvalidArgumentException When a word, page or box is not valid.
	 *
	 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-images-and-image-content-are-detected-through-one-seam-req-ais-001
	 */
	public static function normalise(array $words): array {
		if (count($words) > self::MAX_WORDS) {
			throw new InvalidArgumentException(sprintf('words may hold at most %d entries.', self::MAX_WORDS));
		}

		$out = [];
		foreach (array_values($words) as $index => $word) {
			if (is_array($word) === false || is_string($word['text'] ?? null) === false) {
				throw new InvalidArgumentException(sprintf('words[%d] needs a text.', $index));
			}

			$out[] = [
				'text' => $word['text'],
				'page' => self::page(value: ($word['page'] ?? null), index: $index),
				'box' => self::box(value: ($word['box'] ?? null), label: sprintf('words[%d].box', $index)),
			];
		}

		return $out;
	}//end normalise()

	/**
	 * Validate a box: x, y, w and h between 0 and 1, inside the image.
	 *
	 * @param mixed  $value The submitted box.
	 * @param string $label What to name in the message.
	 *
	 * @return array{x: float, y: float, w: float, h: float} The box.
	 *
	 * @throws InvalidArgumentException When it is not valid.
	 *
	 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-images-and-image-content-are-detected-through-one-seam-req-ais-001
	 */
	public static function box(mixed $value, string $label): array {
		if (is_array($value) === false) {
			throw new InvalidArgumentException($label . ' must be an object with x, y, w and h.');
		}

		$box = [];
		foreach (['x', 'y', 'w', 'h'] as $key) {
			$number = ($value[$key] ?? null);
			if ((is_int($number) === false && is_float($number) === false) || $number < 0 || $number > 1) {
				throw new InvalidArgumentException(sprintf('%s.%s must be a number from 0 to 1.', $label, $key));
			}

			$box[$key] = (float)$number;
		}

		if ($box['x'] + $box['w'] > 1.000001 || $box['y'] + $box['h'] > 1.000001) {
			throw new InvalidArgumentException($label . ' reaches outside the image.');
		}

		return $box;
	}//end box()

	/**
	 * Validate a page number: null or a whole number from 1.
	 *
	 * @param mixed $value The submitted page.
	 * @param int   $index The word's index.
	 *
	 * @return int|null The page.
	 *
	 * @throws InvalidArgumentException When it is not valid.
	 */
	private static function page(mixed $value, int $index): ?int {
		if ($value === null) {
			return null;
		}

		if (is_int($value) === false || $value < 1) {
			throw new InvalidArgumentException(sprintf('words[%d].page must be a whole number from 1.', $index));
		}

		return $value;
	}//end page()
}//end class
