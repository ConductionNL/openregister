<?php

/**
 * Builds a validated, unsaved task from boundary data.
 *
 * Extracted from TaskService so the service stays about LIFECYCLE and the
 * builder about INTAKE: every published vocabulary is applied here, once —
 * a legacy status through {@see TaskState} (unmapped refused by name),
 * priority through {@see TaskPriority} (off-scale refused by name),
 * `expires_at` earlier than `due_at` refused naming both, the checklist
 * required to be a typed array (a string containing JSON is the procest
 * shape this entity removes), and the template FROZEN: id, version and
 * snapshot land together at creation.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-one-lifecycle-with-every-legacy-value-mapped-onto-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use DateTime;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskRelation;
use OCA\OpenRegister\Exception\TaskValidationException;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Intake: boundary data in, a validated unsaved Task out.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) TaskState and TaskPriority are
 * stateless published vocabularies; static access to them IS the design.
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) fromData() is one setter
 * per column of the resolved 23-shape union; splitting it by column group
 * would spread one validation boundary over several methods.
 * @SuppressWarnings(PHPMD.NPathComplexity) Same cause: each nullable field
 * is one independent branch.
 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Same cause.
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-one-lifecycle-with-every-legacy-value-mapped-onto-it
 */
class TaskBuilder {

	/**
	 * Carried text fields: payload key => setter, trimmed, blank as null.
	 *
	 * Includes the template FREEZE (id lands with version and snapshot, and
	 * later evaluation reads only the snapshot) and sequence membership (the
	 * ordinal is written at provisioning and never re-derived from
	 * timestamps, flow-approval-consolidation).
	 */
	private const STRING_FIELDS = [
		'key' => 'setTaskKey',
		'title' => 'setTitle',
		'description' => 'setDescription',
		'kind' => 'setKind',
		'runUuid' => 'setRunUuid',
		'nodeId' => 'setNodeId',
		'appId' => 'setAppId',
		'workflowStepId' => 'setWorkflowStepId',
		'organisation' => 'setOrganisation',
		'assignee' => 'setAssignee',
		'candidateRole' => 'setCandidateRole',
		'routingStrategy' => 'setRoutingStrategy',
		'routingFallback' => 'setRoutingFallback',
		'onBehalfOf' => 'setOnBehalfOf',
		'mandate' => 'setMandate',
		'requester' => 'setRequester',
		'slaUnit' => 'setSlaUnit',
		'recurrence' => 'setRecurrence',
		'objectUuid' => 'setObjectUuid',
		'blockedBy' => 'setBlockedBy',
		'templateId' => 'setTemplateId',
		'sequenceUuid' => 'setSequenceUuid',
	];

	/**
	 * Carried integer fields: payload key => setter, empty as null.
	 */
	private const INT_FIELDS = [
		'definitionVersion' => 'setDefinitionVersion',
		'slaValue' => 'setSlaValue',
		'compliancePeriodDays' => 'setCompliancePeriodDays',
		'registerId' => 'setRegisterId',
		'schemaId' => 'setSchemaId',
		'parentTaskId' => 'setParentTaskId',
		'epicTaskId' => 'setEpicTaskId',
		'percentComplete' => 'setPercentComplete',
		'templateVersion' => 'setTemplateVersion',
		'sequencePosition' => 'setSequencePosition',
		'legacyStepId' => 'setLegacyStepId',
	];

	/**
	 * Carried array fields: payload key => setter, a non-array as null.
	 */
	private const ARRAY_FIELDS = [
		'metadata' => 'setMetadata',
		'candidateUsers' => 'setCandidateUsers',
		'candidateGroups' => 'setCandidateGroups',
		'watchers' => 'setWatchers',
		'responses' => 'setResponses',
		'evidence' => 'setEvidence',
		'templateSnapshot' => 'setTemplateSnapshot',
	];

	/**
	 * Build and validate a new task from boundary data. Unsaved.
	 *
	 * @param array<string, mixed> $data The incoming fields.
	 * @param string|null $actor The creating identity.
	 *
	 * @return Task The validated, unsaved task.
	 *
	 * @throws TaskValidationException On any refused value.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-one-lifecycle-with-every-legacy-value-mapped-onto-it
	 * @spec openspec/changes/a-task-may-wait-on-another-task/specs/flow-tasks/spec.md#requirement-a-task-may-wait-on-another-task-and-waits-out-of-sight
	 */
	public function fromData(array $data, ?string $actor): Task {
		$task = new Task();
		$this->applyLifecycle(task: $task, data: $data);

		// Priority: one scale, off-scale refused.
		$task->setPriority(TaskPriority::normalise(value: ($data['priority'] ?? 'normal')));
		$task->setPerformerType($this->validPerformerType(data: $data));
		$this->applyDeadlines(task: $task, data: $data);
		$this->applyBehaviours(task: $task, data: $data);
		$task->setStartAt($this->parseDate(value: ($data['startAt'] ?? null), field: 'startAt'));
		$task->setSuspendedUntil($this->parseDate(value: ($data['suspendedUntil'] ?? null), field: 'suspendedUntil'));

		// Checklist: typed array, never a string containing JSON.
		$task->setChecklist($this->validChecklist(value: ($data['checklist'] ?? null)));

		// Plain carried fields.
		$task->setUuid((string)($data['uuid'] ?? Uuid::v4()->toRfc4122()));
		$this->carryFields(task: $task, data: $data);
		$task->setCreatedBy($actor);

		return $task;
	}//end fromData()

	/**
	 * Lifecycle: any vocabulary in, one vocabulary stored.
	 *
	 * State and is_terminal land together — the same invariant
	 * TaskService::applyState() keeps for every later move — and the
	 * collapsed distinction lands on outcome unless the caller supplied an
	 * explicit one.
	 *
	 * @param Task $task The task being built.
	 * @param array<string, mixed> $data The incoming fields.
	 *
	 * @return void
	 *
	 * @throws TaskValidationException On an unmapped state.
	 */
	private function applyLifecycle(Task $task, array $data): void {
		$normalised = TaskState::normalise(value: (string)($data['state'] ?? Task::STATE_AVAILABLE));
		$task->setState($normalised['state']);
		$task->setIsTerminal(TaskState::isTerminal(state: $normalised['state']));
		$task->setLastAction('create');
		$outcome = $normalised['outcome'];
		if ((string)($data['outcome'] ?? '') !== '') {
			$outcome = (string)$data['outcome'];
		}

		$task->setOutcome($outcome);
	}//end applyLifecycle()

	/**
	 * Performer type: validated against the extensible vocabulary.
	 *
	 * @param array<string, mixed> $data The incoming fields.
	 *
	 * @return string The performer type.
	 *
	 * @throws TaskValidationException Outside the vocabulary.
	 */
	private function validPerformerType(array $data): string {
		$performerType = (string)($data['performerType'] ?? Task::PERFORMER_USER);
		if (in_array($performerType, Task::PERFORMER_TYPES, true) === false) {
			throw new TaskValidationException(
				message: sprintf("Performer type '%s' is not in the known vocabulary (%s).", $performerType, implode('|', Task::PERFORMER_TYPES))
			);
		}

		return $performerType;
	}//end validPerformerType()

	/**
	 * Deadlines: due_at advises, expires_at enforces, and an expiry before
	 * the due date is a configuration error, not a schedule.
	 *
	 * @param Task $task The task being built.
	 * @param array<string, mixed> $data The incoming fields.
	 *
	 * @return void
	 *
	 * @throws TaskValidationException On an unparsable date or an expiry before the due date.
	 */
	private function applyDeadlines(Task $task, array $data): void {
		$dueAt = $this->parseDate(value: ($data['dueAt'] ?? null), field: 'dueAt');
		$expiresAt = $this->parseDate(value: ($data['expiresAt'] ?? null), field: 'expiresAt');
		if ($dueAt !== null && $expiresAt !== null && $expiresAt < $dueAt) {
			throw new TaskValidationException(
				message: sprintf(
					"expiresAt '%s' lies before dueAt '%s': a task that dies before it is due is a configuration error.",
					$expiresAt->format('c'),
					$dueAt->format('c')
				)
			);
		}

		$task->setDueAt($dueAt);
		$task->setExpiresAt($expiresAt);
	}//end applyDeadlines()

	/**
	 * Declared behaviours: one reserved vocabulary, refused by name outside
	 * it, and a timeout behaviour with no deadline is a configuration error,
	 * not a schedule (task-expiry-and-outcomes D-6).
	 *
	 * @param Task $task The task being built, its deadlines already set.
	 * @param array<string, mixed> $data The incoming fields.
	 *
	 * @return void
	 *
	 * @throws TaskValidationException On an unknown behaviour or a timeout without deadline.
	 */
	private function applyBehaviours(Task $task, array $data): void {
		$onTimeout = $this->validBehaviour(value: ($data['onTimeout'] ?? null), field: 'onTimeout');
		if ($onTimeout !== null && $task->getExpiresAt() === null) {
			throw new TaskValidationException(
				message: sprintf("onTimeout '%s' without an expiresAt is a configuration error: there is no deadline to time out on.", $onTimeout)
			);
		}

		$task->setOnTimeout($onTimeout);
		$task->setOnReject($this->validBehaviour(value: ($data['onReject'] ?? null), field: 'onReject'));
	}//end applyBehaviours()

	/**
	 * Copy the plain carried fields, each through its own normaliser.
	 *
	 * @param Task $task The task being built.
	 * @param array<string, mixed> $data The incoming fields.
	 *
	 * @return void
	 */
	private function carryFields(Task $task, array $data): void {
		foreach (self::STRING_FIELDS as $key => $setter) {
			$task->$setter($this->stringOrNull(value: $data[$key] ?? null));
		}

		foreach (self::INT_FIELDS as $key => $setter) {
			$task->$setter($this->intOrNull(value: $data[$key] ?? null));
		}

		foreach (self::ARRAY_FIELDS as $key => $setter) {
			$task->$setter($this->arrayOrNull(value: $data[$key] ?? null));
		}
	}//end carryFields()

	/**
	 * The typed relations named at creation, as unsaved rows.
	 *
	 * @param Task $task The persisted task.
	 * @param array<string, mixed> $data The creation payload; `relations` is
	 *                                   a list of {role, objectUuid,
	 *                                   registerId?, schemaId?}.
	 *
	 * @return array<int, TaskRelation> The relation rows to insert.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-one-generic-anchor-plus-typed-relations
	 */
	public function relationsFor(Task $task, array $data): array {
		$relations = ($data['relations'] ?? null);
		if (is_array($relations) === false) {
			return [];
		}

		$rows = [];
		foreach ($relations as $relation) {
			if (is_array($relation) === true) {
				$rows[] = $this->relationRow(task: $task, relation: $relation);
			}
		}

		return $rows;
	}//end relationsFor()

	/**
	 * One relation row, refused without a role or an object.
	 *
	 * @param Task $task The inserted task.
	 * @param array<string, mixed> $relation The declared relation.
	 *
	 * @return TaskRelation The unsaved row.
	 *
	 * @throws TaskValidationException Without a role or an objectUuid.
	 */
	private function relationRow(Task $task, array $relation): TaskRelation {
		$role = trim((string)($relation['role'] ?? ''));
		$objectUuid = trim((string)($relation['objectUuid'] ?? ''));
		if ($role === '' || $objectUuid === '') {
			throw new TaskValidationException(message: 'A task relation requires both a role and an objectUuid.');
		}

		$row = new TaskRelation();
		$row->setTaskId((int)$task->getId());
		$row->setRole($role);
		$row->setObjectUuid($objectUuid);
		$row->setRegisterId($this->intOrNull(value: ($relation['registerId'] ?? null)));
		$row->setSchemaId($this->intOrNull(value: ($relation['schemaId'] ?? null)));

		return $row;
	}//end relationRow()

	/**
	 * A declared behaviour: one value of the reserved outcome vocabulary.
	 *
	 * @param mixed $value The incoming value.
	 * @param string $field The field name, for the refusal message.
	 *
	 * @return string|null The validated behaviour, or null for absent.
	 *
	 * @throws TaskValidationException When present but outside the vocabulary.
	 *
	 * @spec openspec/specs/task-expiry-and-outcomes/spec.md#requirement-a-task-declares-its-timeout-and-reject-behaviour-in-one-vocabulary
	 */
	private function validBehaviour(mixed $value, string $field): ?string {
		$behaviour = $this->stringOrNull(value: $value);
		if ($behaviour === null) {
			return null;
		}

		if (in_array($behaviour, Task::OUTCOME_BEHAVIOURS, true) === false) {
			throw new TaskValidationException(
				message: sprintf(
					"Field '%s' value '%s' is not in the behaviour vocabulary (%s).",
					$field,
					$behaviour,
					implode('|', Task::OUTCOME_BEHAVIOURS)
				)
			);
		}

		return $behaviour;
	}//end validBehaviour()

	/**
	 * Parse a date field: DateTime passes, ISO strings parse, junk refuses.
	 *
	 * @param mixed $value The incoming value.
	 * @param string $field The field name, for the refusal message.
	 *
	 * @return DateTime|null The parsed date, or null for absent.
	 *
	 * @throws TaskValidationException When present but unparsable.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-due_at-advises-expires_at-enforces
	 */
	private function parseDate(mixed $value, string $field): ?DateTime {
		if ($value === null || $value === '') {
			return null;
		}

		$date = $this->dateOf(value: $value);
		if ($date === null) {
			throw new TaskValidationException(
				message: sprintf("Field '%s' does not parse as a date.", $field)
			);
		}

		return $date;
	}//end parseDate()

	/**
	 * A given DateTime, or a string parsed as one; null when neither.
	 *
	 * @param mixed $value The raw value, not null or empty.
	 *
	 * @return DateTime|null The date, or null when it does not parse.
	 */
	private function dateOf(mixed $value): ?DateTime {
		if ($value instanceof DateTime === true) {
			return $value;
		}

		if (is_string($value) === false) {
			return null;
		}

		try {
			return new DateTime($value);
		} catch (Throwable) {
			return null;
		}
	}//end dateOf()

	/**
	 * The checklist must be a typed array of {id, label} items.
	 *
	 * A STRING is refused by name: procest stores JSON-in-a-string today,
	 * which is exactly the unqueryable shape this entity removes.
	 *
	 * @param mixed $value The incoming checklist.
	 *
	 * @return array<int, array<string, mixed>>|null The validated checklist.
	 *
	 * @throws TaskValidationException When it is a string or malformed.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-a-templated-task-freezes-its-template-at-creation
	 */
	private function validChecklist(mixed $value): ?array {
		if ($value === null) {
			return null;
		}

		if (is_string($value) === true) {
			throw new TaskValidationException(
				message: 'The checklist must be a typed array of {id, label, description, checked} items, not a string containing JSON.'
			);
		}

		if (is_array($value) === false) {
			throw new TaskValidationException(message: 'The checklist must be a typed array of {id, label, description, checked} items.');
		}

		return array_map(fn (mixed $item): array => $this->checklistItem(item: $item), array_values($value));
	}//end validChecklist()

	/**
	 * One checklist item in its typed shape.
	 *
	 * @param mixed $item The declared item.
	 *
	 * @return array{id: string, label: string, description: mixed, checked: bool} The item.
	 *
	 * @throws TaskValidationException Without an id or a label.
	 */
	private function checklistItem(mixed $item): array {
		if (is_array($item) === false || trim((string)($item['id'] ?? '')) === '' || trim((string)($item['label'] ?? '')) === '') {
			throw new TaskValidationException(message: 'Every checklist item requires an id and a label.');
		}

		return [
			'id' => (string)$item['id'],
			'label' => (string)$item['label'],
			'description' => ($item['description'] ?? null),
			'checked' => (bool)($item['checked'] ?? false),
		];
	}//end checklistItem()

	/**
	 * A trimmed string, or null for absent/empty.
	 *
	 * @param mixed $value The incoming value.
	 *
	 * @return string|null The string, or null.
	 */
	private function stringOrNull(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		$string = trim((string)$value);
		if ($string === '') {
			return null;
		}

		return $string;
	}//end stringOrNull()

	/**
	 * An integer, or null for absent.
	 *
	 * @param mixed $value The incoming value.
	 *
	 * @return int|null The integer, or null.
	 */
	private function intOrNull(mixed $value): ?int {
		if ($value === null || $value === '') {
			return null;
		}

		return (int)$value;
	}//end intOrNull()

	/**
	 * An array, or null for absent.
	 *
	 * @param mixed $value The incoming value.
	 *
	 * @return array<int|string, mixed>|null The array, or null.
	 */
	private function arrayOrNull(mixed $value): ?array {
		if (is_array($value) === true) {
			return $value;
		}

		return null;
	}//end arrayOrNull()
}//end class
