<?php

/**
 * Object owner writer — the one targeted write of the `_owner` column.
 *
 * A handover changes who owns a record and nothing else. It is written as a
 * single-column UPDATE, deliberately NOT as a save through the object write
 * path, for two reasons that both matter:
 *
 *  1. The write path DERIVES the owner from the acting user, which is the
 *     security property that stops a caller claiming ownership
 *     ({@see \OCA\OpenRegister\Service\Object\SaveObject}). A handover asks for
 *     a different owner on purpose, so it cannot go through the path whose job
 *     is to refuse exactly that.
 *  2. A save revalidates and rewrites the whole payload, bumps the version and
 *     fills absent properties with null. A handover that quietly rewrote the
 *     record's data would be a data migration wearing an ownership label.
 *
 * This is the same reasoning, and the same shape, as
 * {@see ObjectAuthorizationWriter} — which is why they sit beside each other.
 * The audit row is written by the CALLER, never here, so there is one place that
 * decides what a handover is and one place that records it.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/object-ownership-and-handover/specs/object-ownership/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Writes the `_owner` column for one object.
 */
class ObjectOwnerWriter {

	/**
	 * Constructor.
	 *
	 * @param MagicMapper     $mapper Resolves the magic table a register and schema store in.
	 * @param IDBConnection   $db     The database.
	 * @param LoggerInterface $logger Where the write is noted.
	 */
	public function __construct(
		private readonly MagicMapper $mapper,
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Write the owner of one object.
	 *
	 * @param Register $register The register.
	 * @param Schema $schema The schema.
	 * @param string $objectUuid The object UUID.
	 * @param string $owner The new owner's uid.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-ownership-and-handover/specs/object-ownership/spec.md
	 */
	public function writeOwner(
		Register $register,
		Schema $schema,
		string $objectUuid,
		string $owner,
	): void {
		$table = $this->mapper->getTableNameForRegisterSchema($register, $schema);

		$qb = $this->db->getQueryBuilder();
		$qb->update($table)
			->set('_owner', $qb->createNamedParameter($owner))
			->where($qb->expr()->eq('_uuid', $qb->createNamedParameter($objectUuid)));
		$qb->executeStatement();

		$this->logger->info(
			message: '[ObjectOwnerWriter] Wrote the owner of an object',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'uuid' => $objectUuid,
				'owner' => $owner,
			]
		);
	}//end writeOwner()

}//end class
