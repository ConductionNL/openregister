<?php

/**
 * HybridSearchRequest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service\Vectorization
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Vectorization;

/**
 * One hybrid search: the query text, the keyword hits to fuse with the vector hits, and how.
 *
 * @spec openspec/specs/vector-embeddings/spec.md
 */
final class HybridSearchRequest {
	/**
	 * Describe a hybrid search.
	 *
	 * @param string               $query          The query text; its embedding comes from the configured provider.
	 * @param array                $keywordResults The keyword search hits to fuse.
	 * @param int                  $limit          Maximum results.
	 * @param array<string, float> $weights        Weight per source: `keyword` and `vector`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function __construct(
		public readonly string $query,
		public readonly array $keywordResults = [],
		public readonly int $limit = 20,
		public readonly array $weights = ['keyword' => 0.5, 'vector' => 0.5],
	) {
	}//end __construct()
}//end class
