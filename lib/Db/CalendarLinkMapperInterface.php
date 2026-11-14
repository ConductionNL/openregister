<?php

/**
 * CalendarLinkMapperInterface: what callers outside the Db component use of CalendarLinkMapper.
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
 * The methods of CalendarLinkMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to CalendarLinkMapper. Guarded by C04InterfaceBindingsTest.
 */
interface CalendarLinkMapperInterface {

	/**
	 * Delete a single calendar link by object UUID + event UID.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param string $eventUid The event UID.
	 *
	 * @return int Number of deleted rows (0 or 1).
	 */
	public function deleteByObjectAndEvent(string $objectUuid, string $eventUid): int;

	/**
	 * Find a single calendar link by object UUID + event UID.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param string $eventUid The event UID.
	 *
	 * @return CalendarLink The link entity.
	 *
	 * @throws DoesNotExistException When no row matches.
	 */
	public function findByObjectAndEvent(string $objectUuid, string $eventUid): CalendarLink;

	/**
	 * The link rows attached to a VEVENT, oldest first, scoped to one calendar when given.
	 *
	 * @param string      $eventUid    The VEVENT UID.
	 * @param string|null $calendarUri The calendar URI, or null for every calendar.
	 *
	 * @return CalendarLink[] The matching rows.
	 */
	public function findByEventUid(string $eventUid, ?string $calendarUri=null): array;

	/**
	 * The oldest link row for an object, event and calendar, or null when there is none.
	 *
	 * @param string $objectUuid  The object UUID.
	 * @param string $eventUid    The VEVENT UID.
	 * @param string $calendarUri The calendar URI.
	 *
	 * @return CalendarLink|null The row.
	 */
	public function findLink(string $objectUuid, string $eventUid, string $calendarUri): ?CalendarLink;

	/**
	 * Find calendar links by object UUID.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return CalendarLink[] Array of calendar links.
	 */
	public function findByObjectUuid(string $objectUuid): array;

	/**
	 * Insert one row.
	 *
	 * @param Entity $entity The row to insert.
	 *
	 * @return CalendarLink The inserted row, with its id.
	 */
	public function insert(Entity $entity): Entity;
}//end interface
