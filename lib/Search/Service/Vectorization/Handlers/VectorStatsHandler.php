<?php

/**
 * Vector Statistics Handler
 *
 * Handles gathering statistics about stored vectors from the database.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vectorization\Handlers
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Vectorization\Handlers;

use Exception;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * VectorStatsHandler
 *
 * Responsible for gathering statistics about stored vectors from the PostgreSQL database.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vectorization\Handlers
 */
class VectorStatsHandler {

	/**
	 * The statistics reported when the database cannot be read.
	 *
	 * @var array{total_vectors: int, by_type: array<never>, by_model: array<never>, object_vectors: int, file_vectors: int}
	 */
	private const EMPTY_STATS = [
		'total_vectors' => 0,
		'by_type' => [],
		'by_model' => [],
		'object_vectors' => 0,
		'file_vectors' => 0,
	];
	/**
	 * Constructor
	 *
	 * @param IDBConnection $db Database connection
	 * @param LoggerInterface $logger PSR-3 logger
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get vector statistics from the PostgreSQL database
	 *
	 * @return ((int|mixed)[]|int|string)[] Statistics about stored vectors
	 *
	 * @psalm-return array{total_vectors: int, by_type: array<int>,
	 *     by_model: array<int|mixed>, object_vectors: int, file_vectors: int}
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function getStats(): array {
		try {
			return $this->getStatsFromDatabase();
		} catch (Exception $e) {
			$this->logger->error(
				message: '[VectorStatsHandler] Failed to get vector stats',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return self::EMPTY_STATS;
		}//end try
	}//end getStats()

	/**
	 * Get vector statistics from database
	 *
	 * @return (int[])[] Statistics from database
	 *
	 * @psalm-return array{total_vectors: int, by_type: array<int>,
	 *     by_model: array<int>, object_vectors: int, file_vectors: int}
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	private function getStatsFromDatabase(): array {
		$qb = $this->db->getQueryBuilder();

		// Total vectors.
		$qb->select($qb->func()->count('id', 'total'))
			->from('openregister_vectors');
		$total = (int)$qb->executeQuery()->fetchOne();

		// By entity type.
		$byType = $this->countsBy(column: 'entity_type');

		// By model.
		$byModel = $this->countsBy(column: 'embedding_model');

		return [
			'total_vectors' => $total,
			'by_type' => $byType,
			'by_model' => $byModel,
			'object_vectors' => $byType['object'] ?? 0,
			'file_vectors' => $byType['file'] ?? 0,
		];
	}//end getStatsFromDatabase()

	/**
	 * Count the stored vectors per value of one column.
	 *
	 * @param string $column The column to group on (entity_type or embedding_model).
	 *
	 * @return array<int|string, int> The count per column value.
	 */
	private function countsBy(string $column): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select($column, $qb->func()->count('id', 'count'))
			->from('openregister_vectors')
			->groupBy($column);
		$result = $qb->executeQuery();
		$counts = [];
		while (($row = $result->fetch()) !== false) {
			$counts[$row[$column]] = (int)$row['count'];
		}

		$result->closeCursor();

		return $counts;
	}//end countsBy()
}//end class
