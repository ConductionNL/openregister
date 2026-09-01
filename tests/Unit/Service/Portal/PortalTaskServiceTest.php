<?php

/**
 * The completion path's order and refusals, and the subject-scoped read.
 *
 * The assertions here are about ORDER as much as outcome: a stranger is
 * refused before a byte is stored, a constraint violation is refused before
 * a byte is stored, and the file is stored BEFORE the completion is recorded.
 * The case-edit regression is here too: the party is read from the task's
 * STORED reference, so changing the case moves nothing.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Portal
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Portal;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Exception\TaskAccessDeniedException;
use OCA\OpenRegister\Exception\TaskValidationException;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Portal\PortalSubject;
use OCA\OpenRegister\Service\Portal\PortalTaskService;
use OCA\OpenRegister\Service\Task\TaskAuthorizationService;
use OCA\OpenRegister\Service\Task\TaskInboxService;
use OCA\OpenRegister\Service\Task\TaskService;
use OCA\OpenRegister\Service\Task\TaskTemporalProjection;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for {@see PortalTaskService}.
 *
 * @covers \OCA\OpenRegister\Service\Portal\PortalTaskService
 * @covers \OCA\OpenRegister\Service\Portal\PortalSubject
 * @covers \OCA\OpenRegister\Service\Task\TaskAuthorizationService
 * @covers \OCA\OpenRegister\Db\Task
 */
class PortalTaskServiceTest extends TestCase {

	/**
	 * The lifecycle, mocked.
	 *
	 * @var TaskService&MockObject
	 */
	private TaskService&MockObject $tasks;

	/**
	 * The mapper, mocked.
	 *
	 * @var TaskMapper&MockObject
	 */
	private TaskMapper&MockObject $mapper;

	/**
	 * The file service, mocked.
	 *
	 * @var FileService&MockObject
	 */
	private FileService&MockObject $files;

	/**
	 * The service under test.
	 *
	 * @var PortalTaskService
	 */
	private PortalTaskService $service;

	/**
	 * What happened, in order.
	 *
	 * @var array<int, string>
	 */
	private array $trace = [];

	/**
	 * Build the service over mocks; `openFor` runs the REAL authorization
	 * rule so the party comparison under test is the production one.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->tasks = $this->createMock(TaskService::class);
		$this->mapper = $this->createMock(TaskMapper::class);
		$this->files = $this->createMock(FileService::class);
		$inbox = new TaskInboxService(tasks: $this->mapper, temporal: new TaskTemporalProjection(), logger: new NullLogger());
		$this->service = new PortalTaskService(
			tasks: $this->tasks,
			mapper: $this->mapper,
			inbox: $inbox,
			temporal: new TaskTemporalProjection(),
			files: $this->files,
			logger: new NullLogger()
		);
	}//end setUp()

	/**
	 * An open external task matched to `party:bsn-1`, anchored to case-7.
	 *
	 * @param array<string, mixed> $upload Upload constraint overrides.
	 *
	 * @return Task The task.
	 */
	private function task(array $upload = []): Task {
		$task = new Task();
		$task->setId(7);
		$task->setUuid('t-1');
		$task->setState(Task::STATE_ACTIVE);
		$task->setIsTerminal(false);
		$task->setPerformerType(Task::PERFORMER_EXTERNAL);
		$task->setAssignee('party:bsn-1');
		$task->setObjectUuid('case-7');
		$task->setRegisterId(3);
		$task->setMetadata(['upload' => array_merge(['required' => true, 'maxFiles' => 1, 'acceptedTypes' => [], 'maxSizeBytes' => null], $upload)]);

		return $task;
	}//end task()

	/**
	 * Wire `openFor` to the real authorization service over the given task,
	 * tracing the call.
	 *
	 * @param Task $task The task the uuid resolves to.
	 *
	 * @return void
	 */
	private function openForRuns(Task $task): void {
		$authorization = new TaskAuthorizationService();
		$this->tasks->method('openFor')->willReturnCallback(
			function (string $verb, string $uuid, ?string $actor) use ($task, $authorization): Task {
				$this->trace[] = 'authorize:' . $actor;
				$authorization->assertMay(verb: $verb, task: $task, uid: $actor);

				return $task;
			}
		);
	}//end openForRuns()

	/**
	 * A stored file, as the file service returns it.
	 *
	 * @return File&MockObject The file.
	 */
	private function storedFile(): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);
		$file->method('getName')->willReturn('payslip.pdf');
		$file->method('getPath')->willReturn('/case-7/payslip.pdf');
		$file->method('getSize')->willReturn(1200);
		$file->method('getMimeType')->willReturn('application/pdf');

		return $file;
	}//end storedFile()

	/**
	 * The matched party completes with a file: authorize, store on the CASE,
	 * then record, with the stored reference on the completion.
	 *
	 * @return void
	 */
	public function testTheMatchedPartyStoresOnTheCaseBeforeTheCompletionIsRecorded(): void {
		$task = $this->task();
		$this->openForRuns($task);
		$this->files->expects($this->once())
			->method('addFile')
			->with('case-7', 'payslip.pdf', 'PDFBYTES', false, ['portal-task:t-1'], null, null, 3)
			->willReturnCallback(
				function (): File {
					$this->trace[] = 'store';

					return $this->storedFile();
				}
			);
		$this->tasks->expects($this->once())
			->method('complete')
			->willReturnCallback(
				function (string $uuid, string $outcome, ?string $resultText, ?string $comment, ?string $actor, ?array $responses, ?array $evidence) use ($task): Task {
					$this->trace[] = 'record';
					$this->assertSame('t-1', $uuid);
					$this->assertSame('submitted', $outcome);
					$this->assertSame('party:bsn-1', $actor);
					$this->assertSame(['remarks' => 'ok'], $responses);
					$this->assertSame(42, $evidence[0]['fileId']);
					$this->assertSame('/case-7/payslip.pdf', $evidence[0]['path']);
					$task->setState(Task::STATE_COMPLETED);

					return $task;
				}
			);

		$completed = $this->service->complete(
			subject: new PortalSubject(subjectRef: 'bsn-1'),
			uuid: 't-1',
			answers: ['remarks' => 'ok'],
			comment: null,
			files: [['name' => 'payslip.pdf', 'type' => 'application/pdf', 'size' => 1200, 'content' => 'PDFBYTES']]
		);

		$this->assertSame(Task::STATE_COMPLETED, $completed->getState());
		$this->assertSame(['authorize:party:bsn-1', 'store', 'record'], $this->trace, 'authorize, store, THEN record');
	}//end testTheMatchedPartyStoresOnTheCaseBeforeTheCompletionIsRecorded()

	/**
	 * Another subject who knows the uuid is denied before anything is stored,
	 * and the task is unchanged.
	 *
	 * @return void
	 */
	public function testAnotherSubjectIsDeniedBeforeAnythingIsStored(): void {
		$task = $this->task();
		$this->openForRuns($task);
		$this->files->expects($this->never())->method('addFile');
		$this->tasks->expects($this->never())->method('complete');

		try {
			$this->service->complete(
				subject: new PortalSubject(subjectRef: 'bsn-2'),
				uuid: 't-1',
				files: [['name' => 'x.pdf', 'type' => 'application/pdf', 'size' => 1, 'content' => 'x']]
			);
			$this->fail('Another subject completed the task.');
		} catch (TaskAccessDeniedException $denied) {
			$this->assertStringContainsString('matched portal subject', $denied->getMessage());
		}

		$this->assertSame(Task::STATE_ACTIVE, $task->getState());
	}//end testAnotherSubjectIsDeniedBeforeAnythingIsStored()

	/**
	 * THE CASE-EDIT REGRESSION: the party is compared to the task's STORED
	 * reference. The case's initiator has moved to party B; B is still denied
	 * and A still completes, because nothing re-resolves.
	 *
	 * @return void
	 */
	public function testEditingTheCaseDoesNotMoveTheOpenAsk(): void {
		$task = $this->task(['required' => false]);
		$this->openForRuns($task);
		// No party resolver is consulted at completion at all: the service has
		// none, and the mapper/object store is never asked for the case.
		$this->mapper->expects($this->never())->method('findByUuid');
		$this->tasks->method('complete')->willReturn($task);

		try {
			$this->service->complete(subject: new PortalSubject(subjectRef: 'party-b'), uuid: 't-1');
			$this->fail('Party B completed a task matched to party A.');
		} catch (TaskAccessDeniedException) {
			$this->addToAssertionCount(1);
		}

		$this->service->complete(subject: new PortalSubject(subjectRef: 'bsn-1'), uuid: 't-1');
		$this->assertContains('authorize:party:bsn-1', $this->trace);
	}//end testEditingTheCaseDoesNotMoveTheOpenAsk()

	/**
	 * A Nextcloud administrator acting through the seam is denied like any
	 * other non-party: there is no admin bypass on an external completion.
	 *
	 * @return void
	 */
	public function testAnAdministratorIsDeniedThroughTheSeam(): void {
		$task = $this->task(['required' => false]);
		// The real rule over a backend that calls EVERYONE an administrator.
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(true);
		$authorization = new TaskAuthorizationService(groupManager: $groups);
		$this->tasks->method('openFor')->willReturnCallback(
			static function (string $verb, string $uuid, ?string $actor) use ($task, $authorization): Task {
				$authorization->assertMay(verb: $verb, task: $task, uid: $actor);

				return $task;
			}
		);
		$this->files->expects($this->never())->method('addFile');

		$this->expectException(TaskAccessDeniedException::class);
		$this->service->complete(subject: new PortalSubject(subjectRef: 'admin'), uuid: 't-1');
	}//end testAnAdministratorIsDeniedThroughTheSeam()

	/**
	 * The upload constraints refuse, naming the constraint, BEFORE any storage:
	 * required, count, size, type, and a task with no case to land on.
	 *
	 * @return void
	 */
	public function testUploadConstraintsRefuseNamingTheConstraintBeforeAnythingIsStored(): void {
		$this->files->expects($this->never())->method('addFile');
		$this->tasks->expects($this->never())->method('complete');
		$subject = new PortalSubject(subjectRef: 'bsn-1');
		$pdf = ['name' => 'a.pdf', 'type' => 'application/pdf', 'size' => 100, 'content' => 'x'];

		$cases = [
			['uploadRequired', $this->task(), []],
			['uploadMaxFiles', $this->task(['maxFiles' => 1]), [$pdf, $pdf]],
			['uploadMaxSizeMb', $this->task(['maxSizeBytes' => 50]), [$pdf]],
			['uploadAcceptedTypes', $this->task(['acceptedTypes' => ['image/*', 'docx']]), [$pdf]],
		];
		$anchorless = $this->task(['required' => false]);
		$anchorless->setObjectUuid(null);
		$cases[] = ['no case object', $anchorless, [$pdf]];

		foreach ($cases as [$constraint, $task, $files]) {
			$tasks = $this->createMock(TaskService::class);
			$tasks->method('openFor')->willReturn($task);
			$service = new PortalTaskService(
				tasks: $tasks,
				mapper: $this->mapper,
				inbox: new TaskInboxService(tasks: $this->mapper, temporal: new TaskTemporalProjection(), logger: new NullLogger()),
				temporal: new TaskTemporalProjection(),
				files: $this->files,
				logger: new NullLogger()
			);
			try {
				$service->complete(subject: $subject, uuid: 't-1', files: $files);
				$this->fail("Expected a refusal naming $constraint");
			} catch (TaskValidationException $refused) {
				$this->assertStringContainsString($constraint, $refused->getMessage());
			}

			$this->assertSame(Task::STATE_ACTIVE, $task->getState(), 'the task remains open');
		}
	}//end testUploadConstraintsRefuseNamingTheConstraintBeforeAnythingIsStored()

	/**
	 * Accepted types admit by exact media type, wildcard, or extension.
	 *
	 * @return void
	 */
	public function testAcceptedTypesAdmitByMediaTypeWildcardOrExtension(): void {
		$task = $this->task(['acceptedTypes' => ['application/pdf', 'image/*', '.docx'], 'maxFiles' => 5]);
		$this->service->assertUploadConstraints(
			task: $task,
			files: [
				['name' => 'a.pdf', 'type' => 'application/pdf', 'size' => 1],
				['name' => 'b.png', 'type' => 'image/png', 'size' => 1],
				['name' => 'c.docx', 'type' => 'application/octet-stream', 'size' => 1],
			]
		);
		$this->addToAssertionCount(1);

		$this->expectException(TaskValidationException::class);
		$this->service->assertUploadConstraints(task: $task, files: [['name' => 'd.exe', 'type' => 'application/x-msdownload', 'size' => 1]]);
	}//end testAcceptedTypesAdmitByMediaTypeWildcardOrExtension()

	/**
	 * The subject-scoped read: the party reference is the query predicate, the
	 * total is the same predicate's count, and a foreign row never leaves.
	 *
	 * @return void
	 */
	public function testTheListIsScopedToTheSubjectInTheQueryAndTheTotal(): void {
		$mine = $this->task();
		$foreign = $this->task();
		$foreign->setUuid('t-9');
		$foreign->setAssignee('party:bsn-2');
		$this->mapper->expects($this->once())->method('findOpenExternalForParty')->with('party:bsn-1', 25, 0)->willReturn([$mine, $foreign]);
		$this->mapper->expects($this->once())->method('countOpenExternalForParty')->with('party:bsn-1')->willReturn(1);

		$page = $this->service->listForSubject(subject: new PortalSubject(subjectRef: 'bsn-1'));
		$this->assertSame(1, $page['total']);
		$this->assertCount(1, $page['results'], 'a row that is not this party\'s never leaves the service');
		$this->assertSame('t-1', $page['results'][0]['uuid']);
		$this->assertSame('not-recorded', $page['results'][0]['delivery']['state'], 'an external row carries its delivery state');
	}//end testTheListIsScopedToTheSubjectInTheQueryAndTheTotal()

	/**
	 * `show` answers absence for a task that is not this subject's or not external.
	 *
	 * @return void
	 */
	public function testShowReadsAForeignOrInternalTaskAsAbsent(): void {
		$task = $this->task();
		$this->tasks->method('get')->willReturn($task);
		$this->assertSame('t-1', $this->service->show(subject: new PortalSubject(subjectRef: 'bsn-1'), uuid: 't-1')->getUuid());

		try {
			$this->service->show(subject: new PortalSubject(subjectRef: 'bsn-2'), uuid: 't-1');
			$this->fail('A foreign subject read the task.');
		} catch (DoesNotExistException) {
			$this->addToAssertionCount(1);
		}

		$task->setPerformerType(Task::PERFORMER_USER);
		$task->setAssignee('party:bsn-1');
		$this->expectException(DoesNotExistException::class);
		$this->service->show(subject: new PortalSubject(subjectRef: 'bsn-1'), uuid: 't-1');
	}//end testShowReadsAForeignOrInternalTaskAsAbsent()
}//end class
