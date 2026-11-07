<?php

/**
 * OrphanDeletionGuard
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Archival\RecordState;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCA\OpenRegister\Service\SharedMasterDataService;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Whether orphan cleanup may soft-delete a row: the same refusals a direct delete
 * gets, asked for the signed-in caller, in every save. Anything it cannot answer
 * counts as a refusal, so the row stays and only loses the reference.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/specs/referential-integrity/spec.md
 */
class OrphanDeletionGuard {
	/**
	 * Constructor.
	 *
	 * @param MagicMapperInterface             $objectMapper      Organisation boundary and delete guards.
	 * @param PermissionHandlerInterface       $permissionHandler The caller's delete right.
	 * @param IUserSession            $userSession       The caller.
	 * @param ContainerInterface|null $container         Resolves the shared-data and integrity services lazily.
	 *
	 * @spec openspec/specs/referential-integrity/spec.md
	 */
	public function __construct(
		private readonly MagicMapperInterface $objectMapper,
		private readonly PermissionHandlerInterface $permissionHandler,
		private readonly IUserSession $userSession,
		private readonly ?ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Whether the signed-in caller may soft-delete this row.
	 *
	 * @param ObjectEntity $row      The row.
	 * @param Register     $register The row's register.
	 * @param Schema       $schema   The row's schema.
	 *
	 * @return bool False on any refusal or failure.
	 *
	 * @spec openspec/specs/referential-integrity/spec.md
	 */
	public function allows(ObjectEntity $row, Register $register, Schema $schema): bool {
		if ($this->userSession->getUser() === null || $this->isProtected(row: $row, schema: $schema) === true) {
			return false;
		}

		try {
			return $this->objectMapper->callerOrganisationsHold(row: $row) === true
				&& $this->mayWriteSharedData(register: $register, schema: $schema) === true
				&& $this->permissionHandler->hasPermission(schema: $schema, action: 'delete', objectOwner: $row->getOwner(), object: $row) === true
				&& $this->passesDeleteChecks(row: $row) === true;
		} catch (Throwable $e) {
			return false;
		}
	}//end allows()

	/**
	 * Whether the row's schema or state refuses every delete.
	 *
	 * @param ObjectEntity $row    The row.
	 * @param Schema       $schema The row's schema.
	 *
	 * @return bool True for an append-only, source-backed or archival schema, or a transferred row.
	 */
	private function isProtected(ObjectEntity $row, Schema $schema): bool {
		return $schema->isAppendOnly() === true
			|| $schema->getObjectSource() !== null
			|| $schema->hasArchivalAnnotation() === true
			|| in_array(($row->getRetention()['archiefstatus'] ?? ''), RecordState::TRANSFERRED_ALIASES, true) === true;
	}//end isProtected()

	/**
	 * Whether the caller's organisations hold the register and schema, not merely consume them.
	 *
	 * The organisation boundary answers a read question and lets a consumer of shared
	 * master data through, so the write question is asked here.
	 *
	 * @param Register $register The row's register.
	 * @param Schema   $schema   The row's schema.
	 *
	 * @return bool
	 *
	 * @throws Throwable When the caller only consumes either, or a service is missing.
	 */
	private function mayWriteSharedData(Register $register, Schema $schema): bool {
		$shared = $this->container->get(SharedMasterDataService::class);
		$organisations = $this->container->get(OrganisationServiceInterface::class)->getUserActiveOrganisations();
		$shared->assertWritable(table: SharedMasterDataService::REGISTERS, id: $register->getId(), activeOrgUuids: $organisations);
		$shared->assertWritable(table: SharedMasterDataService::SCHEMAS, id: $schema->getId(), activeOrgUuids: $organisations);

		return true;
	}//end mayWriteSharedData()

	/**
	 * Whether referential integrity and the delete listeners let the row go.
	 *
	 * A row other rows depend on is left alone: the cleanup runs none of the
	 * cascade, nullify or default actions a direct delete would.
	 *
	 * @param ObjectEntity $row The row.
	 *
	 * @return bool
	 *
	 * @throws Throwable When a listener stops the delete, or a service is missing.
	 */
	private function passesDeleteChecks(ObjectEntity $row): bool {
		$analysis = $this->container->get(DeleteObject::class)->canDelete($row);
		$dependants = array_merge($analysis->cascadeTargets, $analysis->nullifyTargets, $analysis->defaultTargets);
		if ($analysis->deletable === false || $dependants !== []) {
			return false;
		}

		$this->objectMapper->assertDeletable(entity: $row);

		return true;
	}//end passesDeleteChecks()
}//end class
