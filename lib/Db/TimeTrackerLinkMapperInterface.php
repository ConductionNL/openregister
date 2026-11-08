<?php

/**
 * TimeTrackerLinkMapperInterface: what callers outside the Db component use of TimeTrackerLinkMapper.
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
 * The methods of TimeTrackerLinkMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to TimeTrackerLinkMapper. Guarded by C04InterfaceBindingsTest.
 */
interface TimeTrackerLinkMapperInterface {

	/**
	 * Delete a time-tracker link by object UUID + upstream entry id
	 * (Tier-2 unlink path).
	 *
	 * Matches the entry id against any of the three id columns. Returns
	 * the number of rows actually deleted so callers can distinguish
	 * "no such link" (0) from "ok" (>=1).
	 *
	 * @param string $objectUuid The object UUID.
	 * @param string $entryId The upstream entry id (client/task/time uuid).
	 *
	 * @return int Number of deleted rows.
	 */
	public function deleteByObjectAndEntryId(string $objectUuid, string $entryId): int;

	/**
	 * Return every link row, optionally scoped to an object uuid.
	 *
	 * Used by the `openregister:time:reconcile` command to walk every
	 * link and re-fetch upstream NC TimeManager metadata (duration,
	 * billable, started_at, name) so the denormalised fields match the
	 * authoritative source after schema/data drift.
	 *
	 * @param string|null $objectUuid Optional object uuid scope. Null returns every row.
	 *
	 * @return TimeTrackerLink[]
	 *
	 * @spec openspec/specs/integration-time-tracker/spec.md
	 */
	public function findAll(?string $objectUuid = null): array;

	/**
	 * Find a specific time-tracker link by object UUID + entry composite.
	 *
	 * The composite (entry_type + client_id + task_id + time_id) matches
	 * the unique index. Null id components are matched with `IS NULL`.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param string $entryType The entry kind (`client`|`task`|`time`).
	 * @param string|null $clientId The client uuid (or null).
	 * @param string|null $taskId The task uuid (or null).
	 * @param string|null $timeId The time-entry uuid (or null).
	 *
	 * @return TimeTrackerLink|null The link or null if not found.
	 */
	public function findByObjectAndEntry(
		string $objectUuid,
		string $entryType,
		?string $clientId,
		?string $taskId,
		?string $timeId,
	): ?TimeTrackerLink;

	/**
	 * Find the links of an object, newest first.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return TimeTrackerLink[] The links.
	 *
	 * @spec exclude shared link-mapper plumbing, not a capability of its own
	 */
	public function findByObjectUuid(string $objectUuid): array;

	/**
	 * Insert one row.
	 *
	 * @param Entity $entity The row to insert.
	 *
	 * @return TimeTrackerLink The inserted row, with its id.
	 */
	public function insert(Entity $entity): Entity;

	/**
	 * Update one row.
	 *
	 * @param Entity $entity The row to update.
	 *
	 * @return TimeTrackerLink The updated row.
	 */
	public function update(Entity $entity): Entity;
}//end interface
