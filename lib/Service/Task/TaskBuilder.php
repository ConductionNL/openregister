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
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-one-lifecycle-with-every-legacy-value-mapped-onto-it
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
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-one-lifecycle-with-every-legacy-value-mapped-onto-it
 */
class TaskBuilder {

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
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-one-lifecycle-with-every-legacy-value-mapped-onto-it
	 */
	public function fromData(array $data, ?string $actor): Task {
		$task = new Task();

		// Lifecycle: any vocabulary in, one vocabulary stored. state and
		// is_terminal land together; the collapsed distinction lands on
		// outcome unless the caller supplied an explicit one.
		$normalised = TaskState::normalise(value: (string)($data['state'] ?? Task::STATE_AVAILABLE));
		// Creation initialises state and is_terminal TOGETHER, the same
		// invariant TaskService::applyState() keeps for every later move.
		$task->setState($normalised['state']);
		$task->setIsTerminal(TaskState::isTerminal(state: $normalised['state']));
		$task->setLastAction('create');
		$outcome = $normalised['outcome'];
		if ((string)($data['outcome'] ?? '') !== '') {
			$outcome = (string)$data['outcome'];
		}

		$task->setOutcome($outcome);

		// Priority: one scale, off-scale refused.
		$task->setPriority(TaskPriority::normalise(value: ($data['priority'] ?? 'normal')));

		// Performer type: validated against the extensible vocabulary.
		$performerType = (string)($data['performerType'] ?? Task::PERFORMER_USER);
		if (in_array($performerType, Task::PERFORMER_TYPES, true) === false) {
			throw new TaskValidationException(
				message: sprintf("Performer type '%s' is not in the known vocabulary (%s).", $performerType, implode('|', Task::PERFORMER_TYPES))
			);
		}

		$task->setPerformerType($performerType);

		// Deadlines: due_at advises, expires_at enforces, and an expiry
		// before the due date is a configuration error, not a schedule.
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

		// Declared behaviours: one reserved vocabulary, refused by name
		// outside it, and a timeout behaviour with no deadline is a
		// configuration error, not a schedule (task-expiry-and-outcomes D-6).
		$onTimeout = $this->validBehaviour(value: ($data['onTimeout'] ?? null), field: 'onTimeout');
		if ($onTimeout !== null && $expiresAt === null) {
			throw new TaskValidationException(
				message: sprintf("onTimeout '%s' without an expiresAt is a configuration error: there is no deadline to time out on.", $onTimeout)
			);
		}

		$task->setOnTimeout($onTimeout);
		$task->setOnReject($this->validBehaviour(value: ($data['onReject'] ?? null), field: 'onReject'));
		$task->setStartAt($this->parseDate(value: ($data['startAt'] ?? null), field: 'startAt'));
		$task->setSuspendedUntil($this->parseDate(value: ($data['suspendedUntil'] ?? null), field: 'suspendedUntil'));

		// Checklist: typed array, never a string containing JSON.
		$task->setChecklist($this->validChecklist(value: ($data['checklist'] ?? null)));

		// Plain carried fields.
		$task->setUuid((string)($data['uuid'] ?? Uuid::v4()->toRfc4122()));
		$task->setTaskKey($this->stringOrNull(value: $data['key'] ?? null));
		$task->setTitle($this->stringOrNull(value: $data['title'] ?? null));
		$task->setDescription($this->stringOrNull(value: $data['description'] ?? null));
		$task->setMetadata($this->arrayOrNull(value: $data['metadata'] ?? null));
		$task->setRunUuid($this->stringOrNull(value: $data['runUuid'] ?? null));
		$task->setNodeId($this->stringOrNull(value: $data['nodeId'] ?? null));
		$task->setDefinitionVersion($this->intOrNull(value: ($data['definitionVersion'] ?? null)));
		$task->setAppId($this->stringOrNull(value: $data['appId'] ?? null));
		$task->setWorkflowStepId($this->stringOrNull(value: $data['workflowStepId'] ?? null));
		$task->setOrganisation($this->stringOrNull(value: $data['organisation'] ?? null));
		$task->setAssignee($this->stringOrNull(value: $data['assignee'] ?? null));
		$task->setCandidateUsers($this->arrayOrNull(value: $data['candidateUsers'] ?? null));
		$task->setCandidateGroups($this->arrayOrNull(value: $data['candidateGroups'] ?? null));
		$task->setCandidateRole($this->stringOrNull(value: $data['candidateRole'] ?? null));
		$task->setRoutingStrategy($this->stringOrNull(value: $data['routingStrategy'] ?? null));
		$task->setRoutingFallback($this->stringOrNull(value: $data['routingFallback'] ?? null));
		$task->setOnBehalfOf($this->stringOrNull(value: $data['onBehalfOf'] ?? null));
		$task->setMandate($this->stringOrNull(value: $data['mandate'] ?? null));
		$task->setRequester($this->stringOrNull(value: $data['requester'] ?? null));
		$task->setWatchers($this->arrayOrNull(value: $data['watchers'] ?? null));
		$task->setSlaValue($this->intOrNull(value: ($data['slaValue'] ?? null)));
		$task->setSlaUnit($this->stringOrNull(value: $data['slaUnit'] ?? null));
		$task->setCompliancePeriodDays($this->intOrNull(value: ($data['compliancePeriodDays'] ?? null)));
		$task->setRecurrence($this->stringOrNull(value: $data['recurrence'] ?? null));
		$task->setObjectUuid($this->stringOrNull(value: $data['objectUuid'] ?? null));
		$task->setRegisterId($this->intOrNull(value: ($data['registerId'] ?? null)));
		$task->setSchemaId($this->intOrNull(value: ($data['schemaId'] ?? null)));
		$task->setParentTaskId($this->intOrNull(value: ($data['parentTaskId'] ?? null)));
		$task->setEpicTaskId($this->intOrNull(value: ($data['epicTaskId'] ?? null)));
		$task->setPercentComplete($this->intOrNull(value: ($data['percentComplete'] ?? null)));
		$task->setResponses($this->arrayOrNull(value: $data['responses'] ?? null));
		$task->setEvidence($this->arrayOrNull(value: $data['evidence'] ?? null));
		$task->setCreatedBy($actor);

		// Template FREEZE at creation: id, version and the snapshot land
		// together, and later evaluation reads only the snapshot.
		$task->setTemplateId($this->stringOrNull(value: $data['templateId'] ?? null));
		$task->setTemplateVersion($this->intOrNull(value: ($data['templateVersion'] ?? null)));
		$task->setTemplateSnapshot($this->arrayOrNull(value: $data['templateSnapshot'] ?? null));

		// Sequence membership (flow-approval-consolidation): the ordinal is
		// written at provisioning and never re-derived from timestamps.
		$task->setSequenceUuid($this->stringOrNull(value: $data['sequenceUuid'] ?? null));
		$task->setSequencePosition($this->intOrNull(value: ($data['sequencePosition'] ?? null)));
		$task->setLegacyStepId($this->intOrNull(value: ($data['legacyStepId'] ?? null)));

		return $task;
	}//end fromData()

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
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-one-generic-anchor-plus-typed-relations
	 */
	public function relationsFor(Task $task, array $data): array {
		$relations = ($data['relations'] ?? null);
		if (is_array($relations) === false) {
			return [];
		}

		$rows = [];
		foreach ($relations as $relation) {
			if (is_array($relation) === false) {
				continue;
			}

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
			$rows[] = $row;
		}

		return $rows;
	}//end relationsFor()

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
	 * @spec openspec/changes/task-expiry-and-outcomes/specs/task-expiry-and-outcomes/spec.md#requirement-a-task-declares-its-timeout-and-reject-behaviour-in-one-vocabulary
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
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-due_at-advises-expires_at-enforces
	 */
	private function parseDate(mixed $value, string $field): ?DateTime {
		if ($value === null || $value === '') {
			return null;
		}

		if ($value instanceof DateTime === true) {
			return $value;
		}

		if (is_string($value) === true) {
			try {
				return new DateTime($value);
			} catch (Throwable) {
				// Falls through to the refusal below.
			}
		}

		throw new TaskValidationException(
			message: sprintf("Field '%s' does not parse as a date.", $field)
		);
	}//end parseDate()

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
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-a-templated-task-freezes-its-template-at-creation
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

		$items = [];
		foreach ($value as $item) {
			if (is_array($item) === false || trim((string)($item['id'] ?? '')) === '' || trim((string)($item['label'] ?? '')) === '') {
				throw new TaskValidationException(message: 'Every checklist item requires an id and a label.');
			}

			$items[] = [
				'id' => (string)$item['id'],
				'label' => (string)$item['label'],
				'description' => ($item['description'] ?? null),
				'checked' => (bool)($item['checked'] ?? false),
			];
		}

		return $items;
	}//end validChecklist()

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
