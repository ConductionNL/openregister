<?php

/**
 * TextFolder: case and accent folding for normalized term matching.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\RedactionPolicy
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\RedactionPolicy;

/**
 * Folds text for a `normalized` match: lower case, accents removed, ASCII.
 *
 * Uses ICU transliteration; without the intl extension it degrades to case
 * folding only, rather than failing.
 *
 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#REQ-RPD-001
 */
class TextFolder {

	/**
	 * The ICU rule set: any script to Latin, Latin to ASCII, lower case.
	 *
	 * @var string
	 */
	private const RULESET = 'Any-Latin; Latin-ASCII; Lower';

	/**
	 * The transliterator, once looked up; false when intl cannot provide one.
	 *
	 * @var \Transliterator|false|null
	 */
	private \Transliterator|false|null $transliterator = null;

	/**
	 * Fold a value and trim it.
	 *
	 * @param string $value The value.
	 *
	 * @return string The folded value.
	 *
	 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#REQ-RPD-001
	 */
	public function fold(string $value): string {
		return trim($this->foldPart(value: $value));
	}//end fold()

	/**
	 * Find every occurrence of a term in a text, compared folded, on word boundaries.
	 *
	 * @param string $text The text searched.
	 * @param string $term The term (folded here).
	 *
	 * @return array<int, array{start: int, length: int, text: string}> Character offsets into the original text.
	 *
	 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#REQ-RPD-001
	 */
	public function occurrences(string $text, string $term): array {
		$needle = $this->fold(value: $term);
		if ($needle === '') {
			return [];
		}

		$chars  = mb_str_split($text);
		$folded = '';
		$origin = [];
		foreach ($chars as $index => $char) {
			$part    = $this->foldPart(value: $char);
			$folded .= $part;
			$origin  = array_merge($origin, array_fill(0, strlen($part), $index));
		}

		$found  = [];
		$offset = strpos($folded, $needle);
		while ($offset !== false) {
			$start = $origin[$offset];
			$end   = ($origin[$offset + strlen($needle) - 1] + 1);
			if ($this->isWordAt(chars: $chars, start: $start, end: $end) === true) {
				$found[] = ['start' => $start, 'length' => ($end - $start), 'text' => implode('', array_slice($chars, $start, ($end - $start)))];
			}

			$offset = strpos($folded, $needle, ($offset + 1));
		}

		return $found;
	}//end occurrences()

	/**
	 * Whether the characters before start and at end are not letters or digits.
	 *
	 * @param array<int, string> $chars The text's characters.
	 * @param int                $start The first character of the occurrence.
	 * @param int                $end   The character after the occurrence.
	 *
	 * @return bool
	 */
	private function isWordAt(array $chars, int $start, int $end): bool {
		foreach ([($chars[($start - 1)] ?? ''), ($chars[$end] ?? '')] as $neighbour) {
			if ($neighbour !== '' && preg_match('/[\p{L}\p{N}]/u', $neighbour) === 1) {
				return false;
			}
		}

		return true;
	}//end isWordAt()

	/**
	 * Fold a value without trimming.
	 *
	 * @param string $value The value.
	 *
	 * @return string
	 */
	private function foldPart(string $value): string {
		$transliterator = $this->transliterator();
		if ($transliterator !== false) {
			$folded = $transliterator->transliterate($value);
			if (is_string($folded) === true) {
				return $folded;
			}
		}

		// Without intl only case is folded.
		return mb_strtolower($value);
	}//end foldPart()

	/**
	 * The ICU transliterator, or false without intl.
	 *
	 * @return \Transliterator|false
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Transliterator::create() is intl's only constructor.
	 */
	private function transliterator(): \Transliterator|false {
		if ($this->transliterator === null) {
			$this->transliterator = false;
			if (class_exists(\Transliterator::class) === true) {
				$this->transliterator = (\Transliterator::create(self::RULESET) ?? false);
			}
		}

		return $this->transliterator;
	}//end transliterator()
}//end class
