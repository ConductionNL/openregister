<?php

/**
 * ListQuery: the paging, filters, sort and search of one listing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Limit, offset, filters, sort and search of one listing (ADR-011 rule 1).
 *
 * The defaults are the ones the object controllers apply today: a page of
 * 20, no offset, no filters, no sort and no search.
 *
 * @spec exclude ADR-011 shared parameter object, not a capability of its own
 */
final class ListQuery {

	/**
	 * The page size when the request names none.
	 *
	 * @var int
	 */
	public const DEFAULT_LIMIT = 20;

	/**
	 * The limit of a listing without a page size.
	 *
	 * PHP_INT_MAX, so a reader that forgets limitOrNull() still returns every
	 * row (setMaxResults(PHP_INT_MAX)) instead of a page of DEFAULT_LIMIT.
	 *
	 * @var int
	 */
	public const UNBOUNDED = PHP_INT_MAX;

	/**
	 * Constructor
	 *
	 * @param int                  $limit   The page size.
	 * @param int|null             $offset  The number of rows to skip, or null for none.
	 * @param array<string, mixed> $filters The filters.
	 * @param array<string, mixed> $sort    The sort: field name to direction.
	 * @param string|null          $search  The free-text search, or null for none.
	 */
	public function __construct(
		public readonly int $limit=self::DEFAULT_LIMIT,
		public readonly ?int $offset=null,
		public readonly array $filters=[],
		public readonly array $sort=[],
		public readonly ?string $search=null,
	) {
	}//end __construct()

	/**
	 * The listing of a request, read the way ObjectRequestParams::listing() reads it.
	 *
	 * The limit comes from `limit` or `_limit`; the offset from `offset` or
	 * `_offset`, else from `page` or `_page`; the sort from `order` or `_order`,
	 * an array or a JSON string; the search from `_search`. The filters are
	 * the parameters as given, as the controllers pass them on today.
	 *
	 * @param array<string, mixed> $params The request parameters, without the route keys.
	 *
	 * @return self The listing.
	 *
	 * @spec exclude ADR-011 shared parameter object, not a capability of its own
	 */
	public static function fromParams(array $params): self {
		$limit  = (int)($params['limit'] ?? $params['_limit'] ?? self::DEFAULT_LIMIT);
		$offset = self::optionalInt(value: $params['offset'] ?? $params['_offset'] ?? null);
		$page   = self::optionalInt(value: $params['page'] ?? $params['_page'] ?? null);
		if ($offset === null && $page !== null) {
			$offset = (($page - 1) * $limit);
		}

		return new self(
			limit: $limit,
			offset: $offset,
			filters: $params,
			sort: self::sortOf(order: $params['order'] ?? $params['_order'] ?? []),
			search: self::searchOf(search: $params['_search'] ?? null)
		);
	}//end fromParams()

	/**
	 * A listing without a page size: every row matching the filters.
	 *
	 * A caller that also needs an offset or a search builds
	 * `new ListQuery(limit: ListQuery::UNBOUNDED, ...)` instead.
	 *
	 * @param array<string, mixed> $filters The filters.
	 * @param array<string, mixed> $sort    The sort: field name to direction.
	 *
	 * @return self The listing.
	 *
	 * @spec exclude ADR-011 shared parameter object, not a capability of its own
	 */
	public static function unbounded(array $filters=[], array $sort=[]): self {
		return new self(limit: self::UNBOUNDED, filters: $filters, sort: $sort);
	}//end unbounded()

	/**
	 * Whether this listing has no page size.
	 *
	 * @return bool True when the limit is UNBOUNDED.
	 *
	 * @spec exclude ADR-011 shared parameter object, not a capability of its own
	 */
	public function isUnbounded(): bool {
		return $this->limit === self::UNBOUNDED;
	}//end isUnbounded()

	/**
	 * The limit to pass to setMaxResults() or to a `?int $limit` parameter.
	 *
	 * @return int|null Null when the listing is unbounded, else the limit.
	 *
	 * @spec exclude ADR-011 shared parameter object, not a capability of its own
	 */
	public function limitOrNull(): ?int {
		if ($this->isUnbounded() === true) {
			return null;
		}

		return $this->limit;
	}//end limitOrNull()

	/**
	 * The page this listing starts on, counted from 1.
	 *
	 * @return int The page; 1 without an offset or without a page size.
	 *
	 * @spec exclude ADR-011 shared parameter object, not a capability of its own
	 */
	public function page(): int {
		if ($this->offset === null || $this->limit <= 0) {
			return 1;
		}

		return ((int)floor($this->offset / $this->limit) + 1);
	}//end page()

	/**
	 * An integer request value, or null when it is absent.
	 *
	 * @param mixed $value The request value.
	 *
	 * @return int|null The integer.
	 */
	private static function optionalInt(mixed $value): ?int {
		if ($value === null) {
			return null;
		}

		return (int)$value;
	}//end optionalInt()

	/**
	 * The sort as an array; a JSON string is decoded, anything else is empty.
	 *
	 * @param mixed $order The order parameter.
	 *
	 * @return array<string, mixed> The sort.
	 */
	private static function sortOf(mixed $order): array {
		if (is_string($order) === true) {
			$order = json_decode($order, true);
		}

		if (is_array($order) === false) {
			return [];
		}

		return $order;
	}//end sortOf()

	/**
	 * The search as a string, or null when it is absent or not a scalar.
	 *
	 * @param mixed $search The search parameter.
	 *
	 * @return string|null The search.
	 */
	private static function searchOf(mixed $search): ?string {
		if (is_scalar($search) === false) {
			return null;
		}

		return (string)$search;
	}//end searchOf()
}//end class
