<?php

/**
 * Vector Payload
 *
 * The embedding one vector row stores.
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
 * The embedding of a stored vector with the model and dimensions it came from.
 *
 * @spec openspec/specs/vector-embeddings/spec.md
 */
final class VectorPayload {
	/**
	 * Constructor.
	 *
	 * @param array<mixed> $embedding  Vector embedding (array of floats).
	 * @param string       $model      Model used to generate the embedding.
	 * @param int          $dimensions Number of dimensions.
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function __construct(
		public readonly array $embedding,
		public readonly string $model,
		public readonly int $dimensions,
	) {
	}//end __construct()
}//end class
