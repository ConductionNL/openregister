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
 * @spec openspec/specs/flow-task-forms/spec.md#requirement-a-completion-payload-is-validated-by-the-lifecycle-input-allowlist-and-by-nothing-else
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Exception\TaskFormRefusedException;
use OCA\OpenRegister\Exception\TaskSubjectWriteRefusedException;

/**
 * Validates a completion payload through the lifecycle allowlist and writes it before completing.
 *
 * The order is fixed here: the task verb's authorization, the form's
 * resolvability, the checklist, then {@see TaskFormSubjectWriter} writes
 * the subject, and only then the task completes.
 *
 * @spec openspec/specs/flow-task-forms/spec.md#requirement-a-completion-payload-is-validated-by-the-lifecycle-input-allowlist-and-by-nothing-else
 */
class TaskFormCompletion implements TaskFormCompletionInterface {

	/**
	 * Constructor.
	 *
	 * @param TaskServiceInterface $tasks The authorized task lifecycle.
	 * @param TaskFormResolver $forms Resolves the task's form.
	 * @param TaskFormSubjectWriter $writer The allowlist and the two subject write paths.
	 */
	public function __construct(
		private readonly TaskServiceInterface $tasks,
		private readonly TaskFormResolver $forms,
		private readonly TaskFormSubjectWriter $writer,
	) {

	}//end __construct()

	/**
	 * Complete a task, writing its form payload to the subject first.
	 *
	 * @param FormCompletionRequest $request The task uuid, the completion outcome, result and comment, and the
	 *                                       declared field values (empty for a form-less completion).
	 * @param string|null           $actor   The completing identity.
	 *
	 * @return Task The completed task.
	 *
	 * @throws TaskFormRefusedException When the payload violates the form (400, fields named).
	 * @throws TaskSubjectWriteRefusedException When the subject's own validation or lifecycle refuses the write (422).
	 * @throws \OCA\OpenRegister\Exception\TaskAccessDeniedException When the task verb or the object write is not authorized.
	 *
	 * @spec openspec/specs/flow-task-forms/spec.md#requirement-a-completion-payload-is-validated-by-the-lifecycle-input-allowlist-and-by-nothing-else
	 */
	public function complete(FormCompletionRequest $request, ?string $actor): Task {
		// The task verb's authorization FIRST, fail-closed and audited on
		// denial, before a single byte reaches the subject object.
		$task = $this->tasks->authorizedOpenTask(act: new TaskAct(verb: 'complete', actor: $actor), uuid: $request->uuid);
		$this->submit(submission: new TaskFormSubmission(task: $task, data: $request->data, actor: $actor));

		$answer = $request->answer;
		return $this->tasks->completeWithAnswer(
			uuid: $request->uuid,
			answer: new TaskAnswer(outcome: $answer->outcome, resultText: $answer->resultText, comment: $answer->comment),
			actor: $actor
		);
	}//end complete()

	/**
	 * Check the form and the checklist, then write the submitted values onto
	 * the subject; a refusal is audited as a refused completion and rethrown.
	 *
	 * @param TaskFormSubmission $submission The opened task, values and actor.
	 *
	 * @return void
	 *
	 * @throws TaskFormRefusedException When the form, checklist or values are refused.
	 * @throws TaskSubjectWriteRefusedException When the subject refuses the write.
	 */
	private function submit(TaskFormSubmission $submission): void {
		$task = $submission->task;
		try {
			$described = $this->forms->describe(task: $task);
			$this->refuseUnresolvable(described: $described);
			$this->refuseUncheckedItems(task: $task, required: $described['requireChecklist']);
			$this->writer->write(submission: $submission, form: $described['form']);
		} catch (TaskFormRefusedException | TaskSubjectWriteRefusedException $refused) {
			// A refused attempt, distinguishable from a completion: the task
			// keeps its pre-call state and the audit says somebody tried.
			$attempt = new TaskAct(verb: 'complete', actor: $submission->actor, reason: $refused->getMessage());
			$this->tasks->recordRefusedCompletion(task: $task, attempt: $attempt);

			throw $refused;
		}
	}//end submit()

	/**
	 * An unresolvable form completes nothing: an empty form would report success for a task that required evidence.
	 *
	 * @param array{form: array<string, mixed>|null, requireChecklist: bool} $described The resolved surface.
	 *
	 * @return void
	 *
	 * @throws TaskFormRefusedException When the form's state is unresolvable.
	 *
	 * @spec openspec/specs/flow-task-forms/spec.md#requirement-the-form-a-task-presents-is-the-one-its-flow-version-declared
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
	 * @spec openspec/specs/flow-task-forms/spec.md#requirement-a-checklist-is-presented-beside-the-field-form-never-merged-into-it
	 */
	private function refuseUncheckedItems(Task $task, bool $required): void {
		if ($required === false) {
			return;
		}

		$unchecked = self::uncheckedItems(task: $task);
		if ($unchecked !== []) {
			throw self::checklistRefusal(unchecked: $unchecked);
		}
	}//end refuseUncheckedItems()

	/**
	 * The ids (or labels) of the task's checklist items that are not checked.
	 *
	 * @param Task $task The task.
	 *
	 * @return array<int, string> The unchecked items.
	 */
	private static function uncheckedItems(Task $task): array {
		$unchecked = [];
		foreach (($task->getChecklist() ?? []) as $item) {
			if (is_array($item) === false) {
				continue;
			}

			if (filter_var(($item['checked'] ?? false), FILTER_VALIDATE_BOOLEAN) === false) {
				$unchecked[] = (string)($item['id'] ?? ($item['label'] ?? ''));
			}
		}

		return $unchecked;
	}//end uncheckedItems()

	/**
	 * The refusal naming the unchecked items.
	 *
	 * @param array<int, string> $unchecked The unchecked items.
	 *
	 * @return TaskFormRefusedException The refusal.
	 */
	private static function checklistRefusal(array $unchecked): TaskFormRefusedException {
		return new TaskFormRefusedException(
			message: sprintf(
				'Every checklist item must be checked before this task can be completed; still unchecked: %s.',
				'"' . implode('", "', $unchecked) . '"'
			),
			kind: TaskFormRefusedException::KIND_CHECKLIST,
			fields: $unchecked
		);
	}//end checklistRefusal()
}//end class
