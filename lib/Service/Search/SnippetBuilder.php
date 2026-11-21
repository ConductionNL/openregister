<?php

/**
 * SnippetBuilder — the passage of a search hit, quoted only from what the reader may see.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Search
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/search-dutch-language-quality/specs/zoeken-filteren/spec.md#requirement-a-result-shows-the-passage-that-matched-only-from-what-the-reader-may-see-req-sdl-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Search;

/**
 * Builds `@self.snippet` for a property hit or a content hit.
 *
 * The passage is at most 300 characters around the first matched term,
 * HTML-escaped, with the matched terms wrapped in `<mark>`, the only markup it
 * carries. A property hit never quotes a property declared write-only or
 * encrypted, nor one the caller may not read. A content hit is quoted only
 * from an anonymised copy whose verdict is `clean`, which is published and
 * which the caller may read; anything else gives no snippet. This is the
 * window fallback of REQ-SDL-002; PostgreSQL's `ts_headline` is a later step.
 *
 * @spec openspec/changes/search-dutch-language-quality/specs/zoeken-filteren/spec.md#requirement-a-result-shows-the-passage-that-matched-only-from-what-the-reader-may-see-req-sdl-002
 */
final class SnippetBuilder {

	/**
	 * The longest passage, in characters, before escaping and marking.
	 *
	 * @var int
	 */
	public const MAX_LENGTH = 300;

	/**
	 * The only verdict under which a content passage may be quoted.
	 *
	 * @var string
	 */
	public const CLEAN_VERDICT = 'clean';

	/**
	 * The snippet of the first readable property whose value matches the search, or null.
	 *
	 * @param string                              $search     The search text.
	 * @param array<string, mixed>                $object     The object's property values.
	 * @param array<string, array<string, mixed>> $properties The schema's property definitions, in schema order.
	 * @param callable(string): bool|null         $mayRead    Whether the caller may read a property; null reads all.
	 *
	 * @return array{source: string, property: string, text: string}|null
	 *
	 * @spec openspec/changes/search-dutch-language-quality/specs/zoeken-filteren/spec.md#requirement-a-result-shows-the-passage-that-matched-only-from-what-the-reader-may-see-req-sdl-002
	 */
	public function forProperty(string $search, array $object, array $properties, ?callable $mayRead = null): ?array {
		$terms = $this->terms(search: $search);
		if ($terms === []) {
			return null;
		}

		foreach ($properties as $name => $definition) {
			$value = ($object[$name] ?? null);
			if (is_string($value) === false || $this->mayQuote(name: (string)$name, definition: $definition, mayRead: $mayRead) === false) {
				continue;
			}

			$text = $this->passage(text: $value, terms: $terms);
			if ($text !== null) {
				return ['source' => 'property', 'property' => (string)$name, 'text' => $text];
			}
		}

		return null;
	}//end forProperty()

	/**
	 * The snippet of a content hit, or null when its file may not be quoted or it does not match.
	 *
	 * @param string               $search The search text.
	 * @param ContentSnippetSource $source The chunk and its file's facts.
	 *
	 * @return array{source: string, fileId: int, text: string}|null
	 *
	 * @spec openspec/changes/search-dutch-language-quality/specs/zoeken-filteren/spec.md#requirement-a-result-shows-the-passage-that-matched-only-from-what-the-reader-may-see-req-sdl-002
	 */
	public function forContent(string $search, ContentSnippetSource $source): ?array {
		if ($source->anonymisedCopy === false
			|| $source->verdict !== self::CLEAN_VERDICT
			|| $source->published === false
			|| $source->readableByCaller === false
		) {
			return null;
		}

		$text = $this->passage(text: $source->text, terms: $this->terms(search: $search));
		if ($text === null) {
			return null;
		}

		return ['source' => 'content', 'fileId' => $source->fileId, 'text' => $text];
	}//end forContent()

	/**
	 * Whether a property may be quoted at all.
	 *
	 * @param string                      $name       The property name.
	 * @param mixed                       $definition The property definition.
	 * @param callable(string): bool|null $mayRead    Whether the caller may read a property.
	 *
	 * @return bool
	 */
	private function mayQuote(string $name, mixed $definition, ?callable $mayRead): bool {
		if (is_array($definition) === false
			|| ($definition['writeOnly'] ?? false) === true
			|| ($definition['x-openregister-encrypted'] ?? false) === true
		) {
			return false;
		}

		return $mayRead === null || $mayRead($name) === true;
	}//end mayQuote()

	/**
	 * The search terms, lower-cased, unique, longest first so a longer term is marked before its part.
	 *
	 * @param string $search The search text.
	 *
	 * @return list<string>
	 */
	private function terms(string $search): array {
		$terms = [];
		$words = preg_split('/\s+/u', mb_strtolower(trim($search)));
		if ($words === false) {
			$words = [];
		}

		foreach ($words as $term) {
			$term = trim($term, '"\'*%');
			if (mb_strlen($term) >= 2) {
				$terms[$term] = true;
			}
		}

		// A numeric term becomes an integer array key; the terms are strings again here.
		$terms = array_map('strval', array_keys($terms));
		usort($terms, static fn (string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));

		return $terms;
	}//end terms()

	/**
	 * The marked, escaped window around the first match, or null when no term occurs.
	 *
	 * @param string       $text  The text to quote from.
	 * @param list<string> $terms The terms.
	 *
	 * @return string|null
	 */
	private function passage(string $text, array $terms): ?string {
		$text = trim((string)preg_replace('/\s+/u', ' ', $text));
		$first = null;
		foreach ($terms as $term) {
			$position = mb_stripos($text, $term);
			if ($position !== false && ($first === null || $position < $first)) {
				$first = $position;
			}
		}

		if ($first === null) {
			return null;
		}

		$length = mb_strlen($text);
		if ($length <= self::MAX_LENGTH) {
			return $this->mark(text: $text, terms: $terms);
		}

		// Room for an ellipsis on both sides keeps the quoted text within the bound.
		$size = (self::MAX_LENGTH - 2);
		$start = max(0, min($first - intdiv($size, 3), $length - $size));
		$prefix = '';
		if ($start > 0) {
			$prefix = '…';
		}

		$suffix = '';
		if ($start + $size < $length) {
			$suffix = '…';
		}

		return $prefix . $this->mark(text: mb_substr($text, $start, $size), terms: $terms) . $suffix;
	}//end passage()

	/**
	 * Escape a text and wrap every occurrence of a term in `<mark>`.
	 *
	 * The text is split on the terms first and each piece escaped on its own,
	 * so the marks are the only markup and a term can never match inside an
	 * escape sequence.
	 *
	 * @param string       $text  The text.
	 * @param list<string> $terms The terms, longest first.
	 *
	 * @return string
	 */
	private function mark(string $text, array $terms): string {
		$pattern = '/(' . implode('|', array_map(static fn (string $term): string => preg_quote($term, '/'), $terms)) . ')/iu';
		$pieces = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
		if ($pieces === false) {
			return htmlspecialchars($text, (ENT_QUOTES | ENT_HTML5), 'UTF-8');
		}

		$marked = '';
		foreach ($pieces as $index => $piece) {
			$escaped = htmlspecialchars($piece, (ENT_QUOTES | ENT_HTML5), 'UTF-8');
			if ($index % 2 === 1) {
				$escaped = '<mark>' . $escaped . '</mark>';
			}

			$marked .= $escaped;
		}

		return $marked;
	}//end mark()
}//end class
