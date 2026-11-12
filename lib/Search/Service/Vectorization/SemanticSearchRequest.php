<?php

/**
 * SemanticSearchRequest
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
 * One semantic search: the query text, how many hits, which filters, and which embedding provider.
 *
 * @spec openspec/specs/vector-embeddings/spec.md
 */
final class SemanticSearchRequest {
	/**
	 * Describe a semantic search.
	 *
	 * @param string      $query    The query text to embed and search for.
	 * @param int         $limit    Maximum number of results.
	 * @param array       $filters  Additional filters (entity_type, etc.).
	 * @param string|null $provider The embedding provider, or null for the configured one.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function __construct(
		public readonly string $query,
		public readonly int $limit = 10,
		public readonly array $filters = [],
		public readonly ?string $provider = null,
	) {
	}//end __construct()
}//end class
