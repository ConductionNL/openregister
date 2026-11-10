<?php

/**
 * Writes a completion payload to the task's subject through one of the two existing paths.
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
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Exception\InvalidTransitionInputException;
use OCA\OpenRegister\Exception\LifecycleSubjectNotFoundException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\TaskAccessDeniedException;
use OCA\OpenRegister\Exception\TaskFormRefusedException;
use OCA\OpenRegister\Exception\TaskSubjectWriteRefusedException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use RuntimeException;

/**
 * The lifecycle input allowlist, the transition path and the inline path.
 *
 * @spec openspec/specs/flow-task-forms/spec.md#requirement-a-completion-payload-is-validated-by-the-lifecycle-input-allowlist-and-by-nothing-else
 */
final class TaskFormSubjectWriter {

	/**
	 * The action name the allowlist reports for an inline field list.
	 *
	 * @var string
	 */
	private const INLINE_ACTION = 'complete';

	/**
	 * Constructor.
	 *
	 * @param TransitionEngine $engine The one allowlist, and the transition write.
	 * @param TaskFormInlineSave $inline The ordinary object write, for an inline field list.
	 */
	public function __construct(
		private readonly TransitionEngine $engine,
		private readonly TaskFormInlineSave $inline,
	) {

	}//end __construct()

	/**
	 * Write the payload to the subject through one of the two existing paths, never a third.
	 *
	 * @param TaskFormSubmission $submission The opened task and the submitted values.
	 * @param array<string, mixed>|null $form The resolved form description.
	 *
	 * @return void
	 *
	 * @throws TaskFormRefusedException When the payload violates the allowlist.
	 * @throws TaskSubjectWriteRefusedException When the subject refuses the write.
	 * @throws TaskAccessDeniedException When the object write is not authorized.
	 *
	 * @spec openspec/specs/flow-task-forms/spec.md#requirement-a-completion-payload-is-validated-by-the-lifecycle-input-allowlist-and-by-nothing-else
	 */
	public function write(TaskFormSubmission $submission, ?array $form): void {
		if (self::isFieldForm(form: $form) === false) {
			// No form, or an external one: no field values are accepted at
			// all. An empty payload skips the allowlist entirely, so a
			// form-less completion is exactly what it was before.
			if ($submission->data !== []) {
				$this->allowlist(declared: [], data: $submission->data);
			}

			return;
		}

		// A field form needs a subject, whichever path writes it.
		$objectUuid = self::subjectOf(task: $submission->task);
		$action = self::actionOf(form: $form);
		if ($action !== null) {
			$this->transition(target: new TaskSubjectTransition(objectUuid: $objectUuid, action: $action), data: $submission->data);

			return;
		}

		$this->writeInline(submission: $submission, form: $form);
	}//end write()

	/**
	 * Whether a resolved form is a native field form.
	 *
	 * @param array<string, mixed>|null $form The resolved form description.
	 *
	 * @return bool True for a field form.
	 *
	 * @phpstan-assert-if-true array<string, mixed> $form
	 */
	private static function isFieldForm(?array $form): bool {
		return $form !== null && ($form['kind'] ?? null) === TaskForm::KIND_FIELDS;
	}//end isFieldForm()

	/**
	 * The subject object a field form writes to.
	 *
	 * @param Task $task The task.
	 *
	 * @return string The subject uuid.
	 *
	 * @throws TaskFormRefusedException When the task has no subject.
	 */
	private static function subjectOf(Task $task): string {
		$objectUuid = trim((string)$task->getObjectUuid());
		if ($objectUuid === '') {
			throw new TaskFormRefusedException(
				message: 'This task has no subject object, so its form values have nowhere to be written.',
				kind: TaskFormRefusedException::KIND_NO_SUBJECT
			);
		}

		return $objectUuid;
	}//end subjectOf()

	/**
	 * The lifecycle action a field form inherits its fields from, or null for an inline list.
	 *
	 * @param array<string, mixed> $form The resolved form description.
	 *
	 * @return string|null The action.
	 */
	private static function actionOf(array $form): ?string {
		$action = ($form['action'] ?? null);
		if (is_string($action) === true && $action !== '') {
			return $action;
		}

		return null;
	}//end actionOf()

	/**
	 * The inline path: allowlist the declared fields, then save what was accepted.
	 *
	 * @param TaskFormSubmission $submission The opened task and the submitted values.
	 * @param array<string, mixed> $form The resolved form description.
	 *
	 * @return void
	 */
	private function writeInline(TaskFormSubmission $submission, array $form): void {
		$accepted = $this->allowlist(declared: self::declaredFields(form: $form), data: $submission->data);
		if ($accepted === []) {
			return;
		}

		$this->inline->save(task: $submission->task, accepted: $accepted);
	}//end writeInline()

	/**
	 * The inline field list in the allowlist's shape.
	 *
	 * @param array<string, mixed> $form The resolved form description.
	 *
	 * @return array<int, array{field: string, required: bool}> The declared fields.
	 */
	private static function declaredFields(array $form): array {
		$declared = [];
		foreach ((array)($form['fields'] ?? []) as $field) {
			$declared[] = [
				'field' => (string)$field['field'],
				'required' => (bool)$field['required'],
			];
		}

		return $declared;
	}//end declaredFields()

	/**
	 * The ONE allowlist, with its refusal translated to the task's 400 shape.
	 *
	 * The kind is derived from the declaration, not parsed from the message:
	 * a refused field the form declared was missing, one it did not was
	 * undeclared.
	 *
	 * @param array<int, array{field: string, required: bool}> $declared The declared fields.
	 * @param array<string, mixed> $data The submitted values.
	 *
	 * @return array<string, mixed> The accepted values.
	 *
	 * @throws TaskFormRefusedException When the allowlist refuses.
	 *
	 * @spec openspec/specs/flow-task-forms/spec.md#requirement-a-validation-failure-names-its-fields-and-completes-nothing
	 */
	private function allowlist(array $declared, array $data): array {
		try {
			return $this->engine->resolveTransitionInputs(inputs: $declared, data: $data, action: self::INLINE_ACTION);
		} catch (InvalidTransitionInputException $refused) {
			throw self::refusal(refused: $refused, declared: $declared);
		}
	}//end allowlist()

	/**
	 * The transition path: allowlist, merge and lifecycle flip in one save.
	 *
	 * @param TaskSubjectTransition $target The subject object and the lifecycle action.
	 * @param array<string, mixed> $data The submitted values.
	 *
	 * @return void
	 *
	 * @throws TaskFormRefusedException When the allowlist refuses.
	 * @throws TaskAccessDeniedException When the caller may not transition the object.
	 * @throws TaskSubjectWriteRefusedException When the schema or the lifecycle refuses.
	 *
	 * @spec openspec/specs/flow-task-forms/spec.md#requirement-a-completion-payload-is-validated-by-the-lifecycle-input-allowlist-and-by-nothing-else
	 */
	private function transition(TaskSubjectTransition $target, array $data): void {
		try {
			$this->engine->transition(objectId: $target->objectUuid, action: $target->action, data: $data);
		} catch (InvalidTransitionInputException $refused) {
			// The engine's kind is not recoverable from its declaration here,
			// so read it off the message the same method wrote.
			throw self::refusal(refused: $refused, declared: null);
		} catch (NotAuthorizedException $denied) {
			throw new TaskAccessDeniedException(message: $denied->getMessage());
		} catch (LifecycleSubjectNotFoundException $missing) {
			throw self::subjectNotFound(missing: $missing);
		} catch (HookStoppedException | ValidationException | CustomValidationException | RuntimeException $refused) {
			throw new TaskSubjectWriteRefusedException(message: $refused->getMessage(), previous: $refused);
		}
	}//end transition()

	/**
	 * The task-side answer to the engine's "subject not found".
	 *
	 * The engine reports a subject the caller may not READ as "not found",
	 * so the object endpoints do not leak its existence. The task endpoint
	 * has already shown this performer the task, so it keeps answering a
	 * read denial as the access denial it was.
	 *
	 * @param LifecycleSubjectNotFoundException $missing The engine's report.
	 *
	 * @return TaskAccessDeniedException|TaskSubjectWriteRefusedException The translated exception.
	 */
	private static function subjectNotFound(LifecycleSubjectNotFoundException $missing): TaskAccessDeniedException|TaskSubjectWriteRefusedException {
		$cause = $missing->getPrevious();
		if ($cause instanceof NotAuthorizedException) {
			return new TaskAccessDeniedException(message: $cause->getMessage());
		}

		return new TaskSubjectWriteRefusedException(message: $missing->getMessage(), previous: $missing);
	}//end subjectNotFound()

	/**
	 * The task-side 400 for an allowlist refusal, its kind derived rather than guessed.
	 *
	 * @param InvalidTransitionInputException $refused The engine's refusal.
	 * @param array<int, array{field: string, required: bool}>|null $declared The declaration, when this class holds it.
	 *
	 * @return TaskFormRefusedException The translated refusal.
	 */
	private static function refusal(InvalidTransitionInputException $refused, ?array $declared): TaskFormRefusedException {
		return new TaskFormRefusedException(
			message: $refused->getMessage(),
			kind: self::refusalKind(refused: $refused, declared: $declared),
			fields: $refused->getFields()
		);
	}//end refusal()

	/**
	 * Whether an allowlist refusal names missing or undeclared fields.
	 *
	 * @param InvalidTransitionInputException $refused The engine's refusal.
	 * @param array<int, array{field: string, required: bool}>|null $declared The declaration, when known.
	 *
	 * @return string One of the TaskFormRefusedException kinds.
	 */
	private static function refusalKind(InvalidTransitionInputException $refused, ?array $declared): string {
		if ($declared !== null) {
			return self::declaredKind(fields: $refused->getFields(), declared: $declared);
		}

		if (str_contains($refused->getMessage(), 'missing required') === true) {
			return TaskFormRefusedException::KIND_MISSING;
		}

		return TaskFormRefusedException::KIND_UNDECLARED;
	}//end refusalKind()

	/**
	 * Missing when every refused field was declared, undeclared otherwise.
	 *
	 * @param array<int, string> $fields The refused fields.
	 * @param array<int, array{field: string, required: bool}> $declared The declaration.
	 *
	 * @return string One of the TaskFormRefusedException kinds.
	 */
	private static function declaredKind(array $fields, array $declared): string {
		if ($fields !== [] && array_diff($fields, array_column($declared, 'field')) === []) {
			return TaskFormRefusedException::KIND_MISSING;
		}

		return TaskFormRefusedException::KIND_UNDECLARED;
	}//end declaredKind()
}//end class
