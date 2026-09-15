<?php

/**
 * OpenRegister SearchTermParser
 *
 * Parses a full-text search term into a boolean tree: `AND`, `OR`, `NOT`,
 * grouping with brackets, quoted phrases and leading or trailing wildcards.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Search;

use OCA\OpenRegister\Exception\SearchTermSyntaxException;

/**
 * Turns a search term into a tree of {@see SearchTermNode}.
 *
 * Two properties decide the shape of this class.
 *
 * A term that carries no operator, bracket, quote or wildcard is NOT parsed at
 * all: {@see needsParsing()} answers false and the caller keeps the substring
 * match this search has always done. That is what keeps `dakkapel geweigerd`
 * meaning the same thing it meant before this change, rather than silently
 * becoming a conjunction.
 *
 * A term that does carry one and cannot be read is refused, never evaluated as
 * a literal. A literal fallback returns zero rows, which on screen is
 * indistinguishable from a search that legitimately found nothing.
 *
 * Operators are recognised in upper case only, so a search for the Dutch word
 * `en` or the English `not` keeps finding the word.
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
final class SearchTermParser {
	/**
	 * Token type for a literal word or quoted phrase.
	 */
	private const TOKEN_TERM = 'term';

	/**
	 * Token type for an opening bracket.
	 */
	private const TOKEN_OPEN = 'open';

	/**
	 * Token type for a closing bracket.
	 */
	private const TOKEN_CLOSE = 'close';

	/**
	 * The boolean keywords, upper case only.
	 *
	 * @var string[]
	 */
	private const KEYWORDS = ['AND', 'OR', 'NOT'];

	/**
	 * Whether this term needs the boolean parser at all.
	 *
	 * Answering false is the backwards-compatibility guarantee: the caller then
	 * applies exactly the substring match it applied before this change.
	 *
	 * @param string $term The raw search term.
	 *
	 * @return bool True when the term carries an operator, bracket, quote or wildcard.
	 */
	public function needsParsing(string $term): bool {
		foreach (['(', ')', '"', '*'] as $marker) {
			if (str_contains($term, $marker) === true) {
				return true;
			}
		}

		return preg_match('/(?:^|\s)(?:AND|OR|NOT)(?:\s|$)/', $term) === 1;
	}//end needsParsing()

	/**
	 * Parse a term into a node tree.
	 *
	 * @param string $term The raw search term.
	 *
	 * @throws SearchTermSyntaxException When the term cannot be read.
	 *
	 * @return SearchTermNode The root node.
	 */
	public function parse(string $term): SearchTermNode {
		$tokens = $this->tokenize(term: $term);
		if (empty($tokens) === true) {
			throw new SearchTermSyntaxException(
				reason: 'The search term is empty',
				position: 1,
				term: $term
			);
		}

		$cursor = 0;
		$node = $this->parseOr(tokens: $tokens, cursor: $cursor, term: $term);

		if ($cursor < count($tokens)) {
			throw new SearchTermSyntaxException(
				reason: 'Unexpected closing bracket',
				position: $tokens[$cursor]['position'],
				term: $term
			);
		}

		return $node;
	}//end parse()

	/**
	 * Split the term into tokens, each carrying its 1-based position.
	 *
	 * @param string $term The raw search term.
	 *
	 * @throws SearchTermSyntaxException When a quote is never closed or a wildcard stands alone.
	 *
	 * @return array<int, array{type: string, value: string, position: int, leading: bool, trailing: bool}> The tokens.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 */
	private function tokenize(string $term): array {
		$tokens = [];
		$length = mb_strlen($term);
		$index = 0;

		while ($index < $length) {
			$character = mb_substr($term, $index, 1);

			if (trim($character) === '') {
				$index++;
				continue;
			}

			if ($character === '(' || $character === ')') {
				$type = self::TOKEN_OPEN;
				if ($character === ')') {
					$type = self::TOKEN_CLOSE;
				}

				$tokens[] = $this->makeToken(type: $type, value: $character, position: ($index + 1));
				$index++;
				continue;
			}

			if ($character === '"') {
				$tokens[] = $this->readPhrase(term: $term, index: $index);
				// Skip past the closing quote; readPhrase() proved it exists.
				$index = (mb_strpos($term, '"', ($index + 1)) + 1);
				continue;
			}

			$tokens[] = $this->readWord(term: $term, index: $index, length: $length);
			$index = $this->wordEnd(term: $term, index: $index, length: $length);
		}//end while

		return $tokens;
	}//end tokenize()

	/**
	 * Build a token array.
	 *
	 * @param string $type     The token type.
	 * @param string $value    The literal value.
	 * @param int    $position The 1-based position.
	 * @param bool   $leading  Whether a leading wildcard was present.
	 * @param bool   $trailing Whether a trailing wildcard was present.
	 *
	 * @return array{type: string, value: string, position: int, leading: bool, trailing: bool} The token.
	 */
	private function makeToken(
		string $type,
		string $value,
		int $position,
		bool $leading = false,
		bool $trailing = false,
	): array {
		return [
			'type' => $type,
			'value' => $value,
			'position' => $position,
			'leading' => $leading,
			'trailing' => $trailing,
		];
	}//end makeToken()

	/**
	 * Read a quoted phrase starting at the opening quote.
	 *
	 * A `*` inside quotes is a literal asterisk: the quotes are what the user
	 * reaches for to say "this exact run of characters".
	 *
	 * @param string $term  The raw search term.
	 * @param int    $index Offset of the opening quote.
	 *
	 * @throws SearchTermSyntaxException When the quote is never closed.
	 *
	 * @return array{type: string, value: string, position: int, leading: bool, trailing: bool} The phrase token.
	 */
	private function readPhrase(string $term, int $index): array {
		$closing = mb_strpos($term, '"', ($index + 1));
		if ($closing === false) {
			throw new SearchTermSyntaxException(
				reason: 'Unterminated quoted phrase opened',
				position: ($index + 1),
				term: $term
			);
		}

		$value = mb_substr($term, ($index + 1), ($closing - $index - 1));
		if (trim($value) === '') {
			throw new SearchTermSyntaxException(
				reason: 'Empty quoted phrase',
				position: ($index + 1),
				term: $term
			);
		}

		return $this->makeToken(type: self::TOKEN_TERM, value: $value, position: ($index + 1));
	}//end readPhrase()

	/**
	 * The offset just past the word starting at $index.
	 *
	 * @param string $term   The raw search term.
	 * @param int    $index  Offset of the first character.
	 * @param int    $length Length of the term.
	 *
	 * @return int The offset just past the word.
	 */
	private function wordEnd(string $term, int $index, int $length): int {
		$end = $index;
		while ($end < $length) {
			$character = mb_substr($term, $end, 1);
			if (trim($character) === '' || $character === '(' || $character === ')' || $character === '"') {
				break;
			}

			$end++;
		}

		return $end;
	}//end wordEnd()

	/**
	 * Read a bare word, which may be a keyword or a wildcarded term.
	 *
	 * @param string $term   The raw search term.
	 * @param int    $index  Offset of the first character.
	 * @param int    $length Length of the term.
	 *
	 * @throws SearchTermSyntaxException When the word is nothing but wildcards.
	 *
	 * @return array{type: string, value: string, position: int, leading: bool, trailing: bool} The token.
	 */
	private function readWord(string $term, int $index, int $length): array {
		$end = $this->wordEnd(term: $term, index: $index, length: $length);
		$raw = mb_substr($term, $index, ($end - $index));
		$position = ($index + 1);

		if (in_array($raw, self::KEYWORDS, true) === true) {
			return $this->makeToken(type: $raw, value: $raw, position: $position);
		}

		$leading = str_starts_with($raw, '*');
		$trailing = str_ends_with($raw, '*');
		$value = trim($raw, '*');

		if ($value === '') {
			throw new SearchTermSyntaxException(
				reason: 'A wildcard needs a term beside it',
				position: $position,
				term: $term
			);
		}

		return $this->makeToken(
			type: self::TOKEN_TERM,
			value: $value,
			position: $position,
			leading: $leading,
			trailing: $trailing
		);
	}//end readWord()

	/**
	 * Parse a disjunction, the loosest binding level.
	 *
	 * @param array<int, array{type: string, value: string, position: int, leading: bool, trailing: bool}> $tokens The tokens.
	 * @param int                                                                                          $cursor Cursor, by reference.
	 * @param string                                                                                       $term   The raw term, for messages.
	 *
	 * @throws SearchTermSyntaxException When an operand is missing.
	 *
	 * @return SearchTermNode The parsed node.
	 */
	private function parseOr(array $tokens, int &$cursor, string $term): SearchTermNode {
		$operands = [$this->parseAnd(tokens: $tokens, cursor: $cursor, term: $term)];

		while (($tokens[$cursor]['type'] ?? null) === 'OR') {
			$operator = $tokens[$cursor];
			$cursor++;
			if ($this->startsOperand(token: ($tokens[$cursor] ?? null)) === false) {
				throw new SearchTermSyntaxException(
					reason: 'OR needs a term after it',
					position: $operator['position'],
					term: $term
				);
			}

			$operands[] = $this->parseAnd(tokens: $tokens, cursor: $cursor, term: $term);
		}

		return SearchTermNode::any(children: $operands);
	}//end parseOr()

	/**
	 * Parse a conjunction. Two terms side by side are a conjunction too.
	 *
	 * @param array<int, array{type: string, value: string, position: int, leading: bool, trailing: bool}> $tokens The tokens.
	 * @param int                                                                                          $cursor Cursor, by reference.
	 * @param string                                                                                       $term   The raw term, for messages.
	 *
	 * @throws SearchTermSyntaxException When an operand is missing.
	 *
	 * @return SearchTermNode The parsed node.
	 */
	private function parseAnd(array $tokens, int &$cursor, string $term): SearchTermNode {
		$operands = [$this->parseNot(tokens: $tokens, cursor: $cursor, term: $term)];

		while (true) {
			$next = ($tokens[$cursor] ?? null);
			if ($next === null || $next['type'] === 'OR' || $next['type'] === self::TOKEN_CLOSE) {
				break;
			}

			if ($next['type'] === 'AND') {
				$cursor++;
				if ($this->startsOperand(token: ($tokens[$cursor] ?? null)) === false) {
					throw new SearchTermSyntaxException(
						reason: 'AND needs a term after it',
						position: $next['position'],
						term: $term
					);
				}
			}

			$operands[] = $this->parseNot(tokens: $tokens, cursor: $cursor, term: $term);
		}//end while

		return SearchTermNode::all(children: $operands);
	}//end parseAnd()

	/**
	 * Parse an optionally negated primary.
	 *
	 * @param array<int, array{type: string, value: string, position: int, leading: bool, trailing: bool}> $tokens The tokens.
	 * @param int                                                                                          $cursor Cursor, by reference.
	 * @param string                                                                                       $term   The raw term, for messages.
	 *
	 * @throws SearchTermSyntaxException When an operand is missing.
	 *
	 * @return SearchTermNode The parsed node.
	 */
	private function parseNot(array $tokens, int &$cursor, string $term): SearchTermNode {
		if (($tokens[$cursor]['type'] ?? null) !== 'NOT') {
			return $this->parsePrimary(tokens: $tokens, cursor: $cursor, term: $term);
		}

		$operator = $tokens[$cursor];
		$cursor++;
		if ($this->startsOperand(token: ($tokens[$cursor] ?? null)) === false) {
			throw new SearchTermSyntaxException(
				reason: 'NOT needs a term after it',
				position: $operator['position'],
				term: $term
			);
		}

		return SearchTermNode::not(child: $this->parseNot(tokens: $tokens, cursor: $cursor, term: $term));
	}//end parseNot()

	/**
	 * Parse a bracketed group or a single term.
	 *
	 * @param array<int, array{type: string, value: string, position: int, leading: bool, trailing: bool}> $tokens The tokens.
	 * @param int                                                                                          $cursor Cursor, by reference.
	 * @param string                                                                                       $term   The raw term, for messages.
	 *
	 * @throws SearchTermSyntaxException When a bracket is unbalanced or a term is missing.
	 *
	 * @return SearchTermNode The parsed node.
	 */
	private function parsePrimary(array $tokens, int &$cursor, string $term): SearchTermNode {
		$token = ($tokens[$cursor] ?? null);

		if ($token === null) {
			throw new SearchTermSyntaxException(
				reason: 'The search term ends where a word was expected',
				position: (mb_strlen($term) + 1),
				term: $term
			);
		}

		if ($token['type'] === self::TOKEN_CLOSE) {
			throw new SearchTermSyntaxException(
				reason: 'Unexpected closing bracket',
				position: $token['position'],
				term: $term
			);
		}

		if (in_array($token['type'], self::KEYWORDS, true) === true) {
			throw new SearchTermSyntaxException(
				reason: $token['type'] . ' needs a term before it',
				position: $token['position'],
				term: $term
			);
		}

		if ($token['type'] === self::TOKEN_OPEN) {
			return $this->parseGroup(tokens: $tokens, cursor: $cursor, term: $term);
		}

		$cursor++;

		return SearchTermNode::term(
			value: $token['value'],
			leadingWildcard: $token['leading'],
			trailingWildcard: $token['trailing']
		);
	}//end parsePrimary()

	/**
	 * Parse a bracketed group, the cursor sitting on the opening bracket.
	 *
	 * @param array<int, array{type: string, value: string, position: int, leading: bool, trailing: bool}> $tokens The tokens.
	 * @param int                                                                                          $cursor Cursor, by reference.
	 * @param string                                                                                       $term   The raw term, for messages.
	 *
	 * @throws SearchTermSyntaxException When the group is empty or never closed.
	 *
	 * @return SearchTermNode The parsed node.
	 */
	private function parseGroup(array $tokens, int &$cursor, string $term): SearchTermNode {
		$opening = $tokens[$cursor];
		$cursor++;

		if (($tokens[$cursor]['type'] ?? null) === self::TOKEN_CLOSE) {
			throw new SearchTermSyntaxException(
				reason: 'Empty group opened',
				position: $opening['position'],
				term: $term
			);
		}

		$node = $this->parseOr(tokens: $tokens, cursor: $cursor, term: $term);

		if (($tokens[$cursor]['type'] ?? null) !== self::TOKEN_CLOSE) {
			throw new SearchTermSyntaxException(
				reason: 'Unbalanced opening bracket',
				position: $opening['position'],
				term: $term
			);
		}

		$cursor++;

		return $node;
	}//end parseGroup()

	/**
	 * Whether a token can begin an operand.
	 *
	 * @param array{type: string, value: string, position: int, leading: bool, trailing: bool}|null $token The token, or null at the end.
	 *
	 * @return bool True when the token can start an operand.
	 */
	private function startsOperand(?array $token): bool {
		if ($token === null) {
			return false;
		}

		return ($token['type'] === self::TOKEN_TERM
			|| $token['type'] === self::TOKEN_OPEN
			|| $token['type'] === 'NOT');
	}//end startsOperand()
}//end class
