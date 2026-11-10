<?php

/**
 * Vector Embedding Handlers
 *
 * The four handlers the vector embeddings facade delegates to.
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
 * The generation, storage, search and statistics handlers of the vector embeddings facade.
 *
 * @spec openspec/specs/vector-embeddings/spec.md
 */
final class VectorEmbeddingHandlers {
	/**
	 * Constructor.
	 *
	 * @param EmbeddingGeneratorHandler     $generator Embedding generator handler.
	 * @param VectorStorageHandlerInterface $storage   Vector storage handler.
	 * @param VectorSearchHandler           $search    Vector search handler.
	 * @param VectorStatsHandler            $stats     Vector statistics handler.
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function __construct(
		public readonly EmbeddingGeneratorHandler $generator,
		public readonly VectorStorageHandlerInterface $storage,
		public readonly VectorSearchHandler $search,
		public readonly VectorStatsHandler $stats,
	) {
	}//end __construct()
}//end class
