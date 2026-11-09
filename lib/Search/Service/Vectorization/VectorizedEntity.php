<?php

/**
 * VectorizedEntity: one entity being embedded, with its strategy and identifier.
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
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/vector-embeddings/spec.md
 */

namespace OCA\OpenRegister\Search\Service\Vectorization;

use OCA\OpenRegister\Search\Service\Vectorization\Strategies\VectorizationStrategyInterface;

/**
 * The entity whose items are embedded, the strategy that reads it, and its identifier.
 *
 * Deliberately without strict_types, like VectorizationService that builds it:
 * the entity and its identifier are whatever the strategy returns.
 *
 * @spec openspec/specs/vector-embeddings/spec.md
 */
final readonly class VectorizedEntity {
	/**
	 * Bundle the entity.
	 *
	 * @param mixed                          $entity   Entity being vectorized.
	 * @param VectorizationStrategyInterface $strategy Strategy to use.
	 * @param mixed                          $entityId The entity's identifier.
	 */
	public function __construct(
		public mixed $entity,
		public VectorizationStrategyInterface $strategy,
		public mixed $entityId,
	) {
	}//end __construct()
}//end class
