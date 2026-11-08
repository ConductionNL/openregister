<?php

/**
 * Knn Statement
 *
 * The SQL, parameters and parameter types of one pgvector KNN query.
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
 * One index-backed KNN statement ready to execute.
 *
 * @spec openspec/specs/vector-embeddings/spec.md
 */
final readonly class KnnStatement {
	/**
	 * Bundle the statement.
	 *
	 * @param string               $sql    The SQL.
	 * @param array<string, mixed> $params The parameters.
	 * @param array<string, mixed> $types  The parameter types.
	 */
	public function __construct(
		public string $sql,
		public array $params,
		public array $types,
	) {
	}//end __construct()
}//end class
