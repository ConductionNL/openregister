<?php

/**
 * Persistence for plan items.
 *
 * Two properties here are load-bearing for the spec rather than
 * conveniences:
 *
 * - `updateIfState()` is a CONDITIONAL UPDATE (move IF still in the state
 *   the caller read), so two overlapping transitions on ONE row produce one
 *   winner and one conflict, while two transitions on DIFFERENT rows of the
 *   same case never touch each other. Row-level storage is what makes the
 *   second half true; the reference implementation's single JSON blob made
 *   it a lost-update race by construction (design D-2).
 * - Every "stuck where" read filters, sorts, paginates and counts in the
 *   datastore over the `(plan_item_type, state)` index; no stored document
 *   is decoded to answer it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @template-extends QBMapper<CaseItem>
 *
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use InvalidArgumentException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;

/**
 * Reads and writes plan items.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) A mapper's public methods
 * are its query vocabulary, one per distinct question the case layer asks of
 * the table (same reasoning as TaskMapper and FlowRunMapper).
 *
 * @template-extends QBMapper<CaseItem>
 *
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
 */
class CaseItemMapper extends QBMapper {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_case_items', entityClass: CaseItem::class);

	}//end __construct()

	/**
	 * Insert, stamping `uuid` and `created` when absent.
	 *
	 * @param Entity $entity The plan item to insert.
	 *
	 * @return CaseItem The inserted item, with its id.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	public function insert(Entity $entity): CaseItem {
		if ($entity instanceof CaseItem === false) {
			throw new InvalidArgumentException('CaseItemMapper persists CaseItem entities only.');
		}

		if (trim((string)$entity->getUuid()) === '') {
			$entity->setUuid(Uuid::v4()->toRfc4122());
		}

		if ($entity->getCreated() === null) {
			$entity->setCreated(new DateTime());
		}

		return parent::insert(entity: $entity);
	}//end insert()

	/**
	 * Update, stamping `updated`.
	 *
	 * @param Entity $entity The plan item to update.
	 *
	 * @return CaseItem The updated item.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	public function update(Entity $entity): CaseItem {
		if ($entity instanceof CaseItem === false) {
			throw new InvalidArgumentException('CaseItemMapper persists CaseItem entities only.');
		}

		$entity->setUpdated(new DateTime());

		return parent::update(entity: $entity);
	}//end update()

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
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	public function updateIfState(CaseItem $item, string $expectedState): bool {
		$id = $item->getId();
		if ($id === null) {
			throw new InvalidArgumentException('A plan item must be persisted before it can be transitioned.');
		}

		$item->setUpdated(new DateTime());
		$properties = $item->getUpdatedFields();
		unset($properties['id']);

		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName());
		foreach (array_keys($properties) as $property) {
			$getter = 'get' . ucfirst($property);
			$qb->set(
				$item->propertyToColumn(property: $property),
				$qb->createNamedParameter($item->$getter(), $this->getParameterTypeForProperty(entity: $item, property: $property))
			);
		}

		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter($expectedState)));

		return $qb->executeStatement() === 1;
	}//end updateIfState()

	/**
	 * Find a plan item by its public uuid.
	 *
	 * @param string $uuid The item uuid.
	 *
	 * @return CaseItem The item.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such item exists.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
	 */
	public function findByUuid(string $uuid): CaseItem {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));

		return $this->findEntity(query: $qb);
	}//end findByUuid()

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
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
	 */
	public function findByObject(string $objectUuid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->orderBy('position', 'ASC')
			->addOrderBy('id', 'ASC');

		return $this->findEntities(query: $qb);
	}//end findByObject()

	/**
	 * How many non-terminal rows an object has: the cheap "is there a live
	 * plan here at all" question an object-event listener asks first.
	 *
	 * @param string $objectUuid The anchoring object's uuid.
	 *
	 * @return int The open row count.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	public function countOpenByObject(string $objectUuid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'total'))
			->from($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->andWhere($qb->expr()->eq('is_terminal', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)));

		$result = $qb->executeQuery();
		$total = (int)$result->fetchOne();
		$result->closeCursor();

		return $total;
	}//end countOpenByObject()

	/**
	 * The plan items realised by a task or a run: the reverse lookup.
	 *
	 * @param string $realisationUuid The task or run uuid.
	 *
	 * @return array<int, CaseItem> The rows (normally one).
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-human-plan-item-is-realised-by-a-task-and-a-stage-may-be-realised-by-a-flow-run
	 */
	public function findByRealisation(string $realisationUuid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('realisation_uuid', $qb->createNamedParameter($realisationUuid)))
			->orderBy('id', 'ASC');

		return $this->findEntities(query: $qb);
	}//end findByRealisation()

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
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	public function findByTypeAndState(?string $type, ?string $state, int $limit = 25, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName());
		$this->applyTypeAndState(qb: $qb, type: $type, state: $state);
		$qb->orderBy('id', 'ASC')
			->setMaxResults(max(1, $limit))
			->setFirstResult(max(0, $offset));

		return $this->findEntities(query: $qb);
	}//end findByTypeAndState()

	/**
	 * The total behind {@see findByTypeAndState()}.
	 *
	 * @param string|null $type Restrict to one plan-item type, or null for all.
	 * @param string|null $state Restrict to one state, or null for all.
	 *
	 * @return int The count, computed in the datastore.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	public function countByTypeAndState(?string $type, ?string $state): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'total'))
			->from($this->getTableName());
		$this->applyTypeAndState(qb: $qb, type: $type, state: $state);

		$result = $qb->executeQuery();
		$total = (int)$result->fetchOne();
		$result->closeCursor();

		return $total;
	}//end countByTypeAndState()

	/**
	 * Delete every plan item of one object. The audit is NOT touched: it is
	 * append-only and outlives the rows it describes.
	 *
	 * @param string $objectUuid The anchoring object's uuid.
	 *
	 * @return int How many rows were deleted.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-business-state-is-written-through-to-the-register-never-owned-by-the-engine
	 */
	public function deleteByObject(string $objectUuid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)));

		return $qb->executeStatement();
	}//end deleteByObject()

	/**
	 * The shared predicate of the stuck-where page and its total.
	 *
	 * @param IQueryBuilder $qb The query under construction.
	 * @param string|null $type The type filter, or null.
	 * @param string|null $state The state filter, or null.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	private function applyTypeAndState(IQueryBuilder $qb, ?string $type, ?string $state): void {
		if ($type !== null && trim($type) !== '') {
			$qb->andWhere($qb->expr()->eq('plan_item_type', $qb->createNamedParameter($type)));
		}

		if ($state !== null && trim($state) !== '') {
			$qb->andWhere($qb->expr()->eq('state', $qb->createNamedParameter($state)));
		}
	}//end applyTypeAndState()
}//end class
