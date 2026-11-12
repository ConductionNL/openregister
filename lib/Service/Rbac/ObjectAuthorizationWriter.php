<?php

/**
 * The targeted write of an object's stored authorization block.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterSchema;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Writes `_authorization` on one row, and nothing else.
 *
 * 🔴 IT IS A TARGETED SINGLE-COLUMN UPDATE, NOT A SAVE. The object write path
 * OMITS this column on purpose, so an ordinary save carries the stored value
 * forward and a routine update cannot destroy per-object RBAC. That property
 * only holds while the column has exactly one writer, so the writer is its
 * own class: the sharing service no longer holds a query builder it could
 * reach for, and a second write path would have to be added deliberately
 * rather than by extending a method that happened to be nearby.
 *
 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
 */
class ObjectAuthorizationWriter {

	/**
	 * Constructor.
	 *
	 * @param MagicMapperInterface     $mapper Resolves the magic table a register and schema store in.
	 * @param IDBConnection   $db     The database.
	 * @param LoggerInterface $logger Where the write is noted.
	 */
	public function __construct(
		private readonly MagicMapperInterface $mapper,
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Write the authorization block for one object.
	 *
	 * A targeted single-column UPDATE, deliberately NOT a save through the
	 * object write path: that path omits the column so an ordinary save carries
	 * the stored value forward, which is what stops a routine update from
	 * destroying per-object RBAC.
	 *
	 * @param RegisterSchema          $table      The register and schema the object is stored under.
	 * @param AuthorizationAssignment $assignment The object UUID and the block to store.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
	 */
	public function writeAuthorizationBlock(RegisterSchema $table, AuthorizationAssignment $assignment): void {
		$this->writeBlockTo(table: $this->mapper->getTableNameForRegisterSchema($table->register, $table->schema), assignment: $assignment);
	}//end writeAuthorizationBlock()

	/**
	 * Write the authorization block of one object into its magic table.
	 *
	 * @param string                  $table      The magic table the object is stored in.
	 * @param AuthorizationAssignment $assignment The object and its block.
	 *
	 * @return void
	 */
	private function writeBlockTo(string $table, AuthorizationAssignment $assignment): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($table)
			->set('_authorization', $qb->createNamedParameter(json_encode($assignment->block)))
			->where($qb->expr()->eq('_uuid', $qb->createNamedParameter($assignment->objectUuid)));
		$qb->executeStatement();

		$scope = ($assignment->block[ObjectScopeResolverInterface::SCOPE_KEY] ?? null);
		$this->logger->info(
			message: '[ObjectAuthorizationWriter] Wrote the authorization block for an object',
			context: ['file' => __FILE__, 'line' => __LINE__, 'uuid' => $assignment->objectUuid, 'scope' => $scope]
		);
	}//end writeBlockTo()

}//end class
