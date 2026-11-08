<?php

/**
 * SearchTermParserInterface: reads a boolean search term into a node tree.
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
 * Reads a boolean search term into a node tree.
 *
 * Callers outside lib/Service type against this; {@see SearchGrammar::termParser()} builds it.
 */
interface SearchTermParserInterface {
	/**
	 * Whether this term needs the boolean parser at all.
	 *
	 * @param string $term The raw search term.
	 *
	 * @return bool True when the term carries an operator, bracket, quote or wildcard.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public function needsParsing(string $term): bool;

	/**
	 * Parse a term into a node tree.
	 *
	 * @param string $term The raw search term.
	 *
	 * @throws SearchTermSyntaxException When the term cannot be read.
	 *
	 * @return SearchTermNode The root node.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public function parse(string $term): SearchTermNode;
}//end interface
