<?php
/**
 * ObjectArchivalMetadataInterface: the archival metadata an object write stamps and checks.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;

/**
 * Stamps and checks an object's archival (TMLO) metadata on the save path.
 *
 * The object write (Core) asks; the archival metadata service (Compliance)
 * answers and is bound to this contract in the Compliance registrar. One
 * capability: what a create stamps and what a create or update may carry.
 *
 * @spec openspec/specs/tmlo-auto-populate/spec.md#requirement-auto-populate-tmlo-metadata-on-object-creation
 */
interface ObjectArchivalMetadataInterface {

	/**
	 * Whether the register keeps archival metadata on its objects.
	 *
	 * @param Register $register The register the object is written to.
	 *
	 * @return bool True when the register switched archival metadata on.
	 *
	 * @spec openspec/specs/tmlo-register-toggle/spec.md#requirement-register-level-tmlo-toggle
	 */
	public function isTmloEnabled(Register $register): bool;

	/**
	 * Stamp the system-managed archival defaults on an object being created.
	 *
	 * @param ObjectEntity   $object The object being created.
	 * @param RegisterSchema $table  Its register (the switch) and schema (the default values).
	 *
	 * @return ObjectEntity The object carrying its archival defaults.
	 *
	 * @spec openspec/specs/tmlo-auto-populate/spec.md#requirement-auto-populate-tmlo-metadata-on-object-creation
	 */
	public function populateDefaults(ObjectEntity $object, RegisterSchema $table): ObjectEntity;

	/**
	 * The faults in archival field values; an empty list means they may be stored.
	 *
	 * @param array $tmlo The archival metadata a write carries.
	 *
	 * @return array The faults, one message each.
	 *
	 * @spec openspec/specs/tmlo-validation/spec.md#requirement-tmlo-field-value-validation
	 */
	public function validateFieldValues(array $tmlo): array;

	/**
	 * The faults in an archival status change; an empty list means it is allowed.
	 *
	 * @param array  $tmlo      The merged archival metadata, carrying the new status.
	 * @param string $oldStatus The status the object has now.
	 *
	 * @return array The faults, one message each.
	 *
	 * @spec openspec/specs/tmlo-validation/spec.md#requirement-tmlo-status-transition-validation
	 */
	public function validateStatusTransition(array $tmlo, string $oldStatus): array;
}//end interface
