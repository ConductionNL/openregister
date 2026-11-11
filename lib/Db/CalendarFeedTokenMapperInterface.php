<?php

/**
 * CalendarFeedTokenMapperInterface: what callers outside the Db component use of CalendarFeedTokenMapper.
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
 * The methods of CalendarFeedTokenMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to CalendarFeedTokenMapper. Guarded by C04InterfaceBindingsTest.
 */
interface CalendarFeedTokenMapperInterface {

	/**
	 * Find a feed token by its row id.
	 *
	 * @param int $id The token row id.
	 *
	 * @return CalendarFeedToken|null The token, or null when unknown.
	 *
	 * @spec openspec/specs/calendar-provider/spec.md
	 */
	public function findById(int $id): ?CalendarFeedToken;

	/**
	 * Find a feed token by its opaque token string.
	 *
	 * Returns null on a miss so the public feed endpoint can fail closed
	 * without a DoesNotExistException distinguishing "unknown" from
	 * "revoked", which would make the endpoint an enumeration oracle.
	 *
	 * @param string $token The opaque token string.
	 *
	 * @return CalendarFeedToken|null The token row, or null when unknown.
	 *
	 * @spec openspec/specs/calendar-provider/spec.md
	 */
	public function findByToken(string $token): ?CalendarFeedToken;

	/**
	 * Every token minted for one principal, newest first.
	 *
	 * @param string $userId The principal.
	 *
	 * @return array<int, CalendarFeedToken> The token rows.
	 *
	 * @spec openspec/specs/calendar-provider/spec.md
	 */
	public function findByUser(string $userId): array;

	/**
	 * Insert one row.
	 *
	 * @param Entity $entity The row to insert.
	 *
	 * @return CalendarFeedToken The inserted row, with its id.
	 */
	public function insert(Entity $entity): Entity;

	/**
	 * Update one row.
	 *
	 * @param Entity $entity The row to update.
	 *
	 * @return CalendarFeedToken The updated row.
	 */
	public function update(Entity $entity): Entity;
}//end interface
