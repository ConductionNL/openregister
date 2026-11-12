<?php

/**
 * HandoffQueueEntryMapperInterface: what callers outside the Db component use of HandoffQueueEntryMapper.
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
 * The methods of HandoffQueueEntryMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to HandoffQueueEntryMapper. Guarded by C04InterfaceBindingsTest.
 */
interface HandoffQueueEntryMapperInterface {

	/**
	 * All parked entries, oldest first (fallback TimedJob sweep).
	 *
	 * @param int|null $limit Optional maximum number of entries.
	 *
	 * @return list<HandoffQueueEntry> Parked entries.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Scenario: No provider installed, queue mode)
	 */
	public function findParked(?int $limit = null): array;

	/**
	 * Parked entries waiting on a specific kind URI (schema-save drain).
	 *
	 * @param string $kindUri The target kind URI.
	 *
	 * @return list<HandoffQueueEntry> Parked entries for the kind, oldest first.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Scenario: No provider installed, queue mode)
	 */
	public function findParkedByKind(string $kindUri): array;

	/**
	 * Parked entries for one source object (availability `queued` state).
	 *
	 * @param string $sourceObjectUuid The source object's UUID.
	 *
	 * @return list<HandoffQueueEntry> Parked entries for the object.
	 *
	 * @spec openspec/changes/semantic-object-handoff-engine/specs/semantic-object-handoff/spec.md
	 *   (Requirement: Handoff REST surface)
	 */
	public function findParkedForObject(string $sourceObjectUuid): array;

	/**
	 * Insert one row.
	 *
	 * @param Entity $entity The row to insert.
	 *
	 * @return HandoffQueueEntry The inserted row, with its id.
	 */
	public function insert(Entity $entity): Entity;

	/**
	 * Update one row.
	 *
	 * @param Entity $entity The row to update.
	 *
	 * @return HandoffQueueEntry The updated row.
	 */
	public function update(Entity $entity): Entity;
}//end interface
