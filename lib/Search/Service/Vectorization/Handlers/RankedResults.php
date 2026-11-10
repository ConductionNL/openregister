<?php

/**
 * Ranked Results
 *
 * One ranked result list and the weight it carries in rank fusion.
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
 * A ranked result list (best first) and its Reciprocal Rank Fusion weight.
 *
 * @spec openspec/specs/vector-embeddings/spec.md
 */
final readonly class RankedResults {
	/**
	 * Bundle the list and its weight.
	 *
	 * @param array<int, array<string, mixed>> $results The results, best first.
	 * @param float                            $weight  The fusion weight (0-1).
	 */
	public function __construct(
		public array $results,
		public float $weight,
	) {
	}//end __construct()
}//end class
