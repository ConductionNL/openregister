<?php

/**
 * OpenRegister SearchTermSqlCompiler
 *
 * Turns a parsed search term into one SQL boolean expression, leaving the
 * per-column matching to the caller that knows its own columns.
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
 * Compiles a {@see SearchTermNode} tree into SQL.
 *
 * The compiler never knows which columns are searchable. It asks the caller for
 * one SQL fragment per literal term, and composes those fragments with AND, OR
 * and NOT. That is how the QueryBuilder path and the raw-SQL UNION path in
 * MagicSearchHandler share one grammar while keeping their own column rules.
 *
 * The leaf fragment MUST be null-safe. A `NOT` over an expression that
 * evaluates to NULL is NULL, not TRUE, so a record whose searchable columns are
 * all empty would silently drop out of `NOT geweigerd` — which is the class of
 * silent wrong answer this whole change exists to remove. Callers wrap each
 * column in COALESCE for that reason.
 */
final class SearchTermSqlCompiler {
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
	public function compile(SearchTermNode $node, callable $leafBuilder): string {
		if ($node->type === SearchTermNode::TYPE_TERM) {
			return '(' . $leafBuilder($node->likePattern(), $node->value) . ')';
		}

		if ($node->type === SearchTermNode::TYPE_NOT) {
			return '(NOT ' . $this->compile(node: $node->children[0], leafBuilder: $leafBuilder) . ')';
		}

		$glue = ' AND ';
		if ($node->type === SearchTermNode::TYPE_OR) {
			$glue = ' OR ';
		}

		$parts = [];
		foreach ($node->children as $child) {
			$parts[] = $this->compile(node: $child, leafBuilder: $leafBuilder);
		}

		return '(' . implode($glue, $parts) . ')';
	}//end compile()
}//end class
