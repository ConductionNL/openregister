<?php

/**
 * The search term and page of one directory listing (users, groups).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-3.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

/**
 * Reads `filters.search` / `_search` / `search`, `limit` (200) and `offset` (0) from a source query.
 *
 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-3.1
 */
final class DirectoryPage {

	/**
	 * The search term, '' when none.
	 *
	 * @var string
	 */
	public readonly string $search;

	/**
	 * The page size.
	 *
	 * @var integer
	 */
	public readonly int $limit;

	/**
	 * The page offset.
	 *
	 * @var integer
	 */
	public readonly int $offset;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $query Query (filters/search/limit/offset).
	 */
	public function __construct(array $query) {
		$this->search = (string)($query['filters']['search'] ?? $query['_search'] ?? $query['search'] ?? '');
		$this->limit  = (int)($query['limit'] ?? 200);
		$this->offset = (int)($query['offset'] ?? 0);
	}//end __construct()
}//end class
