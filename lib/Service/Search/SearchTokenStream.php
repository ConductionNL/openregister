<?php

/**
 * OpenRegister SearchTokenStream
 *
 * The tokens of one search term together with the term they came from.
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
 * What every step of the recursive-descent parser reads: the tokens and the raw term.
 *
 * The cursor is deliberately not part of it; it advances while the parser runs
 * and stays a by-reference integer beside this object.
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
 */
final class SearchTokenStream {

	/**
	 * Constructor.
	 *
	 * @param array<int, array{type: string, value: string, position: int, leading: bool, trailing: bool}> $tokens The tokens.
	 * @param string                                                                                       $term   The raw term, for messages.
	 */
	public function __construct(
		public readonly array $tokens,
		public readonly string $term,
	) {
	}//end __construct()

	/**
	 * The token at a cursor, or null past the end.
	 *
	 * @param int $cursor The cursor.
	 *
	 * @return array{type: string, value: string, position: int, leading: bool, trailing: bool}|null The token.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public function tokenAt(int $cursor): ?array {
		return ($this->tokens[$cursor] ?? null);
	}//end tokenAt()

	/**
	 * The type of the token at a cursor, or null past the end.
	 *
	 * @param int $cursor The cursor.
	 *
	 * @return string|null The token type.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public function typeAt(int $cursor): ?string {
		return ($this->tokens[$cursor]['type'] ?? null);
	}//end typeAt()

	/**
	 * A refusal of this term.
	 *
	 * @param string $reason   Why the term cannot be read.
	 * @param int    $position The 1-based position of the fault.
	 *
	 * @return SearchTermSyntaxException The refusal, for the caller to throw.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public function refuse(string $reason, int $position): SearchTermSyntaxException {
		return new SearchTermSyntaxException(reason: $reason, position: $position, term: $this->term);
	}//end refuse()
}//end class
