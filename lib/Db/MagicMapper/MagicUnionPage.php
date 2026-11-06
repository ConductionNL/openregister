<?php

/**
 * MagicUnionPage: the page of a cross-schema search, in SQL and in PHP.
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

use OCA\OpenRegister\Support\QueryLimit;

/**
 * Cut the page a cross-schema UNION search asks for.
 *
 * @spec openspec/specs/objects-crud/spec.md#requirement-a-multi-schema-search-orders-the-same-on-every-path
 */
final class MagicUnionPage {
	/**
	 * Constructor.
	 *
	 * @param array $query   Search parameters.
	 * @param int   $maxSize The largest numeric page size.
	 *
	 * @spec openspec/specs/objects-crud/spec.md#requirement-a-multi-schema-search-orders-the-same-on-every-path
	 */
	public function __construct(private readonly array $query, private readonly int $maxSize) {
	}//end __construct()

	/**
	 * The LIMIT/OFFSET clause of a union.
	 *
	 * Both values are cast and clamped here, so raw user input cannot reach
	 * the interpolated SQL string (SQL injection via _limit / _offset).
	 *
	 * Unlimited omits the clause rather than interpolating a large number:
	 * this SQL is concatenated, not bound, and a sentinel like PHP_INT_MAX
	 * is a number some databases reject. MySQL and MariaDB reject a bare
	 * OFFSET without a LIMIT, so an offset with no limit uses the largest
	 * value the syntax accepts, the documented idiom for "everything from
	 * here on".
	 *
	 * @return string The clause with a leading space, or an empty string.
	 *
	 * @spec openspec/specs/objects-crud/spec.md#requirement-a-multi-schema-search-orders-the-same-on-every-path
	 */
	public function limitClause(): string {
		$limit = $this->size();
		$offset = $this->offset();
		if ($limit !== null) {
			return " LIMIT {$limit} OFFSET {$offset}";
		}

		if ($offset > 0) {
			return ' LIMIT 18446744073709551615 OFFSET ' . $offset;
		}

		return '';
	}//end limitClause()

	/**
	 * The per-batch query: page one, wide enough to cover the caller's page.
	 *
	 * Every batch answers the same question over its own arms, so each has to
	 * reach as far as `offset + limit` for the merged set to be able to serve
	 * the requested page. A batch asked for only `limit` rows could not
	 * contribute the last row of a later page even when it owns it.
	 *
	 * An unlimited query stays unlimited: there is nothing to over-fetch to.
	 *
	 * @return array The query to run per batch.
	 *
	 * @spec openspec/specs/objects-crud/spec.md#requirement-a-multi-schema-search-orders-the-same-on-every-path
	 */
	public function batchQuery(): array {
		$batchQuery = $this->query;
		$batchQuery['_offset'] = 0;

		$limit = $this->limit();
		if ($limit !== null) {
			$batchQuery['_limit'] = ($this->offset() + $limit);
		}

		return $batchQuery;
	}//end batchQuery()

	/**
	 * The caller's page of merged rows.
	 *
	 * @param array $rows The merged rows, in order.
	 *
	 * @return array The page.
	 *
	 * @spec openspec/specs/objects-crud/spec.md#requirement-a-multi-schema-search-orders-the-same-on-every-path
	 */
	public function cut(array $rows): array {
		return array_slice($rows, $this->offset(), $this->size());
	}//end cut()

	/**
	 * The offset, never negative.
	 *
	 * @return int
	 */
	private function offset(): int {
		return max(0, (int)($this->query['_offset'] ?? 0));
	}//end offset()

	/**
	 * The limit asked for, or null for unlimited.
	 *
	 * @return int|null
	 */
	private function limit(): ?int {
		return QueryLimit::normalise($this->query['_limit'] ?? null);
	}//end limit()

	/**
	 * The page size: a numeric limit is clamped, `_limit=false` is the only way past the bound.
	 *
	 * @return int|null
	 */
	private function size(): ?int {
		$limit = $this->limit();
		if ($limit === null) {
			return null;
		}

		return min($limit, $this->maxSize);
	}//end size()
}//end class
