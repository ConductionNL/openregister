<?php

/**
 * SearchTermSqlCompilerInterface: compiles a parsed search term into one SQL boolean expression.
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
 * Compiles a parsed search term into one SQL boolean expression.
 *
 * Callers outside lib/Service type against this; {@see SearchGrammar::termCompiler()} builds it.
 */
interface SearchTermSqlCompilerInterface {
	/**
	 * Compile a node tree to one SQL boolean expression.
	 *
	 * @param SearchTermNode $node        The root node.
	 * @param callable       $leafBuilder Receives a LIKE pattern and the raw literal, returns SQL for one term.
	 *
	 * @phpstan-param callable(string, string): string $leafBuilder
	 *
	 * @psalm-param callable(string, string): string $leafBuilder
	 *
	 * @return string The SQL expression, already parenthesised.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public function compile(SearchTermNode $node, callable $leafBuilder): string;
}//end interface
