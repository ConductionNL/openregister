<?php

/**
 * KeywordChunkSearch: one keyword search over chunk text.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
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

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * The query text, result cap and filters of one chunk keyword search, and
 * whether a platform without ranked search may answer with an unranked scan.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */
final class KeywordChunkSearch {
	/**
	 * Bundle the search inputs.
	 *
	 * @param string $query                 Search query text.
	 * @param int    $limit                 Maximum number of results.
	 * @param array  $filters               Optional filters (source_type).
	 * @param bool   $allowUnrankedFallback When true, non-PostgreSQL platforms run an unranked
	 *                                      `LIKE` scan over `text_content` instead of returning `[]`.
	 */
	public function __construct(
		public readonly string $query,
		public readonly int $limit,
		public readonly array $filters=[],
		public readonly bool $allowUnrankedFallback=false,
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
