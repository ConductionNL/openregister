<?php

/**
 * OpenProjectLinkMapperInterface: what callers outside the Db component use of OpenProjectLinkMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\Entity;

/**
 * The methods of OpenProjectLinkMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to OpenProjectLinkMapper. Guarded by C04InterfaceBindingsTest.
 */
interface OpenProjectLinkMapperInterface {

	/**
	 * Delete an OpenProject link by object UUID + work-package id (Tier-2
	 * unlink path).
	 *
	 * Returns the number of rows actually deleted so callers can
	 * distinguish "no such link" (0) from "ok" (>=1).
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int $workPackageId The work-package id.
	 *
	 * @return int Number of deleted rows.
	 */
	public function deleteByObjectAndWorkPackage(string $objectUuid, int $workPackageId): int;

	/**
	 * Find a specific OpenProject link by object UUID and work-package id.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int $workPackageId The work-package id.
	 *
	 * @return OpenProjectLink|null The link or null if not found.
	 */
	public function findByObjectAndWorkPackage(string $objectUuid, int $workPackageId): ?OpenProjectLink;

	/**
	 * Find the links of an object, newest first.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return OpenProjectLink[] The links.
	 *
	 * @spec exclude shared link-mapper plumbing, not a capability of its own
	 */
	public function findByObjectUuid(string $objectUuid): array;

	/**
	 * Insert one row.
	 *
	 * @param Entity $entity The row to insert.
	 *
	 * @return OpenProjectLink The inserted row, with its id.
	 */
	public function insert(Entity $entity): Entity;

	/**
	 * Update one row.
	 *
	 * @param Entity $entity The row to update.
	 *
	 * @return OpenProjectLink The updated row.
	 */
	public function update(Entity $entity): Entity;
}//end interface
