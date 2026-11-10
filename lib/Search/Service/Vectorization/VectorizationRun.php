<?php

/**
 * VectorizationRun: how one batch of one entity type is vectorized.
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

use OCA\OpenRegister\Service\Vectorization\Strategies\VectorizationStrategyInterface;

/**
 * The strategy, processing options and entity type of one vectorization batch.
 *
 * Deliberately without strict_types, like VectorizationService that builds it.
 *
 * @spec openspec/specs/vector-embeddings/spec.md
 */
final readonly class VectorizationRun {
	/**
	 * Bundle the run.
	 *
	 * @param VectorizationStrategyInterface $strategy   Strategy to use.
	 * @param array                          $options    Processing options.
	 * @param string                         $entityType Entity type, for the log.
	 */
	public function __construct(
		public VectorizationStrategyInterface $strategy,
		public array $options,
		public string $entityType,
	) {
	}//end __construct()
}//end class
