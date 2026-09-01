<?php

/**
 * The append-only plan-item audit.
 *
 * `insert()` and reads only. `update()` and `delete()` exist because the
 * QBMapper base class declares them, and both throw: there is no code path
 * by which an audit entry changes or disappears, and deleting a plan item
 * does not cascade here.
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
 * @template-extends QBMapper<CaseItemAudit>
 *
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use InvalidArgumentException;
use LogicException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Appends and reads plan-item audit entries.
 *
 * @template-extends QBMapper<CaseItemAudit>
 *
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
 */
class CaseItemAuditMapper extends QBMapper {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_case_item_audit', entityClass: CaseItemAudit::class);

	}//end __construct()

	/**
	 * Append an entry, stamping `created`.
	 *
	 * @param Entity $entity The entry to append.
	 *
	 * @return CaseItemAudit The appended entry.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	public function insert(Entity $entity): CaseItemAudit {
		if ($entity instanceof CaseItemAudit === false) {
			throw new InvalidArgumentException('CaseItemAuditMapper appends CaseItemAudit entries only.');
		}

		if ($entity->getCreated() === null) {
			$entity->setCreated(new DateTime());
		}

		return parent::insert(entity: $entity);
	}//end insert()

	/**
	 * Refused: the audit is append-only.
	 *
	 * @param Entity $entity Ignored.
	 *
	 * @return CaseItemAudit Never returns.
	 *
	 * @throws LogicException Always.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The parameter is the
	 * inherited signature; refusing it unread is the whole method.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	public function update(Entity $entity): CaseItemAudit {
		throw new LogicException('The plan-item audit is append-only: entries are never updated.');
	}//end update()

	/**
	 * Refused: the audit is append-only.
	 *
	 * @param Entity $entity Ignored.
	 *
	 * @return CaseItemAudit Never returns.
	 *
	 * @throws LogicException Always.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The parameter is the
	 * inherited signature; refusing it unread is the whole method.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	public function delete(Entity $entity): CaseItemAudit {
		throw new LogicException('The plan-item audit is append-only: entries are never deleted.');
	}//end delete()

	/**
	 * The audit trail of one plan item, oldest first.
	 *
	 * @param int $caseItemId The item's row id.
	 *
	 * @return array<int, CaseItemAudit> The entries.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	public function findForItem(int $caseItemId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('case_item_id', $qb->createNamedParameter($caseItemId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');

		return $this->findEntities(query: $qb);
	}//end findForItem()

	/**
	 * The audit trail of a whole plan, oldest first.
	 *
	 * @param array<int, int> $caseItemIds The plan's row ids.
	 *
	 * @return array<int, CaseItemAudit> The entries.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-plan-item-state-is-stored-as-rows-never-as-an-encoded-blob
	 */
	public function findForItems(array $caseItemIds): array {
		if ($caseItemIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('case_item_id', $qb->createNamedParameter(array_values($caseItemIds), IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('id', 'ASC');

		return $this->findEntities(query: $qb);
	}//end findForItems()
}//end class
