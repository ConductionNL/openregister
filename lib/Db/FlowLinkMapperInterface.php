<?php

/**
 * FlowLinkMapperInterface: what callers outside the Db component use of FlowLinkMapper.
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
 * The methods of FlowLinkMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to FlowLinkMapper. Guarded by C04InterfaceBindingsTest.
 */
interface FlowLinkMapperInterface {

	/**
	 * Delete a flow link by object UUID + operation id (Tier-2 unlink path).
	 *
	 * Returns the number of rows actually deleted so callers can
	 * distinguish "no such link" (0) from "ok" (>=1).
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int $operationId The operation id.
	 *
	 * @return int Number of deleted rows.
	 */
	public function deleteByObjectAndOperation(string $objectUuid, int $operationId): int;

	/**
	 * Find a specific flow link by object UUID and operation id.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param int $operationId The operation id.
	 *
	 * @return FlowLink|null The link or null if not found.
	 */
	public function findByObjectAndOperation(string $objectUuid, int $operationId): ?FlowLink;

	/**
	 * Find the links of an object, newest first.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return FlowLink[] The links.
	 *
	 * @spec exclude shared link-mapper plumbing, not a capability of its own
	 */
	public function findByObjectUuid(string $objectUuid): array;

	/**
	 * Insert one row.
	 *
	 * @param Entity $entity The row to insert.
	 *
	 * @return FlowLink The inserted row, with its id.
	 */
	public function insert(Entity $entity): Entity;
}//end interface
