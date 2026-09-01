<?php

/**
 * Completing with a payload: the allowlist, the write, the order, the refusals.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/flow-task-forms/specs/flow-task-forms/spec.md#requirement-a-completion-payload-is-validated-by-the-lifecycle-input-allowlist-and-by-nothing-else
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Exception\InvalidTransitionInputException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\TaskAccessDeniedException;
use OCA\OpenRegister\Exception\TaskFormRefusedException;
use OCA\OpenRegister\Exception\TaskSubjectWriteRefusedException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Task\TaskFormCompletion;
use OCA\OpenRegister\Service\Task\TaskFormResolver;
use OCA\OpenRegister\Service\Task\TaskService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The completion path with a form payload.
 *
 * @covers \OCA\OpenRegister\Service\Task\TaskFormCompletion
 * @covers \OCA\OpenRegister\Exception\TaskFormRefusedException
 * @covers \OCA\OpenRegister\Exception\TaskSubjectWriteRefusedException
 */
class TaskFormCompletionTest extends TestCase {

	private TaskService&MockObject $tasks;

	private TaskFormResolver&MockObject $forms;

	private TransitionEngine&MockObject $engine;

	private ObjectService&MockObject $objects;

	private TaskFormCompletion $completion;

	/**
	 * The order in which collaborators were called, by name.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	protected function setUp(): void {
		$this->tasks = $this->createMock(TaskService::class);
		$this->forms = $this->createMock(TaskFormResolver::class);
		$this->engine = $this->createMock(TransitionEngine::class);
		$this->objects = $this->createMock(ObjectService::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->completion = new TaskFormCompletion(
			tasks: $this->tasks,
			forms: $this->forms,
			engine: $this->engine,
			objects: $this->objects,
			userSession: $session
		);
	}//end setUp()

	/**
	 * An open task anchored to subject `obj-1`.
	 */
	private function task(): Task {
		$task = new Task();
		$task->setId(7);
		$task->setUuid('t-7');
		$task->setState(Task::STATE_ACTIVE);
		$task->setAssignee('alice');
		$task->setObjectUuid('obj-1');

		return $task;
	}//end task()

	/**
	 * A resolved native form: `reason` required, `note` optional, with or without an action.
	 *
	 * @return array{form: array<string, mixed>, requireChecklist: bool}
	 */
	private function nativeForm(?string $action = null, bool $requireChecklist = false): array {
		return [
			'form' => [
				'kind' => 'fields',
				'state' => TaskFormResolver::STATE_READY,
				'error' => null,
				'schema' => ['id' => 5, 'uuid' => null, 'slug' => 'case', 'title' => 'Case'],
				'action' => $action,
				'fields' => [
					['field' => 'reason', 'required' => true, 'order' => 0, 'renderable' => true, 'reason' => null],
					['field' => 'note', 'required' => false, 'order' => 1, 'renderable' => true, 'reason' => null],
				],
			],
			'requireChecklist' => $requireChecklist,
		];
	}//end nativeForm()

	/**
	 * The task service authorizes and returns the open task, and records each call's order.
	 */
	private function openTask(Task $task): void {
		$this->tasks->method('authorizedOpenTask')->with('complete', 't-7', 'alice')->willReturnCallback(
			function () use ($task): Task {
				$this->calls[] = 'authorize';

				return $task;
			}
		);
		$this->tasks->method('complete')->willReturnCallback(
			function () use ($task): Task {
				$this->calls[] = 'complete';

				return $task;
			}
		);
	}//end openTask()

	/**
	 * A step with no form completes with an outcome alone, and no field validation is applied.
	 */
	public function testAFormlessTaskCompletesWithoutAnyFieldValidation(): void {
		$this->openTask($this->task());
		$this->forms->method('describe')->willReturn(['form' => null, 'requireChecklist' => false]);
		$this->engine->expects($this->never())->method('resolveTransitionInputs');
		$this->engine->expects($this->never())->method('transition');
		$this->objects->expects($this->never())->method('saveObject');
		$this->tasks->expects($this->once())->method('complete')->with('t-7', 'approved', null, 'fine', 'alice');

		$this->completion->complete(uuid: 't-7', outcome: 'approved', resultText: null, comment: 'fine', data: [], actor: 'alice');
	}//end testAFormlessTaskCompletesWithoutAnyFieldValidation()

	/**
	 * A step declaring no fields accepts no field values: the allowlist runs with an
	 * empty declaration and refuses the key, the task is not completed, the attempt is audited.
	 */
	public function testAFormlessTaskRefusesAnyFieldValue(): void {
		$this->openTask($this->task());
		$this->forms->method('describe')->willReturn(['form' => null, 'requireChecklist' => false]);
		$this->engine->expects($this->once())->method('resolveTransitionInputs')
			->with([], ['reason' => 'x'], 'complete')
			->willThrowException(new InvalidTransitionInputException('Transition "complete" does not accept input field(s): "reason".', ['reason']));
		$this->tasks->expects($this->never())->method('complete');
		$this->tasks->expects($this->once())->method('recordRefusedCompletion')->with($this->anything(), $this->stringContains('reason'), 'alice');

		try {
			$this->completion->complete(uuid: 't-7', outcome: 'approved', resultText: null, comment: null, data: ['reason' => 'x'], actor: 'alice');
			$this->fail('Expected a refusal.');
		} catch (TaskFormRefusedException $refused) {
			$this->assertSame(TaskFormRefusedException::KIND_UNDECLARED, $refused->getKind());
			$this->assertSame(['reason'], $refused->getFields());
		}
	}//end testAFormlessTaskRefusesAnyFieldValue()

	/**
	 * A missing required field is named, with the kind derived from the declaration.
	 */
	public function testAMissingRequiredFieldIsNamedAsMissing(): void {
		$this->openTask($this->task());
		$this->forms->method('describe')->willReturn($this->nativeForm());
		$this->engine->method('resolveTransitionInputs')
			->with([['field' => 'reason', 'required' => true], ['field' => 'note', 'required' => false]], ['note' => 'n'], 'complete')
			->willThrowException(new InvalidTransitionInputException('Transition "complete" is missing required input field(s): "reason".', ['reason']));
		$this->objects->expects($this->never())->method('saveObject');
		$this->tasks->expects($this->never())->method('complete');

		try {
			$this->completion->complete(uuid: 't-7', outcome: 'approved', resultText: null, comment: null, data: ['note' => 'n'], actor: 'alice');
			$this->fail('Expected a refusal.');
		} catch (TaskFormRefusedException $refused) {
			$this->assertSame(TaskFormRefusedException::KIND_MISSING, $refused->getKind());
			$this->assertSame(['reason'], $refused->getFields());
		}
	}//end testAMissingRequiredFieldIsNamedAsMissing()

	/**
	 * An undeclared key against a declaring form is named as undeclared.
	 */
	public function testAnUndeclaredKeyIsNamedAsUndeclared(): void {
		$this->openTask($this->task());
		$this->forms->method('describe')->willReturn($this->nativeForm());
		$this->engine->method('resolveTransitionInputs')
			->willThrowException(new InvalidTransitionInputException('Transition "complete" does not accept input field(s): "extra".', ['extra']));

		try {
			$this->completion->complete(uuid: 't-7', outcome: 'approved', resultText: null, comment: null, data: ['reason' => 'r', 'extra' => 1], actor: 'alice');
			$this->fail('Expected a refusal.');
		} catch (TaskFormRefusedException $refused) {
			$this->assertSame(TaskFormRefusedException::KIND_UNDECLARED, $refused->getKind());
			$this->assertSame(['extra'], $refused->getFields());
		}
	}//end testAnUndeclaredKeyIsNamedAsUndeclared()

	/**
	 * The inline path: accepted values merge into the subject through the ordinary
	 * object write, as the acting user, BEFORE the task completes.
	 */
	public function testAnInlineFormWritesTheSubjectBeforeCompleting(): void {
		$this->openTask($this->task());
		$this->forms->method('describe')->willReturn($this->nativeForm());
		$this->engine->method('resolveTransitionInputs')->willReturn(['reason' => 'late']);
		$subject = new ObjectEntity();
		$subject->setUuid('obj-1');
		$subject->setRegister('1');
		$subject->setSchema('5');
		$subject->setObject(['name' => 'Case 7', 'reason' => null]);
		$this->objects->method('find')->with('obj-1')->willReturn($subject);
		$this->objects->expects($this->once())->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend, mixed $register, mixed $schema, ?string $uuid, bool $_rbac, bool $_multitenancy, bool $silent, bool $_validation, ?array $uploadedFiles, ?IUser $currentUser) use ($subject): ObjectEntity {
				$this->calls[] = 'save';
				// getObject() carries the entity's own id alongside the record,
				// so assert the record's content rather than the exact array.
				$this->assertSame('Case 7', $object['name']);
				$this->assertSame('late', $object['reason']);
				$this->assertSame('1', $register);
				$this->assertSame('5', $schema);
				$this->assertSame('obj-1', $uuid);
				$this->assertSame('alice', $currentUser?->getUID());

				return $subject;
			}
		);
		$this->engine->expects($this->never())->method('transition');

		$this->completion->complete(uuid: 't-7', outcome: 'approved', resultText: null, comment: null, data: ['reason' => 'late'], actor: 'alice');

		$this->assertSame(['authorize', 'save', 'complete'], $this->calls);
	}//end testAnInlineFormWritesTheSubjectBeforeCompleting()

	/**
	 * Nothing accepted, nothing written: an all-optional form left empty skips the write.
	 */
	public function testAnEmptyAcceptedPayloadWritesNothing(): void {
		$this->openTask($this->task());
		$this->forms->method('describe')->willReturn($this->nativeForm());
		$this->engine->method('resolveTransitionInputs')->willReturn([]);
		$this->objects->expects($this->never())->method('find');
		$this->objects->expects($this->never())->method('saveObject');

		$this->completion->complete(uuid: 't-7', outcome: 'approved', resultText: null, comment: null, data: [], actor: 'alice');

		$this->assertSame(['authorize', 'complete'], $this->calls);
	}//end testAnEmptyAcceptedPayloadWritesNothing()

	/**
	 * The action path: ONE engine call carries allowlist, merge and lifecycle flip, then the task completes.
	 */
	public function testAnActionFormTransitionsTheSubjectBeforeCompleting(): void {
		$this->openTask($this->task());
		$this->forms->method('describe')->willReturn($this->nativeForm(action: 'reject'));
		$this->engine->expects($this->once())->method('transition')->with('obj-1', 'reject', ['reason' => 'late'])->willReturnCallback(
			function (): ObjectEntity {
				$this->calls[] = 'transition';

				return new ObjectEntity();
			}
		);
		$this->engine->expects($this->never())->method('resolveTransitionInputs');
		$this->objects->expects($this->never())->method('saveObject');

		$this->completion->complete(uuid: 't-7', outcome: 'rejected', resultText: null, comment: 'late', data: ['reason' => 'late'], actor: 'alice');

		$this->assertSame(['authorize', 'transition', 'complete'], $this->calls);
	}//end testAnActionFormTransitionsTheSubjectBeforeCompleting()

	/**
	 * A value the schema refuses is refused by the save path: 422, task not completed, attempt audited.
	 */
	public function testASchemaRefusalLeavesTheTaskOpen(): void {
		$this->openTask($this->task());
		$this->forms->method('describe')->willReturn($this->nativeForm(action: 'reject'));
		$this->engine->method('transition')->willThrowException(new HookStoppedException('reason must be one of: late, incomplete'));
		$this->tasks->expects($this->never())->method('complete');
		$this->tasks->expects($this->once())->method('recordRefusedCompletion');

		$this->expectException(TaskSubjectWriteRefusedException::class);
		$this->expectExceptionMessage('reason must be one of');
		$this->completion->complete(uuid: 't-7', outcome: 'rejected', resultText: null, comment: 'x', data: ['reason' => 'other'], actor: 'alice');
	}//end testASchemaRefusalLeavesTheTaskOpen()

	/**
	 * The inline path's save can be refused by the schema too, the same way.
	 */
	public function testAnInlineSaveRefusalLeavesTheTaskOpen(): void {
		$this->openTask($this->task());
		$this->forms->method('describe')->willReturn($this->nativeForm());
		$this->engine->method('resolveTransitionInputs')->willReturn(['reason' => 7]);
		$subject = new ObjectEntity();
		$subject->setUuid('obj-1');
		$this->objects->method('find')->willReturn($subject);
		$this->objects->method('saveObject')->willThrowException(new ValidationException('reason must be a string'));
		$this->tasks->expects($this->never())->method('complete');

		$this->expectException(TaskSubjectWriteRefusedException::class);
		$this->completion->complete(uuid: 't-7', outcome: 'approved', resultText: null, comment: null, data: ['reason' => 7], actor: 'alice');
	}//end testAnInlineSaveRefusalLeavesTheTaskOpen()

	/**
	 * The action's own refusal from the current state is the subject's refusal, not a malformed payload.
	 */
	public function testATransitionRefusedFromTheCurrentStateIs422NotMalformed(): void {
		$this->openTask($this->task());
		$this->forms->method('describe')->willReturn($this->nativeForm(action: 'reject'));
		$this->engine->method('transition')->willThrowException(new \RuntimeException('Transition "reject" is not allowed from current state "closed".'));

		$this->expectException(TaskSubjectWriteRefusedException::class);
		$this->completion->complete(uuid: 't-7', outcome: 'rejected', resultText: null, comment: 'x', data: ['reason' => 'late'], actor: 'alice');
	}//end testATransitionRefusedFromTheCurrentStateIs422NotMalformed()

	/**
	 * Being the assignee grants no write on the subject: the object write's denial stands, unaudited as a refusal.
	 */
	public function testTheObjectWritesAuthorizationMayRefuse(): void {
		$this->openTask($this->task());
		$this->forms->method('describe')->willReturn($this->nativeForm(action: 'reject'));
		$this->engine->method('transition')->willThrowException(new NotAuthorizedException('You do not have permission to transition object "obj-1".'));
		$this->tasks->expects($this->never())->method('complete');
		$this->tasks->expects($this->never())->method('recordRefusedCompletion');

		$this->expectException(TaskAccessDeniedException::class);
		$this->expectExceptionMessage('obj-1');
		$this->completion->complete(uuid: 't-7', outcome: 'rejected', resultText: null, comment: 'x', data: ['reason' => 'late'], actor: 'alice');
	}//end testTheObjectWritesAuthorizationMayRefuse()

	/**
	 * The task verb's authorization is settled FIRST: a denial reaches no resolver and no write.
	 */
	public function testTheTaskVerbIsAuthorizedBeforeAnythingElse(): void {
		$this->tasks->method('authorizedOpenTask')->willThrowException(new TaskAccessDeniedException('not the assignee'));
		$this->forms->expects($this->never())->method('describe');
		$this->engine->expects($this->never())->method('transition');

		$this->expectException(TaskAccessDeniedException::class);
		$this->completion->complete(uuid: 't-7', outcome: 'approved', resultText: null, comment: null, data: ['reason' => 'late'], actor: 'mallory');
	}//end testTheTaskVerbIsAuthorizedBeforeAnythingElse()

	/**
	 * An unresolvable form completes nothing: no empty form is completable.
	 */
	public function testAnUnresolvableFormRefusesTheCompletion(): void {
		$this->openTask($this->task());
		$this->forms->method('describe')->willReturn(
			[
				'form' => ['kind' => null, 'state' => TaskFormResolver::STATE_UNRESOLVABLE, 'error' => 'Version 7 of flow flow-1 cannot be resolved'],
				'requireChecklist' => false,
			]
		);
		$this->tasks->expects($this->never())->method('complete');

		try {
			$this->completion->complete(uuid: 't-7', outcome: 'approved', resultText: null, comment: null, data: [], actor: 'alice');
			$this->fail('Expected a refusal.');
		} catch (TaskFormRefusedException $refused) {
			$this->assertSame(TaskFormRefusedException::KIND_UNRESOLVABLE, $refused->getKind());
			$this->assertStringContainsString('Version 7 of flow flow-1', $refused->getMessage());
		}
	}//end testAnUnresolvableFormRefusesTheCompletion()

	/**
	 * An unchecked mandatory checklist item refuses the completion naming the item,
	 * before any write; the checklist never enters the field payload.
	 */
	public function testAnUncheckedMandatoryItemRefusesNamingIt(): void {
		$task = $this->task();
		$task->setChecklist(
			[
				['id' => 'c1', 'label' => 'Identity verified', 'checked' => true],
				['id' => 'c2', 'label' => 'Documents scanned', 'checked' => false],
			]
		);
		$this->openTask($task);
		$this->forms->method('describe')->willReturn($this->nativeForm(action: 'reject', requireChecklist: true));
		$this->engine->expects($this->never())->method('transition');
		$this->tasks->expects($this->never())->method('complete');

		try {
			$this->completion->complete(uuid: 't-7', outcome: 'rejected', resultText: null, comment: 'x', data: ['reason' => 'late'], actor: 'alice');
			$this->fail('Expected a refusal.');
		} catch (TaskFormRefusedException $refused) {
			$this->assertSame(TaskFormRefusedException::KIND_CHECKLIST, $refused->getKind());
			$this->assertSame(['c2'], $refused->getFields());
		}
	}//end testAnUncheckedMandatoryItemRefusesNamingIt()

	/**
	 * With every item checked the precondition is satisfied and the write proceeds.
	 */
	public function testACompleteChecklistSatisfiesThePrecondition(): void {
		$task = $this->task();
		$task->setChecklist([['id' => 'c1', 'label' => 'Identity verified', 'checked' => true]]);
		$this->openTask($task);
		$this->forms->method('describe')->willReturn(['form' => null, 'requireChecklist' => true]);
		$this->tasks->expects($this->once())->method('complete');

		$this->completion->complete(uuid: 't-7', outcome: 'approved', resultText: null, comment: null, data: [], actor: 'alice');
	}//end testACompleteChecklistSatisfiesThePrecondition()

	/**
	 * A field form on a task with no subject has nowhere to write: refused, named.
	 */
	public function testAFieldFormWithoutASubjectIsRefused(): void {
		$task = $this->task();
		$task->setObjectUuid(null);
		$this->openTask($task);
		$this->forms->method('describe')->willReturn($this->nativeForm());

		try {
			$this->completion->complete(uuid: 't-7', outcome: 'approved', resultText: null, comment: null, data: ['reason' => 'r'], actor: 'alice');
			$this->fail('Expected a refusal.');
		} catch (TaskFormRefusedException $refused) {
			$this->assertSame(TaskFormRefusedException::KIND_NO_SUBJECT, $refused->getKind());
		}
	}//end testAFieldFormWithoutASubjectIsRefused()

	/**
	 * An external form writes no object field by this capability; the completion records the outcome.
	 */
	public function testAnExternalFormWritesNoObjectField(): void {
		$this->openTask($this->task());
		$this->forms->method('describe')->willReturn(
			['form' => ['kind' => 'external', 'state' => TaskFormResolver::STATE_READY, 'formId' => 9], 'requireChecklist' => false]
		);
		$this->engine->expects($this->never())->method('transition');
		$this->objects->expects($this->never())->method('saveObject');
		$this->tasks->expects($this->once())->method('complete')->with('t-7', 'submitted', 'submission 41', null, 'alice');

		$this->completion->complete(uuid: 't-7', outcome: 'submitted', resultText: 'submission 41', comment: null, data: [], actor: 'alice');
	}//end testAnExternalFormWritesNoObjectField()
}//end class
