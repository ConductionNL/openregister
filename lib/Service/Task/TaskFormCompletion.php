<?php

/**
 * Completing a task WITH a payload: the object write first, the task verb second.
 *
 * The payload is validated by the lifecycle transition input allowlist and by
 * nothing else. Where the form names an action, {@see TransitionEngine::transition()}
 * runs the allowlist, merges the accepted values and flips the lifecycle field
 * in ONE save; where it lists fields inline, the SAME allowlist method runs
 * here and the accepted values go through the ordinary object write. There is
 * no validator of this class's own, so a value never reaches storage having
 * been checked only by the form layer.
 *
 * THE ORDER IS THE POINT. The subject object is written BEFORE the task is
 * completed. A refused write leaves the task actionable and its run suspended:
 * a completed task whose evidence was refused would be a lie in an inbox, and
 * the run would advance on it. A task-completion failure AFTER a successful
 * write leaves the write standing and the resubmit idempotent, because writing
 * the same values to the same object twice is the same object. The reverse
 * order has no such recovery (design D-5).
 *
 * Two authorizations, both apply. The task verb's, first, fail-closed and
 * audited on denial by the task service; the object write's, on the save
 * path, exactly as for any other write. Being the assignee grants no write on
 * the subject, and either may refuse.
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
 * @spec openspec/changes/flow-task-forms/specs/flow-task-forms/spec.md#requirement-a-completion-payload-is-validated-by-the-lifecycle-input-allowlist-and-by-nothing-else
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Exception\InvalidTransitionInputException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\TaskAccessDeniedException;
use OCA\OpenRegister\Exception\TaskFormRefusedException;
use OCA\OpenRegister\Exception\TaskSubjectWriteRefusedException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUserSession;
use RuntimeException;

/**
 * Validates a completion payload through the lifecycle allowlist and writes it before completing.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) This class IS the seam
 * between the task verb and the object write: it has to name the task
 * service, the resolver, the engine, the object service and the five
 * exception shapes it translates between. Splitting it would put the two
 * halves of "complete with a payload" in two files that must agree on order.
 *
 * @spec openspec/changes/flow-task-forms/specs/flow-task-forms/spec.md#requirement-a-completion-payload-is-validated-by-the-lifecycle-input-allowlist-and-by-nothing-else
 */
class TaskFormCompletion {

	/**
	 * The action name the allowlist reports for an inline field list.
	 *
	 * @var string
	 */
	private const INLINE_ACTION = 'complete';

	/**
	 * Constructor.
	 *
	 * @param TaskService $tasks The authorized task lifecycle.
	 * @param TaskFormResolver $forms Resolves the task's form.
	 * @param TransitionEngine $engine The one allowlist, and the transition write.
	 * @param ObjectService $objects The ordinary object write, for an inline field list.
	 * @param IUserSession $userSession The acting identity the object write authorizes.
	 */
	public function __construct(
		private readonly TaskService $tasks,
		private readonly TaskFormResolver $forms,
		private readonly TransitionEngine $engine,
		private readonly ObjectService $objects,
		private readonly IUserSession $userSession,
	) {

	}//end __construct()

	/**
	 * Complete a task, writing its form payload to the subject first.
	 *
	 * @param string $uuid The task uuid.
	 * @param string $outcome The completion outcome.
	 * @param string|null $resultText Free-text result.
	 * @param string|null $comment Completion comment.
	 * @param array<string, mixed> $data The declared field values; empty for a form-less completion.
	 * @param string|null $actor The completing identity.
	 *
	 * @return Task The completed task.
	 *
	 * @throws TaskFormRefusedException When the payload violates the form (400, fields named).
	 * @throws TaskSubjectWriteRefusedException When the subject's own validation or lifecycle refuses the write (422).
	 * @throws TaskAccessDeniedException When the task verb or the object write is not authorized.
	 *
	 * @spec openspec/changes/flow-task-forms/specs/flow-task-forms/spec.md#requirement-a-completion-payload-is-validated-by-the-lifecycle-input-allowlist-and-by-nothing-else
	 */
	public function complete(string $uuid, string $outcome, ?string $resultText, ?string $comment, array $data, ?string $actor): Task {
		// The task verb's authorization FIRST, fail-closed and audited on
		// denial, before a single byte reaches the subject object.
		$task = $this->tasks->authorizedOpenTask(verb: 'complete', uuid: $uuid, actor: $actor);

		try {
			$described = $this->forms->describe(task: $task);
			$this->refuseUnresolvable(described: $described);
			$this->refuseUncheckedItems(task: $task, required: $described['requireChecklist']);
			$this->writeSubject(task: $task, form: $described['form'], data: $data);
		} catch (TaskFormRefusedException | TaskSubjectWriteRefusedException $refused) {
			// A refused attempt, distinguishable from a completion: the task
			// keeps its pre-call state and the audit says somebody tried.
			$this->tasks->recordRefusedCompletion(task: $task, reason: $refused->getMessage(), actor: $actor);

			throw $refused;
		}

		return $this->tasks->complete(uuid: $uuid, outcome: $outcome, resultText: $resultText, comment: $comment, actor: $actor);
	}//end complete()

	/**
	 * An unresolvable form completes nothing: an empty form would report success for a task that required evidence.
	 *
	 * @param array{form: array<string, mixed>|null, requireChecklist: bool} $described The resolved surface.
	 *
	 * @return void
	 *
	 * @throws TaskFormRefusedException When the form's state is unresolvable.
	 *
	 * @spec openspec/changes/flow-task-forms/specs/flow-task-forms/spec.md#requirement-the-form-a-task-presents-is-the-one-its-flow-version-declared
	 */
	private function refuseUnresolvable(array $described): void {
		$form = $described['form'];
		if ($form !== null && ($form['state'] ?? null) === TaskFormResolver::STATE_UNRESOLVABLE) {
			throw new TaskFormRefusedException(
				message: (string)($form['error'] ?? 'The form of this task cannot be resolved.'),
				kind: TaskFormRefusedException::KIND_UNRESOLVABLE
			);
		}
	}//end refuseUnresolvable()

	/**
	 * The checklist precondition: every item checked, or the completion is refused naming the unchecked ones.
	 *
	 * A checklist item is task state, written through the task's own verb; it
	 * is never a field and never enters the allowlist. It refuses the same way
	 * a missing required field does: named, not completed, run not advanced.
	 *
	 * @param Task $task The task.
	 * @param bool $required Whether the step requires every item checked.
	 *
	 * @return void
	 *
	 * @throws TaskFormRefusedException When an item is still unchecked.
	 *
	 * @spec openspec/changes/flow-task-forms/specs/flow-task-forms/spec.md#requirement-a-checklist-is-presented-beside-the-field-form-never-merged-into-it
	 */
	private function refuseUncheckedItems(Task $task, bool $required): void {
		if ($required === false) {
			return;
		}

		$unchecked = [];
		foreach (($task->getChecklist() ?? []) as $item) {
			if (is_array($item) === false) {
				continue;
			}

			if (filter_var(($item['checked'] ?? false), FILTER_VALIDATE_BOOLEAN) === false) {
				$unchecked[] = (string)($item['id'] ?? ($item['label'] ?? ''));
			}
		}

		if ($unchecked !== []) {
			throw new TaskFormRefusedException(
				message: sprintf(
					'Every checklist item must be checked before this task can be completed; still unchecked: %s.',
					'"' . implode('", "', $unchecked) . '"'
				),
				kind: TaskFormRefusedException::KIND_CHECKLIST,
				fields: $unchecked
			);
		}
	}//end refuseUncheckedItems()

	/**
	 * Write the payload to the subject through one of the two existing paths, never a third.
	 *
	 * @param Task $task The task.
	 * @param array<string, mixed>|null $form The resolved form description.
	 * @param array<string, mixed> $data The submitted field values.
	 *
	 * @return void
	 *
	 * @throws TaskFormRefusedException When the payload violates the allowlist.
	 * @throws TaskSubjectWriteRefusedException When the subject refuses the write.
	 * @throws TaskAccessDeniedException When the object write is not authorized.
	 *
	 * @spec openspec/changes/flow-task-forms/specs/flow-task-forms/spec.md#requirement-a-completion-payload-is-validated-by-the-lifecycle-input-allowlist-and-by-nothing-else
	 */
	private function writeSubject(Task $task, ?array $form, array $data): void {
		if ($form === null || ($form['kind'] ?? null) !== TaskForm::KIND_FIELDS) {
			// No form, or an external one: no field values are accepted at
			// all. An empty payload skips the allowlist entirely, so a
			// form-less completion is exactly what it was before.
			if ($data !== []) {
				$this->allowlist(declared: [], data: $data, action: self::INLINE_ACTION);
			}

			return;
		}

		$objectUuid = trim((string)$task->getObjectUuid());
		if ($objectUuid === '') {
			throw new TaskFormRefusedException(
				message: 'This task has no subject object, so its form values have nowhere to be written.',
				kind: TaskFormRefusedException::KIND_NO_SUBJECT
			);
		}

		$action = ($form['action'] ?? null);
		if (is_string($action) === true && $action !== '') {
			$this->transition(objectUuid: $objectUuid, action: $action, data: $data);

			return;
		}

		$declared = [];
		foreach ((array)($form['fields'] ?? []) as $field) {
			$declared[] = [
				'field' => (string)$field['field'],
				'required' => (bool)$field['required'],
			];
		}

		$accepted = $this->allowlist(declared: $declared, data: $data, action: self::INLINE_ACTION);
		if ($accepted === []) {
			return;
		}

		$this->save(objectUuid: $objectUuid, accepted: $accepted);
	}//end writeSubject()

	/**
	 * The ONE allowlist, with its refusal translated to the task's 400 shape.
	 *
	 * The kind is derived from the declaration, not parsed from the message:
	 * a refused field the form declared was missing, one it did not was
	 * undeclared.
	 *
	 * @param array<int, array{field: string, required: bool}> $declared The declared fields.
	 * @param array<string, mixed> $data The submitted values.
	 * @param string $action The action name, for the message.
	 *
	 * @return array<string, mixed> The accepted values.
	 *
	 * @throws TaskFormRefusedException When the allowlist refuses.
	 *
	 * @spec openspec/changes/flow-task-forms/specs/flow-task-forms/spec.md#requirement-a-validation-failure-names-its-fields-and-completes-nothing
	 */
	private function allowlist(array $declared, array $data, string $action): array {
		try {
			return $this->engine->resolveTransitionInputs(inputs: $declared, data: $data, action: $action);
		} catch (InvalidTransitionInputException $refused) {
			throw $this->refusal(refused: $refused, declared: $declared);
		}
	}//end allowlist()

	/**
	 * The transition path: allowlist, merge and lifecycle flip in one save.
	 *
	 * @param string $objectUuid The subject object.
	 * @param string $action The lifecycle action.
	 * @param array<string, mixed> $data The submitted values.
	 *
	 * @return void
	 *
	 * @throws TaskFormRefusedException When the allowlist refuses.
	 * @throws TaskAccessDeniedException When the caller may not transition the object.
	 * @throws TaskSubjectWriteRefusedException When the schema or the lifecycle refuses.
	 *
	 * @spec openspec/changes/flow-task-forms/specs/flow-task-forms/spec.md#requirement-a-completion-payload-is-validated-by-the-lifecycle-input-allowlist-and-by-nothing-else
	 */
	private function transition(string $objectUuid, string $action, array $data): void {
		try {
			$this->engine->transition(objectId: $objectUuid, action: $action, data: $data);
		} catch (InvalidTransitionInputException $refused) {
			// The engine's kind is not recoverable from its declaration here,
			// so read it off the message the same method wrote.
			throw $this->refusal(refused: $refused, declared: null);
		} catch (NotAuthorizedException $denied) {
			throw new TaskAccessDeniedException(message: $denied->getMessage());
		} catch (HookStoppedException | ValidationException | CustomValidationException | RuntimeException $refused) {
			throw new TaskSubjectWriteRefusedException(message: $refused->getMessage(), previous: $refused);
		}
	}//end transition()

	/**
	 * The inline path: the accepted values through the ordinary object write.
	 *
	 * @param string $objectUuid The subject object.
	 * @param array<string, mixed> $accepted The values the allowlist accepted.
	 *
	 * @return void
	 *
	 * @throws TaskAccessDeniedException When the caller may not write the object.
	 * @throws TaskSubjectWriteRefusedException When the schema refuses, or the object is gone.
	 *
	 * @spec openspec/changes/flow-task-forms/specs/flow-task-forms/spec.md#requirement-a-completion-payload-is-validated-by-the-lifecycle-input-allowlist-and-by-nothing-else
	 */
	private function save(string $objectUuid, array $accepted): void {
		$object = $this->objects->find(id: $objectUuid);
		if ($object === null) {
			throw new TaskSubjectWriteRefusedException(
				message: sprintf('Subject object "%s" no longer exists, so the form values cannot be written.', $objectUuid)
			);
		}

		try {
			$this->objects->saveObject(
				object: array_merge($object->getObject(), $accepted),
				register: $object->getRegister(),
				schema: $object->getSchema(),
				uuid: $object->getUuid(),
				currentUser: $this->userSession->getUser()
			);
		} catch (NotAuthorizedException $denied) {
			throw new TaskAccessDeniedException(message: $denied->getMessage());
		} catch (HookStoppedException | ValidationException | CustomValidationException $refused) {
			throw new TaskSubjectWriteRefusedException(message: $refused->getMessage(), previous: $refused);
		}
	}//end save()

	/**
	 * The task-side 400 for an allowlist refusal, its kind derived rather than guessed.
	 *
	 * @param InvalidTransitionInputException $refused The engine's refusal.
	 * @param array<int, array{field: string, required: bool}>|null $declared The declaration, when this class holds it.
	 *
	 * @return TaskFormRefusedException The translated refusal.
	 */
	private function refusal(InvalidTransitionInputException $refused, ?array $declared): TaskFormRefusedException {
		$fields = $refused->getFields();
		$kind = TaskFormRefusedException::KIND_UNDECLARED;

		if ($declared !== null) {
			$names = array_column($declared, 'field');
			if ($fields !== [] && array_diff($fields, $names) === []) {
				$kind = TaskFormRefusedException::KIND_MISSING;
			}
		} else if (str_contains($refused->getMessage(), 'missing required') === true) {
			$kind = TaskFormRefusedException::KIND_MISSING;
		}

		return new TaskFormRefusedException(message: $refused->getMessage(), kind: $kind, fields: $fields);
	}//end refusal()
}//end class
