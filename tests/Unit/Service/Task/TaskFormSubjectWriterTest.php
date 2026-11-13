<?php

/**
 * TaskFormSubjectWriter and TaskFormInlineSave: where a task form's values go
 * (nowhere, through a lifecycle transition, or merged onto the subject), and how
 * every refusal on the way is translated for the task API.
 *
 * TaskFormCompletionTest exercises these through the completion service; this
 * file pins each branch of the two classes directly.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Task
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed mock fixtures; the declaration IS the description.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Flow\FlowRunFrame;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Exception\InvalidTransitionInputException;
use OCA\OpenRegister\Exception\LifecycleSubjectNotFoundException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\TaskAccessDeniedException;
use OCA\OpenRegister\Exception\TaskFormRefusedException;
use OCA\OpenRegister\Exception\TaskSubjectWriteRefusedException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\Task\TaskFormInlineSave;
use OCA\OpenRegister\Service\Task\TaskFormSubjectWriter;
use OCA\OpenRegister\Service\Task\TaskFormSubmission;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;

/**
 * @covers \OCA\OpenRegister\Service\Task\TaskFormSubjectWriter
 * @covers \OCA\OpenRegister\Service\Task\TaskFormInlineSave
 * @uses \OCA\OpenRegister\Service\Task\TaskFormSubmission
 * @uses \OCA\OpenRegister\Service\Task\TaskSubjectTransition
 */
class TaskFormSubjectWriterTest extends TestCase {

	private TransitionEngine&MockObject $engine;
	private ObjectOperationsInterface&MockObject $objects;
	private FlowRunContext&MockObject $runContext;
	private IUser&MockObject $user;
	private TaskFormSubjectWriter $writer;

	protected function setUp(): void {
		parent::setUp();
		$this->engine = $this->createMock(TransitionEngine::class);
		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->runContext = $this->createMock(FlowRunContext::class);
		$this->user = $this->createMock(IUser::class);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->user);
		$this->writer = new TaskFormSubjectWriter(
			$this->engine,
			new TaskFormInlineSave($this->objects, $session, $this->runContext)
		);
	}

	private static function task(?string $objectUuid = 'obj-1', ?string $runUuid = 'run-1'): Task {
		$task = new Task();
		$task->setObjectUuid($objectUuid);
		$task->setRunUuid($runUuid);
		return $task;
	}

	private static function subject(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setRegister('5');
		$object->setSchema('6');
		$object->setObject(['title' => 'Old', 'keep' => 1]);
		return $object;
	}

	private static function fieldForm(?string $action = null): array {
		$form = ['kind' => 'fields', 'fields' => [['field' => 'title', 'required' => true], ['field' => 'note', 'required' => 0]]];
		if ($action !== null) {
			$form['action'] = $action;
		}

		return $form;
	}

	private function refusalOf(callable $call): Throwable {
		try {
			$call();
		} catch (Throwable $thrown) {
			return $thrown;
		}

		$this->fail('Expected the write to be refused.');
	}

	public function testAFormlessTaskWithAnEmptyPayloadTouchesNothing(): void {
		$this->engine->expects($this->never())->method('resolveTransitionInputs');
		$this->objects->expects($this->never())->method('saveObject');

		$this->writer->write(new TaskFormSubmission(self::task(), [], null), null);
	}

	public function testAnExternalFormRunsAnyValuesThroughAnEmptyAllowlist(): void {
		$this->engine->expects($this->once())->method('resolveTransitionInputs')
			->with([], ['x' => 1], 'complete')
			->willThrowException(new InvalidTransitionInputException('undeclared input', ['x']));
		$this->objects->expects($this->never())->method('saveObject');

		$refused = $this->refusalOf(fn () => $this->writer->write(new TaskFormSubmission(self::task(), ['x' => 1], null), ['kind' => 'external']));

		$this->assertInstanceOf(TaskFormRefusedException::class, $refused);
		$this->assertSame(TaskFormRefusedException::KIND_UNDECLARED, $refused->getKind());
		$this->assertSame(['x'], $refused->getFields());
	}

	public function testAFieldFormWithoutASubjectIsRefused(): void {
		$this->engine->expects($this->never())->method('resolveTransitionInputs');

		$refused = $this->refusalOf(fn () => $this->writer->write(new TaskFormSubmission(self::task(' '), ['title' => 'New'], null), self::fieldForm()));

		$this->assertInstanceOf(TaskFormRefusedException::class, $refused);
		$this->assertSame(TaskFormRefusedException::KIND_NO_SUBJECT, $refused->getKind());
	}

	public function testAnInlineFormMergesTheAcceptedValuesOntoTheSubjectAsTheRun(): void {
		$this->engine->expects($this->once())->method('resolveTransitionInputs')
			->with([['field' => 'title', 'required' => true], ['field' => 'note', 'required' => false]], ['title' => 'New'], 'complete')
			->willReturn(['title' => 'New']);
		$this->objects->expects($this->once())->method('find')->with('obj-1')->willReturn(self::subject());
		$this->objects->expects($this->once())->method('saveObject')
			->with(['title' => 'New', 'keep' => 1, 'id' => 'obj-1'], $this->callback(fn (ObjectSaveOptions $options): bool => $options->extend == [] && $options->target->register == '5' && $options->target->schema == '6' && $options->uuid == 'obj-1' && $options->policy->silent === false && $options->policy->validation === true && $options->uploadedFiles === null && $options->actor->currentUser == $this->user), true, true)
			->willReturn(self::subject());
		$this->runContext->expects($this->once())->method('push')->with(new FlowRunFrame('run-1', 'task-form', 0));
		$this->runContext->expects($this->once())->method('pop');

		$this->writer->write(new TaskFormSubmission(self::task(), ['title' => 'New'], null), self::fieldForm());
	}

	public function testAnInlineSaveWithoutARunPushesNoRunUuid(): void {
		$this->engine->method('resolveTransitionInputs')->willReturn(['title' => 'New']);
		$this->objects->method('find')->willReturn(self::subject());
		$this->runContext->expects($this->once())->method('push')->with(new FlowRunFrame(null, 'task-form', 0));

		$this->writer->write(new TaskFormSubmission(self::task('obj-1', '  '), ['title' => 'New'], null), self::fieldForm());
	}

	public function testNothingAcceptedMeansNothingWritten(): void {
		$this->engine->method('resolveTransitionInputs')->willReturn([]);
		$this->objects->expects($this->never())->method('find');
		$this->objects->expects($this->never())->method('saveObject');

		$this->writer->write(new TaskFormSubmission(self::task(), [], null), self::fieldForm());
	}

	public function testAMissingDeclaredFieldIsAMissingRefusal(): void {
		$this->engine->method('resolveTransitionInputs')->willThrowException(new InvalidTransitionInputException('nope', ['title']));

		$refused = $this->refusalOf(fn () => $this->writer->write(new TaskFormSubmission(self::task(), [], null), self::fieldForm()));

		$this->assertSame(TaskFormRefusedException::KIND_MISSING, $refused->getKind());
	}

	public function testAnUndeclaredFieldOnAFieldFormIsAnUndeclaredRefusal(): void {
		$this->engine->method('resolveTransitionInputs')->willThrowException(new InvalidTransitionInputException('nope', ['title', 'rogue']));

		$refused = $this->refusalOf(fn () => $this->writer->write(new TaskFormSubmission(self::task(), ['rogue' => 1], null), self::fieldForm()));

		$this->assertSame(TaskFormRefusedException::KIND_UNDECLARED, $refused->getKind());
	}

	public function testARefusalNamingNoFieldIsUndeclared(): void {
		$this->engine->method('resolveTransitionInputs')->willThrowException(new InvalidTransitionInputException('nope', []));

		$refused = $this->refusalOf(fn () => $this->writer->write(new TaskFormSubmission(self::task(), [], null), self::fieldForm()));

		$this->assertSame(TaskFormRefusedException::KIND_UNDECLARED, $refused->getKind());
	}

	public function testAGoneSubjectIsAWriteRefusal(): void {
		$this->engine->method('resolveTransitionInputs')->willReturn(['title' => 'New']);
		$this->objects->method('find')->willReturn(null);
		$this->runContext->expects($this->never())->method('push');

		$refused = $this->refusalOf(fn () => $this->writer->write(new TaskFormSubmission(self::task(), ['title' => 'New'], null), self::fieldForm()));

		$this->assertInstanceOf(TaskSubjectWriteRefusedException::class, $refused);
		$this->assertStringContainsString('"obj-1" no longer exists', $refused->getMessage());
	}

	public function testAnUnauthorizedInlineSaveIsAnAccessDenialAndStillPopsTheRun(): void {
		$this->engine->method('resolveTransitionInputs')->willReturn(['title' => 'New']);
		$this->objects->method('find')->willReturn(self::subject());
		$this->objects->method('saveObject')->willThrowException(new NotAuthorizedException('no write'));
		$this->runContext->expects($this->once())->method('pop');

		$refused = $this->refusalOf(fn () => $this->writer->write(new TaskFormSubmission(self::task(), ['title' => 'New'], null), self::fieldForm()));

		$this->assertInstanceOf(TaskAccessDeniedException::class, $refused);
		$this->assertSame('no write', $refused->getMessage());
	}

	public function testASchemaRefusalOfTheInlineSaveIsAWriteRefusal(): void {
		$this->engine->method('resolveTransitionInputs')->willReturn(['title' => 'New']);
		$this->objects->method('find')->willReturn(self::subject());
		$cause = new ValidationException('title too long');
		$this->objects->method('saveObject')->willThrowException($cause);

		$refused = $this->refusalOf(fn () => $this->writer->write(new TaskFormSubmission(self::task(), ['title' => 'New'], null), self::fieldForm()));

		$this->assertInstanceOf(TaskSubjectWriteRefusedException::class, $refused);
		$this->assertSame($cause, $refused->getPrevious());
	}

	public function testAnActionFormTransitionsTheSubjectInsteadOfSavingIt(): void {
		$this->engine->expects($this->once())->method('transition')->with('obj-1', 'approve', ['title' => 'New'])->willReturn(self::subject());
		$this->engine->expects($this->never())->method('resolveTransitionInputs');
		$this->objects->expects($this->never())->method('saveObject');

		$this->writer->write(new TaskFormSubmission(self::task(), ['title' => 'New'], null), self::fieldForm('approve'));
	}

	public function testAnEmptyActionFallsBackToTheInlineSave(): void {
		$this->engine->expects($this->never())->method('transition');
		$this->engine->expects($this->once())->method('resolveTransitionInputs')->willReturn([]);

		$this->writer->write(new TaskFormSubmission(self::task(), [], null), self::fieldForm(''));
	}

	public function testATransitionInputRefusalIsKindedByItsMessage(): void {
		$this->engine->method('transition')->willThrowException(new InvalidTransitionInputException('Transition is missing required input: title', ['title']));
		$missing = $this->refusalOf(fn () => $this->writer->write(new TaskFormSubmission(self::task(), [], null), self::fieldForm('approve')));

		$this->assertSame(TaskFormRefusedException::KIND_MISSING, $missing->getKind());
	}

	public function testATransitionInputRefusalWithoutMissingIsUndeclared(): void {
		$this->engine->method('transition')->willThrowException(new InvalidTransitionInputException('Undeclared input: rogue', ['rogue']));
		$undeclared = $this->refusalOf(fn () => $this->writer->write(new TaskFormSubmission(self::task(), ['rogue' => 1], null), self::fieldForm('approve')));

		$this->assertSame(TaskFormRefusedException::KIND_UNDECLARED, $undeclared->getKind());
	}

	public function testAnUnauthorizedTransitionIsAnAccessDenial(): void {
		$this->engine->method('transition')->willThrowException(new NotAuthorizedException('not yours'));

		$refused = $this->refusalOf(fn () => $this->writer->write(new TaskFormSubmission(self::task(), [], null), self::fieldForm('approve')));

		$this->assertInstanceOf(TaskAccessDeniedException::class, $refused);
		$this->assertSame('not yours', $refused->getMessage());
	}

	public function testASubjectHiddenByAuthorizationStaysAnAccessDenial(): void {
		$this->engine->method('transition')->willThrowException(
			new LifecycleSubjectNotFoundException('not found', 404, new NotAuthorizedException('hidden'))
		);

		$refused = $this->refusalOf(fn () => $this->writer->write(new TaskFormSubmission(self::task(), [], null), self::fieldForm('approve')));

		$this->assertInstanceOf(TaskAccessDeniedException::class, $refused);
		$this->assertSame('hidden', $refused->getMessage());
	}

	public function testAGenuinelyMissingSubjectIsAWriteRefusal(): void {
		$missing = new LifecycleSubjectNotFoundException('gone');
		$this->engine->method('transition')->willThrowException($missing);

		$refused = $this->refusalOf(fn () => $this->writer->write(new TaskFormSubmission(self::task(), [], null), self::fieldForm('approve')));

		$this->assertInstanceOf(TaskSubjectWriteRefusedException::class, $refused);
		$this->assertSame($missing, $refused->getPrevious());
	}

	public function testAHookOrRuntimeFailureOfTheTransitionIsAWriteRefusal(): void {
		foreach ([new HookStoppedException('stopped'), new RuntimeException('Transition not allowed from state')] as $cause) {
			$engine = $this->createMock(TransitionEngine::class);
			$engine->method('transition')->willThrowException($cause);
			$writer = new TaskFormSubjectWriter($engine, new TaskFormInlineSave($this->objects, $this->createMock(IUserSession::class)));

			$refused = $this->refusalOf(fn () => $writer->write(new TaskFormSubmission(self::task(), [], null), self::fieldForm('approve')));

			$this->assertInstanceOf(TaskSubjectWriteRefusedException::class, $refused);
			$this->assertSame($cause, $refused->getPrevious());
		}
	}
}
