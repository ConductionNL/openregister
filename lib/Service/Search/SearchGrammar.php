<?php

/**
 * SearchGrammar: the entry points other components use to read search terms and history filters.
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

/**
 * The entry points other components use to read search terms and history filters.
 *
 * Keeps the parser, compiler, node and predicate classes inside lib/Service:
 * callers outside it get them typed as their interfaces from here.
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
 *
 * @SuppressWarnings(PHPMD.StaticAccess) Delegates to the pure static SearchTermNode::likeEscape() and HistoryPredicate::parse().
 */
final class SearchGrammar {
	/**
	 * A boolean search-term parser.
	 *
	 * @return SearchTermParserInterface The parser.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public static function termParser(): SearchTermParserInterface {
		return new SearchTermParser();
	}//end termParser()

	/**
	 * A compiler from a parsed search term to SQL.
	 *
	 * @return SearchTermSqlCompilerInterface The compiler.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public static function termCompiler(): SearchTermSqlCompilerInterface {
		return new SearchTermSqlCompiler();
	}//end termCompiler()

	/**
	 * Lowercase a literal and escape the LIKE wildcards in it.
	 *
	 * @param string $value The literal text.
	 *
	 * @return string The lowercased, escaped literal.
	 *
	 * @see SearchTermNode::likeEscape()
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public static function likeEscape(string $value): string {
		return SearchTermNode::likeEscape(value: $value);
	}//end likeEscape()

	/**
	 * Read the history predicate off a query.
	 *
	 * @param array<string, mixed> $query The search query.
	 *
	 * @return HistoryPredicateInterface The predicate, empty when the query carries none.
	 *
	 * @see HistoryPredicate::parse()
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	public static function historyPredicate(array $query): HistoryPredicateInterface {
		return HistoryPredicate::parse(query: $query);
	}//end historyPredicate()
}//end class
