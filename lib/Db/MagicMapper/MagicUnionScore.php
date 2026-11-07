<?php

/**
 * MagicUnionScore: the search score of one arm of a cross-schema search.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/objects-crud/spec.md#requirement-a-multi-schema-search-orders-the-same-on-every-path
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

/**
 * The `_search_score` expression of a UNION arm.
 *
 * PostgreSQL scores by pg_trgm similarity() when the extension is there and
 * by ILIKE when it is not; MariaDB and MySQL score by LIKE.
 *
 * @spec openspec/specs/objects-crud/spec.md#requirement-a-multi-schema-search-orders-the-same-on-every-path
 */
final class MagicUnionScore {
	/**
	 * Constructor.
	 *
	 * @param string $quotedTerm The search term, quoted by the connection.
	 * @param bool   $isPostgres Whether the connection is PostgreSQL.
	 * @param bool   $hasTrgm    Whether pg_trgm is available.
	 *
	 * @spec openspec/specs/objects-crud/spec.md#requirement-a-multi-schema-search-orders-the-same-on-every-path
	 */
	public function __construct(
		private readonly string $quotedTerm,
		private readonly bool $isPostgres,
		private readonly bool $hasTrgm,
	) {
	}//end __construct()

	/**
	 * The score of one column.
	 *
	 * @param string $column The quoted column.
	 *
	 * @return string The score expression.
	 *
	 * @spec openspec/specs/objects-crud/spec.md#requirement-a-multi-schema-search-orders-the-same-on-every-path
	 */
	public function ofColumn(string $column): string {
		$likePattern = "'%" . trim($this->quotedTerm, "'") . "%'";
		if ($this->isPostgres === false) {
			return "CASE WHEN CAST({$column} AS CHAR) LIKE {$likePattern} THEN 1 ELSE 0 END";
		}

		if ($this->hasTrgm === true) {
			return "COALESCE(similarity({$column}::text, {$this->quotedTerm}), 0)";
		}

		return "CASE WHEN {$column}::text ILIKE {$likePattern} THEN 1 ELSE 0 END";
	}//end ofColumn()

	/**
	 * The select expression over the column scores: the greatest, or 0 without any.
	 *
	 * @param array<int, string> $scores The column scores.
	 *
	 * @return string The select expression.
	 *
	 * @spec openspec/specs/objects-crud/spec.md#requirement-a-multi-schema-search-orders-the-same-on-every-path
	 */
	public function greatest(array $scores): string {
		if ($scores === []) {
			return '0 AS _search_score';
		}

		return 'GREATEST(' . implode(', ', $scores) . ') AS _search_score';
	}//end greatest()
}//end class
