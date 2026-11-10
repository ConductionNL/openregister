<?php

/**
 * KeywordChunkSearch: one keyword search over chunk text.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Files\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Db;

/**
 * The query text, result cap and filters of one chunk keyword search.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */
final readonly class KeywordChunkSearch {
	/**
	 * Bundle the search inputs.
	 *
	 * @param string $query   Search query text.
	 * @param int    $limit   Maximum number of results.
	 * @param array  $filters Optional filters (source_type).
	 */
	public function __construct(
		public string $query,
		public int $limit,
		public array $filters=[],
	) {
	}//end __construct()

	/**
	 * The source type filter, when one is set.
	 *
	 * @return mixed The filter value; null when absent.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function sourceType(): mixed {
		return $this->filters['source_type'] ?? null;
	}//end sourceType()

	/**
	 * The SQL row cap: at least one.
	 *
	 * @return int The cap.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function rowCap(): int {
		return max(1, $this->limit);
	}//end rowCap()
}//end class
