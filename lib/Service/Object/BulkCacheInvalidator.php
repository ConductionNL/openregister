<?php

/**
 * OpenRegister BulkCacheInvalidator
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use Exception;
use Psr\Log\LoggerInterface;

/**
 * Clears the collection caches after a bulk write.
 *
 * A failed invalidation is logged with its scope and never fails the write.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class BulkCacheInvalidator {

	/**
	 * Hold the collaborators.
	 *
	 * @param ObjectCacheInvalidationInterface $cacheHandler The collection cache owner.
	 * @param LoggerInterface       $logger       Receives the failures.
	 */
	public function __construct(
		private readonly ObjectCacheInvalidationInterface $cacheHandler,
		private readonly LoggerInterface $logger
	) {
	}//end __construct()

	/**
	 * Clear the collection caches of a scope after a bulk save that created or updated objects.
	 *
	 * @param array<string, mixed> $bulkResult The bulk save result with its statistics.
	 * @param SavedScopeIds        $scope      The register and schema saved under.
	 *
	 * @return void
	 */
	public function afterBulkSave(array $bulkResult, SavedScopeIds $scope): void {
		$total = (int) ($bulkResult['statistics']['objectsCreated'] ?? 0)
			+ (int) ($bulkResult['statistics']['objectsUpdated'] ?? 0);
		if ($total <= 0) {
			return;
		}

		$failure = $this->clearScope(operation: 'bulk_save', scope: $scope);
		if ($failure !== null) {
			$this->logger->warning(
				message: '[ObjectService] Bulk save cache invalidation failed',
				context: ['error' => $failure->getMessage(), 'totalAffected' => $total] + $scope->logContext()
			);
		}
	}//end afterBulkSave()

	/**
	 * Clear the collection caches of each register and schema pair of a bulk delete.
	 *
	 * CacheHandler::clearSchemaRelatedCaches() only clears the per-schema query
	 * cache when schemaId is not null, so the pairs come from the deleted rows;
	 * a broad null/null invalidation is the fallback when no pair was resolved.
	 *
	 * @param array<string, mixed> $run The bulk delete run with its deleted rows and pairs.
	 *
	 * @return void
	 */
	public function afterBulkDelete(array $run): void {
		if (empty($run['deleted']) === true) {
			return;
		}

		$pairs = $run['pairs'];
		if (empty($pairs) === true) {
			$pairs = [['registerId' => null, 'schemaId' => null]];
		}

		foreach ($pairs as $pair) {
			$this->clearBulkDeletePair(
				scope: new SavedScopeIds(registerId: $pair['registerId'], schemaId: $pair['schemaId']),
				deletedCount: count($run['deleted'])
			);
		}
	}//end afterBulkDelete()

	/**
	 * Clear the collection caches of one bulk delete pair.
	 *
	 * @param SavedScopeIds $scope        The register and schema of the deleted rows.
	 * @param int           $deletedCount The number of rows the run deleted.
	 *
	 * @return void
	 */
	private function clearBulkDeletePair(SavedScopeIds $scope, int $deletedCount): void {
		$failure = $this->clearScope(operation: 'bulk_delete', scope: $scope);
		if ($failure !== null) {
			$this->logger->warning(
				message: '[ObjectService] Bulk delete cache invalidation failed',
				context: ['error' => $failure->getMessage(), 'deletedCount' => $deletedCount] + $scope->logContext()
			);
		}
	}//end clearBulkDeletePair()

	/**
	 * Clear the collection caches of one scope.
	 *
	 * @param string        $operation The bulk operation that changed the scope.
	 * @param SavedScopeIds $scope     The register and schema changed.
	 *
	 * @return Exception|null The failure, or null when the caches were cleared.
	 */
	private function clearScope(string $operation, SavedScopeIds $scope): ?Exception {
		try {
			$this->cacheHandler->invalidateForObjectChange(
				object: null,
				operation: $operation,
				registerId: $scope->registerId,
				schemaId: $scope->schemaId
			);
		} catch (Exception $e) {
			return $e;
		}

		return null;
	}//end clearScope()
}//end class
