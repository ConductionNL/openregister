<?php

/**
 * CaseItemMapperInterface: what callers outside the Db component use of CaseItemMapper.
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
 * The methods of CaseItemMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to CaseItemMapper.
 */
interface CaseItemMapperInterface {

	/**
	 * Insert, stamping `uuid` and `created` when absent.
	 *
	 * @param Entity $entity The plan item to insert.
	 *
	 * @return CaseItem The inserted item, with its id.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	public function insert(Entity $entity): CaseItem;

	/**
	 * Persist a transition ONLY if the row is still in the state it was read in.
	 *
	 * Mirrors QBMapper::update() field by field (updated fields only) with the
	 * extra predicate. Two callers can both pass the in-memory legality check;
	 * exactly one of them changes the row, and the other learns it here.
	 *
	 * @param CaseItem $item The item, with its setters already applied.
	 * @param string $expectedState The state the caller read before mutating.
	 *
	 * @return boolean True when the row moved; false when somebody else moved it first.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	public function updateIfState(CaseItem $item, string $expectedState): bool;

	/**
	 * Find a plan item by its public uuid.
	 *
	 * @param string $uuid The item uuid.
	 *
	 * @return CaseItem The item.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such item exists.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
	 */
	public function findByUuid(string $uuid): CaseItem;

	/**
	 * The whole plan of one object: every row, tree order.
	 *
	 * ONE indexed read by the anchor. No run uuid is involved, so a plan on
	 * an object that never had a run reads identically.
	 *
	 * @param string $objectUuid The anchoring object's uuid.
	 *
	 * @return array<int, CaseItem> The rows, parents before children by id.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
	 */
	public function findByObject(string $objectUuid): array;

	/**
	 * How many non-terminal rows an object has: the cheap "is there a live
	 * plan here at all" question an object-event listener asks first.
	 *
	 * @param string $objectUuid The anchoring object's uuid.
	 *
	 * @return int The open row count.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	public function countOpenByObject(string $objectUuid): int;

	/**
	 * The plan items realised by a task or a run: the reverse lookup.
	 *
	 * @param string $realisationUuid The task or run uuid.
	 *
	 * @return array<int, CaseItem> The rows (normally one).
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-a-human-plan-item-is-realised-by-a-task-and-a-stage-may-be-realised-by-a-flow-run
	 */
	public function findByRealisation(string $realisationUuid): array;

	/**
	 * "Which cases are stuck where": rows of one type in one state, paged.
	 *
	 * Filtering, ordering and paging happen in the datastore over the
	 * `(plan_item_type, state)` index. The total is {@see countByTypeAndState()}
	 * over the same predicate, so page and total cannot disagree.
	 *
	 * @param string|null $type Restrict to one plan-item type, or null for all.
	 * @param string|null $state Restrict to one state, or null for all.
	 * @param int $limit Page size.
	 * @param int $offset Page offset.
	 *
	 * @return array<int, CaseItem> The page, oldest first.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	public function findByTypeAndState(?string $type, ?string $state, int $limit = 25, int $offset = 0): array;

	/**
	 * The total behind {@see findByTypeAndState()}.
	 *
	 * @param string|null $type Restrict to one plan-item type, or null for all.
	 * @param string|null $state Restrict to one state, or null for all.
	 *
	 * @return int The count, computed in the datastore.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	public function countByTypeAndState(?string $type, ?string $state): int;

	/**
	 * Delete one plan item row.
	 *
	 * @param Entity $entity The item to delete.
	 *
	 * @return Entity The deleted item.
	 */
	public function delete(Entity $entity): Entity;

	/**
	 * Remove every row of one object.
	 *
	 * Called when the object is deleted, so a re-created uuid never inherits
	 * the rows of the object it replaces.
	 *
	 * @param string $objectUuid The deleted object's uuid.
	 *
	 * @return integer How many rows were removed.
	 *
	 * @spec exclude shared mapper plumbing, not a capability of its own
	 */
	public function deleteByObject(string $objectUuid): int;
}//end interface
