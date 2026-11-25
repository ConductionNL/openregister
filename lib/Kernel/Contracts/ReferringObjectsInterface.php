<?php
/**
 * ReferringObjectsInterface: the objects of one schema that refer to given uuids.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

use OCA\OpenRegister\Contract\ObjectEntityInterface;

/**
 * The objects of one register and schema whose relations hold any of some uuids.
 *
 * Core reads them from the schema's table without RBAC; Notifications uses them
 * to reach the people on the objects that refer to the triggering one.
 *
 * @spec openspec/changes/notifications-new-notes-and-referrers/specs/notificatie-engine/spec.md#scenario-organisations-using-an-application-hear-about-a-new-version
 */
interface ReferringObjectsInterface {

	/**
	 * The objects in the schema that refer to any of the uuids.
	 *
	 * @param array<int, string> $uuids      The referred uuids.
	 * @param int                $schemaId   The schema the referring objects belong to.
	 * @param int                $registerId The register the referring objects belong to.
	 * @param string             $fieldName  The referring property.
	 *
	 * @return list<ObjectEntityInterface>
	 *
	 * @throws \Throwable When the objects cannot be read.
	 *
	 * @spec openspec/changes/notifications-new-notes-and-referrers/specs/notificatie-engine/spec.md#scenario-organisations-using-an-application-hear-about-a-new-version
	 */
	public function referringTo(array $uuids, int $schemaId, int $registerId, string $fieldName): array;
}//end interface
