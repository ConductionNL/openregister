<?php

/**
 * CaseTokenMapperInterface: what callers outside the Db component use of CaseTokenMapper.
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

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;

/**
 * The methods of CaseTokenMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to CaseTokenMapper. Guarded by C04InterfaceBindingsTest.
 */
interface CaseTokenMapperInterface {

	/**
	 * Find a token by its primary id (used by the revoke path so the
	 * controller / provider can address a token unambiguously).
	 *
	 * @param int $id The token row id.
	 *
	 * @return CaseToken|null The token, or null when unknown.
	 */
	public function findById(int $id): ?CaseToken;

	/**
	 * Find every token minted against the given object uuid.
	 *
	 * @param string $objectUuid The object uuid.
	 *
	 * @return CaseToken[] Token rows.
	 */
	public function findByObjectUuid(string $objectUuid): array;

	/**
	 * Find a case token by its opaque token string.
	 *
	 * Returns null on miss so callers can fail-closed (404) without a
	 * DoesNotExistException leaking through — important for the public
	 * resolve endpoint which must not become an enumeration oracle.
	 *
	 * @param string $token The opaque token string.
	 *
	 * @return CaseToken|null The token row, or null when unknown.
	 */
	public function findByToken(string $token): ?CaseToken;

	/**
	 * Insert one row.
	 *
	 * @param Entity $entity The row to insert.
	 *
	 * @return CaseToken The inserted row, with its id.
	 */
	public function insert(Entity $entity): Entity;

	/**
	 * Update one row.
	 *
	 * @param Entity $entity The row to update.
	 *
	 * @return CaseToken The updated row.
	 */
	public function update(Entity $entity): Entity;
}//end interface
