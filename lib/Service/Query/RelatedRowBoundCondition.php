<?php

/**
 * One filter condition of a related-row block, bound to its field expression and placeholder.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Query
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/query-related-schema-rows/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Query;

/**
 * One filter condition, ready to be written as SQL.
 *
 * `left` is the SQL expression of the field the condition names, already
 * spelled for the engine and storage; `placeholder` is the name its value is
 * bound under. `operator` is the parser's operator (`eq`, `gte`, `in`, ...),
 * not the SQL one: the renderer refuses an operator it has no SQL for.
 *
 * @spec openspec/changes/query-related-schema-rows/specs/zoeken-filteren/spec.md
 */
final readonly class RelatedRowBoundCondition {

	/**
	 * Bundle the condition.
	 *
	 * @param string $left        The field expression.
	 * @param string $placeholder The bound parameter's name.
	 * @param string $operator    The parser's operator.
	 * @param mixed  $value       The value or values.
	 */
	public function __construct(
		public string $left,
		public string $placeholder,
		public string $operator,
		public mixed $value,
	) {
	}//end __construct()
}//end class
