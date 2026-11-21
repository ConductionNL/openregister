<?php

/**
 * Vector Query
 *
 * The query embedding, the result limit and the entity filters of one vector search.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service\Vectorization\Handlers
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Vectorization\Handlers;

/**
 * One vector similarity query: what to compare against, how many results, and which entities.
 *
 * @spec openspec/specs/vector-embeddings/spec.md
 */
final readonly class VectorQuery {
	/**
	 * Bundle the query.
	 *
	 * @param array<int, float>    $embedding The query embedding vector.
	 * @param int                  $limit     The maximum number of results.
	 * @param array<string, mixed> $filters   Entity filters (entity_type, entity_id, max_vectors).
	 */
	public function __construct(
		public array $embedding,
		public int $limit = 10,
		public array $filters = [],
	) {
	}//end __construct()
}//end class
