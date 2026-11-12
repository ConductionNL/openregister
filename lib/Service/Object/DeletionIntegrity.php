<?php

/**
 * OpenRegister DeletionIntegrity
 *
 * The referential-integrity side of an object delete.
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
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */

namespace OCA\OpenRegister\Service\Object;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Dto\DeletionAnalysis;
use OCA\OpenRegister\Exception\ReferentialIntegrityException;
use OCP\IDBConnection;

/**
 * Analyses, refuses and applies the onDelete actions of a delete, inside one transaction.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class DeletionIntegrity {

	/**
	 * Constructor.
	 *
	 * @param ReferentialIntegrityService $integrity The onDelete relation graph and its actions.
	 * @param IDBConnection               $db        The connection the cascade and the root delete share.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function __construct(
		private readonly ReferentialIntegrityService $integrity,
		private readonly IDBConnection $db,
	) {
	}//end __construct()

	/**
	 * Whether any schema points at this one with an onDelete action.
	 *
	 * @param string|null $schemaId The schema of the object being deleted.
	 *
	 * @return bool True when the delete must go through the integrity path.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function governs(?string $schemaId): bool {
		return $schemaId !== null && $this->integrity->hasIncomingOnDeleteReferences($schemaId) === true;
	}//end governs()

	/**
	 * What deleting the object would block, cascade, nullify or reset.
	 *
	 * @param ObjectEntity $object The object to analyse.
	 *
	 * @return DeletionAnalysis The analysis.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function analyse(ObjectEntity $object): DeletionAnalysis {
		return $this->integrity->canDelete($object);
	}//end analyse()

	/**
	 * Record a RESTRICT block and refuse the delete.
	 *
	 * @param RefusedDeletion  $refused  The object, its schema and who asked.
	 * @param DeletionAnalysis $analysis The analysis that blocked it.
	 *
	 * @return never
	 *
	 * @throws ReferentialIntegrityException Always.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function refuse(RefusedDeletion $refused, DeletionAnalysis $analysis): never {
		$this->integrity->logRestrictBlock(refused: $refused, analysis: $analysis);

		throw new ReferentialIntegrityException(analysis: $analysis);
	}//end refuse()

	/**
	 * Apply the cascade, set-null and set-default actions of an analysis.
	 *
	 * @param DeletionAnalysis    $analysis The analysis to act on.
	 * @param CascadeDeleteOrigin $origin   Who deletes, from which root and organisation.
	 *
	 * @return array{retained: array<int, array<string, mixed>>} The cascade targets kept live.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function apply(DeletionAnalysis $analysis, CascadeDeleteOrigin $origin): array {
		return $this->integrity->applyDeletionActions($analysis, $origin);
	}//end apply()

	/**
	 * Run the work in one transaction: committed when it returns, rolled back when it throws.
	 *
	 * @param callable(): bool $work The cascade and the root delete.
	 *
	 * @return bool What the work returned.
	 *
	 * @throws Exception The work's exception, after the rollback.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function atomically(callable $work): bool {
		$this->db->beginTransaction();
		try {
			$result = $work();
			$this->db->commit();
			return $result;
		} catch (Exception $e) {
			$this->db->rollBack();
			throw $e;
		}
	}//end atomically()
}//end class
