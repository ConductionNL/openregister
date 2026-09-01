<?php

/**
 * Mapper for {@see TaskSequence}.
 *
 * The queries mirror the three access paths the retired step table indexed:
 * by identity (uuid), by anchor (which approval is this object in), and by
 * status (is anything still running for this template). Ordinal uniqueness
 * within a sequence is enforced by the unique index on
 * `openregister_tasks (sequence_uuid, sequence_position)`, not by this
 * mapper, because two writers provisioning the same gated write is the
 * concurrent case.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-approval-consolidation/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Persistence for task sequences.
 *
 * @template-extends QBMapper<TaskSequence>
 */
class TaskSequenceMapper extends QBMapper {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'openregister_task_sequences', TaskSequence::class);
	}//end __construct()

	/**
	 * Find a sequence by its public uuid.
	 *
	 * @param string $uuid The sequence uuid.
	 *
	 * @return TaskSequence The sequence.
	 *
	 * @throws DoesNotExistException When no such sequence exists.
	 *
	 * @spec openspec/changes/flow-approval-consolidation/specs/flow-approval-consolidation/spec.md#requirement-an-approval-is-an-ordered-task-sequence-with-one-position-enabled-at-a-time
	 */
	public function findByUuid(string $uuid): TaskSequence {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));

		return $this->findEntity($qb);
	}//end findByUuid()

	/**
	 * The RUNNING sequence for an anchor and template, or null.
	 *
	 * At most one exists by contract: the gate refuses a second provisioning
	 * while one runs, and the migration verifies the same invariant.
	 *
	 * @param string $anchorObjectUuid The object the approval is about.
	 * @param string $templateId The compiled template id.
	 *
	 * @return TaskSequence|null The running sequence, or null.
	 *
	 * @spec openspec/changes/flow-approval-consolidation/specs/approval-workflow/spec.md#req-007
	 */
	public function findRunning(string $anchorObjectUuid, string $templateId): ?TaskSequence {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('anchor_object_uuid', $qb->createNamedParameter($anchorObjectUuid)))
			->andWhere($qb->expr()->eq('template_id', $qb->createNamedParameter($templateId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(TaskSequence::STATUS_RUNNING)))
			->orderBy('id', 'DESC')
			->setMaxResults(1);

		$rows = $this->findEntities($qb);

		return ($rows[0] ?? null);
	}//end findRunning()

	/**
	 * Every sequence for an anchor and template, newest first.
	 *
	 * A rejected cycle stays readable here after a resubmission opened a new
	 * one; the two are distinguishable by their open time.
	 *
	 * @param string $anchorObjectUuid The object the approvals are about.
	 * @param string $templateId The compiled template id.
	 *
	 * @return array<int, TaskSequence> The sequences, newest first.
	 *
	 * @spec openspec/changes/flow-approval-consolidation/specs/flow-approval-consolidation/spec.md#requirement-a-rejection-terminates-the-sequence-and-every-task-it-still-owns
	 */
	public function findForAnchor(string $anchorObjectUuid, string $templateId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('anchor_object_uuid', $qb->createNamedParameter($anchorObjectUuid)))
			->andWhere($qb->expr()->eq('template_id', $qb->createNamedParameter($templateId)))
			->orderBy('opened_at', 'DESC')
			->addOrderBy('id', 'DESC');

		return $this->findEntities($qb);
	}//end findForAnchor()

	/**
	 * The most recent sequence for an anchor and template, or null.
	 *
	 * @param string $anchorObjectUuid The object the approval is about.
	 * @param string $templateId The compiled template id.
	 *
	 * @return TaskSequence|null The newest sequence, or null.
	 *
	 * @spec openspec/changes/flow-approval-consolidation/specs/approval-workflow/spec.md#req-007
	 */
	public function findNewestForAnchor(string $anchorObjectUuid, string $templateId): ?TaskSequence {
		$rows = $this->findForAnchor(anchorObjectUuid: $anchorObjectUuid, templateId: $templateId);

		return ($rows[0] ?? null);
	}//end findNewestForAnchor()
}//end class
