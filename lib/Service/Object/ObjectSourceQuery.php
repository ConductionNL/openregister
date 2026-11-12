<?php

/**
 * OpenRegister ObjectSourceQuery
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * Adapts a canonical search query to the object-source provider contract.
 *
 * The canonical shape (SearchQueryHandler::buildSearchQuery) carries object
 * field filters as top-level keys, paging as `_limit`/`_page`/`_offset`, and
 * sorting as `_order`. Providers read `filters`, `limit`, `offset` and `sort`.
 * Mapping is additive: a key the caller already set is never overwritten, so
 * providers written against either shape keep working.
 *
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */
final class ObjectSourceQuery {

	/**
	 * Top-level keys that are no object field filter.
	 */
	private const RESERVED_KEYS = ['limit', 'offset', 'sort', 'filters', 'extend', 'fields'];

	/**
	 * The query with the provider-contract keys added.
	 *
	 * @param array<string, mixed> $query The canonical search query.
	 *
	 * @return array<string, mixed>
	 */
	public function forProvider(array $query): array {
		if (isset($query['limit']) === false && isset($query['_limit']) === true) {
			$query['limit'] = (int) $query['_limit'];
		}

		$query = $this->withOffset(query: $query);
		if (isset($query['sort']) === false && isset($query['_order']) === true) {
			$query['sort'] = $query['_order'];
		}

		return $this->withFilters(query: $query);
	}//end forProvider()

	/**
	 * The query with `offset` added when paging asks for one.
	 *
	 * @param array<string, mixed> $query The query, its `limit` already mapped.
	 *
	 * @return array<string, mixed>
	 */
	private function withOffset(array $query): array {
		if (isset($query['offset']) === true) {
			return $query;
		}

		$offset = $this->requestedOffset(query: $query);
		if ($offset > 0) {
			$query['offset'] = $offset;
		}

		return $query;
	}//end withOffset()

	/**
	 * The offset `_offset` names, or else the one `_page` implies.
	 *
	 * @param array<string, mixed> $query The query.
	 *
	 * @return int
	 */
	private function requestedOffset(array $query): int {
		$offset = (int) ($query['_offset'] ?? 0);
		$page   = (int) ($query['_page'] ?? 0);
		$limit  = (int) ($query['limit'] ?? 0);
		if ($offset === 0 && $page > 1 && $limit > 0) {
			return (($page - 1) * $limit);
		}

		return $offset;
	}//end requestedOffset()

	/**
	 * The query with `filters` added when it holds scalar object field filters.
	 *
	 * @param array<string, mixed> $query The query.
	 *
	 * @return array<string, mixed>
	 */
	private function withFilters(array $query): array {
		if (isset($query['filters']) === true) {
			return $query;
		}

		$filters = array_filter(
			$query,
			fn ($value, $key): bool => is_scalar($value) === true && $this->isFieldKey(key: (string) $key) === true,
			ARRAY_FILTER_USE_BOTH
		);
		if ($filters !== []) {
			$query['filters'] = $filters;
		}

		return $query;
	}//end withFilters()

	/**
	 * Whether a top-level key names an object field.
	 *
	 * @param string $key The key.
	 *
	 * @return bool
	 */
	private function isFieldKey(string $key): bool {
		if ($key === '' || $key[0] === '_' || $key[0] === '@') {
			return false;
		}

		return in_array($key, self::RESERVED_KEYS, true) === false;
	}//end isFieldKey()
}//end class
