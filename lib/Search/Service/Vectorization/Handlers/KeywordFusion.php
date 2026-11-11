<?php

/**
 * Keyword Fusion
 *
 * The keyword results a hybrid search fuses with the vector results, and the fusion weights.
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
 * Pre-fetched keyword results and the keyword/vector weights Reciprocal Rank Fusion applies.
 *
 * @spec openspec/specs/vector-embeddings/spec.md
 */
final readonly class KeywordFusion {
	/**
	 * Bundle the keyword side.
	 *
	 * @param array<int, array<string, mixed>> $results The keyword results, best first.
	 * @param array<string, mixed>             $weights The requested weights ['keyword' => 0.5, 'vector' => 0.5].
	 */
	public function __construct(
		public array $results = [],
		public array $weights = ['keyword' => 0.5, 'vector' => 0.5],
	) {
	}//end __construct()
}//end class
