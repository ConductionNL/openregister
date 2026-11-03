<?php

/**
 * Wraps an engine task as a virtual ObjectEntity so the declarative
 * notification dispatcher can evaluate `x-openregister-notifications` rules
 * over it, exactly as it does for registers, schemas, sources, agents and
 * webhooks through SystemEntityObjectAdapter.
 *
 * The fence (flow-task-inbox-projections, design D-1): this class contains
 * NO notification logic. It maps task columns and the derived fields onto a
 * flat payload and stops. Who, when, which channel and what text are all
 * answered by a rule in TaskNotificationRules.
 *
 * The entity uuid IS the task uuid, so NotificationDedupeState, which is
 * keyed per object, dedupes per task without any mapper change.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-task-lifecycle-delivery-is-automatic-and-declarative
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Task;

/**
 * Virtual ObjectEntity over a task row plus its derived read projection.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-task-lifecycle-delivery-is-automatic-and-declarative
 */
class TaskObjectAdapter extends ObjectEntity {

	/**
	 * Build the adapter.
	 *
	 * @param Task|null $task The task row; null only for the bare construction
	 *                        the Entity base class performs.
	 * @param array<string, mixed> $row The task's inbox row: the row carries
	 *                                  `displayTitle`, `subject` and the
	 *                                  DERIVED temporal fields, so no
	 *                                  derivation happens here.
	 * @param array<string, mixed> $extra Event-scoped payload fields a rule
	 *                                    may address (previousAssignee, the
	 *                                    write-back actor and reason).
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-task-lifecycle-delivery-is-automatic-and-declarative
	 */
	public function __construct(?Task $task = null, array $row = [], array $extra = []) {
		parent::__construct();

		$this->schema = TaskNotificationRules::SLUG;
		$this->register = null;
		if ($task === null) {
			// Entity::fromRow() constructs bare; nothing hydrates an adapter
			// from a row, so a bare adapter carries an empty payload.
			return;
		}

		$this->uuid = (string)$task->getUuid();

		$payload = self::payload(task: $task, row: $row, extra: $extra);
		$this->name = (string)$payload['title'];
		$this->object = $payload;
	}//end __construct()

	/**
	 * The flat payload a rule's recipients, filters and templates read.
	 *
	 * @param Task $task The task row.
	 * @param array<string, mixed> $row The inbox row (display title, subject, derived fields).
	 * @param array<string, mixed> $extra Event-scoped fields.
	 *
	 * @return array<string, mixed> The payload, keyed by TaskNotificationRules::payloadProperties().
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-task-lifecycle-delivery-is-automatic-and-declarative
	 */
	public static function payload(Task $task, array $row = [], array $extra = []): array {
		$title = trim((string)($row['displayTitle'] ?? ''));
		if ($title === '') {
			$title = trim((string)$task->getTitle());
		}

		if ($title === '') {
			$title = (string)$task->getUuid();
		}

		$subject = ($row['subject'] ?? null);
		$subjectTitle = null;
		if (is_array($subject) === true) {
			$subjectTitle = ($subject['title'] ?? null);
		}

		$payload = [
			'taskUuid' => (string)$task->getUuid(),
			'title' => $title,
			'description' => $task->getDescription(),
			'state' => $task->getState(),
			'isTerminal' => (bool)$task->getIsTerminal(),
			'lastAction' => $task->getLastAction(),
			'outcome' => $task->getOutcome(),
			'priority' => $task->getPriority(),
			'performerType' => $task->getPerformerType(),
			'assignee' => $task->getAssignee(),
			'previousAssignee' => null,
			'candidateUsers' => ($task->getCandidateUsers() ?? []),
			'candidateGroups' => ($task->getCandidateGroups() ?? []),
			'candidateRole' => $task->getCandidateRole(),
			'requester' => $task->getRequester(),
			'watchers' => ($task->getWatchers() ?? []),
			'startAt' => $task->getStartAt()?->format('c'),
			'dueAt' => $task->getDueAt()?->format('c'),
			'expiresAt' => $task->getExpiresAt()?->format('c'),
			'overdue' => (bool)($row['overdue'] ?? false),
			'daysUntilDue' => ($row['daysUntilDue'] ?? null),
			'daysOverdue' => ($row['daysOverdue'] ?? null),
			'objectUuid' => $task->getObjectUuid(),
			'registerId' => $task->getRegisterId(),
			'schemaId' => $task->getSchemaId(),
			'subjectTitle' => $subjectTitle,
			'appId' => $task->getAppId(),
			'runUuid' => $task->getRunUuid(),
			'completedBy' => $task->getCompletedBy(),
			'writeBackActor' => null,
			'writeBackReason' => null,
		];

		foreach ($extra as $key => $value) {
			if (is_string($key) === true && array_key_exists($key, $payload) === true) {
				$payload[$key] = $value;
			}
		}

		return $payload;
	}//end payload()
}//end class
