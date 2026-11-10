<?php

/**
 * Branch behaviour of the portal task seam: the list's clamping and party
 * filter, show's three refusals, the completeVia gate, every upload
 * constraint, and how an upload's content is read and referenced.
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

use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Exception\TaskCompleteViaAppException;
use OCA\OpenRegister\Exception\TaskValidationException;
use OCA\OpenRegister\Service\FileWriterInterface;
use OCA\OpenRegister\Service\Portal\PortalCompletion;
use OCA\OpenRegister\Service\Portal\PortalSubject;
use OCA\OpenRegister\Service\Portal\PortalTaskService;
use OCA\OpenRegister\Service\Portal\PortalTaskSources;
use OCA\OpenRegister\Service\Task\TaskInboxService;
use OCA\OpenRegister\Service\Task\TaskServiceInterface;
use OCA\OpenRegister\Service\Task\TaskTemporalProjection;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Branch tests for {@see PortalTaskService}.
 */
class PortalTaskServiceBehaviourTest extends TestCase {

	/**
	 * The task lifecycle, mocked.
	 *
	 * @var TaskServiceInterface&MockObject
	 */
	private TaskServiceInterface&MockObject $tasks;

	/**
	 * The task mapper, mocked.
	 *
	 * @var TaskMapper&MockObject
	 */
	private TaskMapper&MockObject $mapper;

	/**
	 * The file writer, mocked.
	 *
	 * @var FileWriterInterface&MockObject
	 */
	private FileWriterInterface&MockObject $files;

	/**
	 * The service under test.
	 *
	 * @var PortalTaskService
	 */
	private PortalTaskService $service;

	/**
	 * Build the service over mocked boundaries and the real inbox projection.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->tasks = $this->createMock(TaskServiceInterface::class);
		$this->mapper = $this->createMock(TaskMapper::class);
		$this->files = $this->createMock(FileWriterInterface::class);
		$temporal = new TaskTemporalProjection();
		$inbox = new TaskInboxService(tasks: $this->mapper, logger: new NullLogger());
		$this->service = new PortalTaskService(
			sources: new PortalTaskSources(tasks: $this->tasks, mapper: $this->mapper, inbox: $inbox, temporal: $temporal),
			files: $this->files,
			logger: new NullLogger()
		);
	}//end setUp()

	/**
	 * An open external task for `party:bsn-1` on case-7.
	 *
	 * @param string $uuid The task uuid.
	 * @param string $assignee The stored party reference.
	 * @param array<string, mixed> $metadata The task metadata.
	 *
	 * @return Task The task.
	 */
	private function task(string $uuid = 't-1', string $assignee = 'party:bsn-1', array $metadata = []): Task {
		$task = new Task();
		$task->setId(7);
		$task->setUuid($uuid);
		$task->setState(Task::STATE_ACTIVE);
		$task->setIsTerminal(false);
		$task->setPerformerType(Task::PERFORMER_EXTERNAL);
		$task->setAssignee($assignee);
		$task->setObjectUuid('case-7');
		$task->setRegisterId(3);
		$task->setMetadata($metadata);

		return $task;
	}//end task()

	/**
	 * A file as the writer returns it after storing.
	 *
	 * @param int $id The file id.
	 * @param string $name The file name.
	 *
	 * @return File&MockObject The stored file.
	 */
	private function stored(int $id, string $name): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getName')->willReturn($name);
		$file->method('getPath')->willReturn('/case-7/' . $name);
		$file->method('getSize')->willReturn(10);
		$file->method('getMimeType')->willReturn('application/pdf');

		return $file;
	}//end stored()

	/**
	 * The page size and offset are clamped before they reach the query.
	 *
	 * @return void
	 */
	public function testListClampsLimitAndOffsetBeforeQuerying(): void {
		$this->mapper->expects($this->exactly(2))
			->method('findOpenExternalForParty')
			->willReturnCallback(
				function (string $partyReference, ListQuery $page): array {
					[$limit, $offset] = [$page->limit, $page->offset];
					$this->assertSame('party:bsn-1', $partyReference);
					$this->assertContains($limit, [1, 500]);
					$this->assertSame(0, $offset);

					return [];
				}
			);
		$this->mapper->method('countOpenExternalForParty')->willReturn(0);

		$low = $this->service->listForSubject(subject: new PortalSubject(subjectRef: 'bsn-1'), limit: 0, offset: -5);
		$high = $this->service->listForSubject(subject: new PortalSubject(subjectRef: 'bsn-1'), limit: 9000, offset: -1);

		$this->assertSame([1, 0], [$low['limit'], $low['offset']]);
		$this->assertSame([500, 0], [$high['limit'], $high['offset']]);
	}//end testListClampsLimitAndOffsetBeforeQuerying()

	/**
	 * A row the query returned for another party never leaves the service,
	 * while the total is the count query's answer.
	 *
	 * @return void
	 */
	public function testListDropsAForeignRowTheQueryLetThrough(): void {
		$this->mapper->method('findOpenExternalForParty')
			->willReturn([$this->task(uuid: 'mine'), $this->task(uuid: 'foreign', assignee: 'party:bsn-2')]);
		$this->mapper->method('countOpenExternalForParty')->with('party:bsn-1')->willReturn(17);

		$page = $this->service->listForSubject(subject: new PortalSubject(subjectRef: 'bsn-1'), limit: 10, offset: 20);

		$this->assertSame(['mine'], array_column($page['results'], 'uuid'));
		$this->assertFalse($page['results'][0]['blocked']);
		$this->assertSame(17, $page['total']);
		$this->assertSame([10, 20], [$page['limit'], $page['offset']]);
	}//end testListDropsAForeignRowTheQueryLetThrough()

	/**
	 * Show returns the subject's own external task when nothing blocks it.
	 *
	 * @return void
	 */
	public function testShowReturnsTheSubjectsOwnTask(): void {
		$task = $this->task();
		$this->tasks->method('get')->with('t-1')->willReturn($task);
		$this->mapper->expects($this->never())->method('findByUuid');

		$this->assertSame($task, $this->service->show(subject: new PortalSubject(subjectRef: 'bsn-1'), uuid: 't-1'));
	}//end testShowReturnsTheSubjectsOwnTask()

	/**
	 * An internal task reads as absent even to the party it names.
	 *
	 * @return void
	 */
	public function testShowReadsAnInternalTaskAsAbsent(): void {
		$task = $this->task();
		$task->setPerformerType('user');
		$this->tasks->method('get')->willReturn($task);

		$this->expectException(DoesNotExistException::class);
		$this->service->show(subject: new PortalSubject(subjectRef: 'bsn-1'), uuid: 't-1');
	}//end testShowReadsAnInternalTaskAsAbsent()

	/**
	 * Another party's task reads as absent.
	 *
	 * @return void
	 */
	public function testShowReadsAnotherPartysTaskAsAbsent(): void {
		$this->tasks->method('get')->willReturn($this->task());

		$this->expectException(DoesNotExistException::class);
		$this->service->show(subject: new PortalSubject(subjectRef: 'bsn-2'), uuid: 't-1');
	}//end testShowReadsAnotherPartysTaskAsAbsent()

	/**
	 * A task held back by an open blocker reads as absent; once the blocker
	 * is gone it is shown.
	 *
	 * @return void
	 */
	public function testShowHidesATaskHeldBackByAnOpenBlockerOnly(): void {
		$held = $this->task();
		$held->setBlockedBy('blocker-1');
		$this->tasks->method('get')->willReturn($held);
		$open = $this->task(uuid: 'blocker-1');
		$this->mapper->method('findByUuid')->willReturnOnConsecutiveCalls(
			$open,
			$this->throwException(new DoesNotExistException('gone'))
		);

		try {
			$this->service->show(subject: new PortalSubject(subjectRef: 'bsn-1'), uuid: 't-1');
			$this->fail('A held-back task was shown.');
		} catch (DoesNotExistException $absent) {
			$this->assertSame('No such portal task.', $absent->getMessage());
		}

		$this->assertSame($held, $this->service->show(subject: new PortalSubject(subjectRef: 'bsn-1'), uuid: 't-1'));
	}//end testShowHidesATaskHeldBackByAnOpenBlockerOnly()

	/**
	 * The single-row projection is the task's own serialised row, read
	 * without a per-row blocker store (so it is never marked blocked here).
	 *
	 * @return void
	 */
	public function testRowProjectsOneTaskWithItsBlockedFlag(): void {
		$task = $this->task();
		$task->setBlockedBy('blocker-1');

		$row = $this->service->row(task: $task);

		$this->assertSame('t-1', $row['uuid']);
		$this->assertSame('party:bsn-1', $row['assignee']);
		$this->assertSame('case-7', $row['objectUuid']);
		$this->assertFalse($row['blocked']);
	}//end testRowProjectsOneTaskWithItsBlockedFlag()

	/**
	 * A completion with a temp-file upload streams it onto the case and
	 * references what was stored, with the default outcome.
	 *
	 * @return void
	 */
	public function testCompleteStreamsATempFileAndReferencesIt(): void {
		$task = $this->task(metadata: ['upload' => ['maxFiles' => 2]]);
		$this->tasks->method('openFor')->with('complete', 't-1', 'party:bsn-1')->willReturn($task);
		$tmp = tempnam(sys_get_temp_dir(), 'ptb');
		file_put_contents($tmp, 'BYTES');
		$stream = null;
		$this->files->expects($this->exactly(2))
			->method('addFile')
			->willReturnCallback(
				function ($objectEntity, string $fileName, mixed $content, bool $share, array $tags, $schema, $register, $registerId) use (&$stream): File {
					$this->assertSame('case-7', $objectEntity);
					$this->assertFalse($share);
					$this->assertSame(['portal-task:t-1'], $tags);
					$this->assertSame(3, $registerId);
					if ($fileName === 'a.pdf') {
						$this->assertIsResource($content);
						$this->assertSame('BYTES', stream_get_contents($content));
						$stream = $content;

						return $this->stored(id: 1, name: 'a.pdf');
					}

					$this->assertSame('INLINE', $content);

					return $this->stored(id: 2, name: 'b.pdf');
				}
			);
		$this->tasks->expects($this->once())
			->method('complete')
			->willReturnCallback(
				function (string $uuid, string $outcome, ?string $resultText, ?string $comment, ?string $actor, ?array $responses, ?array $evidence) use ($task): Task {
					$this->assertSame('submitted', $outcome);
					$this->assertNull($resultText);
					$this->assertSame('see attached', $comment);
					$this->assertSame([1, 2], array_column($evidence, 'fileId'));
					$this->assertSame(['a.pdf', 'b.pdf'], array_column($evidence, 'name'));
					$this->assertSame(['t-1', 't-1'], array_column($evidence, 'taskUuid'));
					$this->assertSame('/case-7/a.pdf', $evidence[0]['path']);
					$this->assertSame(10, $evidence[0]['size']);
					$this->assertSame('application/pdf', $evidence[0]['mimeType']);
					$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $evidence[0]['storedAt']);

					return $task;
				}
			);

		$result = $this->service->complete(
			subject: new PortalSubject(subjectRef: 'bsn-1'),
			uuid: 't-1',
			completion: new PortalCompletion(
				comment: 'see attached',
				files: [['name' => 'a.pdf', 'tmp_name' => $tmp], ['name' => 'b.pdf', 'content' => 'INLINE']],
				outcome: '  '
			)
		);
		unlink($tmp);

		$this->assertSame($task, $result);
		$this->assertFalse(is_resource($stream), 'the temp-file stream is closed after storing');
	}//end testCompleteStreamsATempFileAndReferencesIt()

	/**
	 * An upload without content is refused, and nothing is recorded.
	 *
	 * @return void
	 */
	public function testCompleteRefusesAnUploadWithoutContent(): void {
		$this->tasks->method('openFor')->willReturn($this->task());
		$this->files->expects($this->never())->method('addFile');
		$this->tasks->expects($this->never())->method('complete');

		$this->expectException(TaskValidationException::class);
		$this->expectExceptionMessage("File 'x.pdf' carries no content.");
		$this->service->complete(
			subject: new PortalSubject(subjectRef: 'bsn-1'),
			uuid: 't-1',
			completion: new PortalCompletion(files: [['name' => 'x.pdf', 'content' => 42]])
		);
	}//end testCompleteRefusesAnUploadWithoutContent()

	/**
	 * An unreadable temp file is refused by name, and nothing is recorded.
	 *
	 * @return void
	 */
	public function testCompleteRefusesAnUnreadableTempFile(): void {
		$this->tasks->method('openFor')->willReturn($this->task());
		$this->files->expects($this->never())->method('addFile');
		$this->tasks->expects($this->never())->method('complete');

		set_error_handler(static fn (): bool => true, E_WARNING);
		try {
			$this->service->complete(
				subject: new PortalSubject(subjectRef: 'bsn-1'),
				uuid: 't-1',
				completion: new PortalCompletion(files: [['name' => 'x.pdf', 'tmp_name' => '/nonexistent/dir/upload']])
			);
			$this->fail('An unreadable upload was accepted.');
		} catch (TaskValidationException $refused) {
			$this->assertSame("File 'x.pdf' could not be read.", $refused->getMessage());
		} finally {
			restore_error_handler();
		}
	}//end testCompleteRefusesAnUnreadableTempFile()

	/**
	 * The app a task names completes it; any other caller is refused before
	 * anything is stored.
	 *
	 * @return void
	 */
	public function testCompleteViaAdmitsOnlyTheNamedApp(): void {
		$task = $this->task(metadata: [PortalTaskService::COMPLETE_VIA => ['app' => 'zaak', 'action' => 'sign']]);
		$this->tasks->method('openFor')->willReturn($task);
		$this->tasks->expects($this->once())->method('complete')->willReturn($task);

		try {
			$this->service->complete(subject: new PortalSubject(subjectRef: 'bsn-1'), uuid: 't-1', viaApp: 'other');
			$this->fail('Another app completed the task.');
		} catch (TaskCompleteViaAppException $refused) {
			$this->assertStringContainsString('sign action of zaak', $refused->getMessage());
		}

		$this->assertSame($task, $this->service->complete(subject: new PortalSubject(subjectRef: 'bsn-1'), uuid: 't-1', viaApp: 'zaak'));
	}//end testCompleteViaAdmitsOnlyTheNamedApp()

	/**
	 * A task held back by an open blocker cannot be completed.
	 *
	 * @return void
	 */
	public function testCompleteRefusesAHeldBackTask(): void {
		$task = $this->task();
		$task->setBlockedBy('blocker-1');
		$this->tasks->method('openFor')->willReturn($task);
		$this->mapper->method('findByUuid')->willReturn($this->task(uuid: 'blocker-1'));
		$this->tasks->expects($this->never())->method('complete');

		$this->expectException(DoesNotExistException::class);
		$this->service->complete(subject: new PortalSubject(subjectRef: 'bsn-1'), uuid: 't-1');
	}//end testCompleteRefusesAHeldBackTask()

	/**
	 * CompleteVia reads only a whole, trimmed pair; a scalar or a half reads as none.
	 *
	 * @return void
	 */
	public function testCompleteViaReadsOnlyAWholePair(): void {
		$this->assertNull($this->service->completeVia(task: $this->task(metadata: [PortalTaskService::COMPLETE_VIA => 'zaak'])));
		$this->assertNull($this->service->completeVia(task: $this->task(metadata: [PortalTaskService::COMPLETE_VIA => ['app' => 'zaak', 'action' => ' ']])));
		$this->assertNull($this->service->completeVia(task: $this->task()));
		$this->assertSame(
			['app' => 'zaak', 'action' => 'sign'],
			$this->service->completeVia(task: $this->task(metadata: [PortalTaskService::COMPLETE_VIA => ['app' => ' zaak ', 'action' => 'sign ']]))
		);
	}//end testCompleteViaReadsOnlyAWholePair()

	/**
	 * Assert the constraints refuse with the given message.
	 *
	 * @param array<string, mixed> $upload The upload constraints.
	 * @param array<int, array<string, mixed>> $files The uploads.
	 * @param string $message The expected message fragment.
	 * @param string $objectUuid The case the task is anchored to.
	 *
	 * @return void
	 */
	private function assertRefused(array $upload, array $files, string $message, string $objectUuid = 'case-7'): void {
		$task = $this->task(metadata: ['upload' => $upload]);
		$task->setObjectUuid($objectUuid);
		try {
			$this->service->assertUploadConstraints(task: $task, files: $files);
			$this->fail('Expected a refusal: ' . $message);
		} catch (TaskValidationException $refused) {
			$this->assertStringContainsString($message, $refused->getMessage());
		}
	}//end assertRefused()

	/**
	 * Each constraint refuses naming itself, and the flipped input passes.
	 *
	 * @return void
	 */
	public function testEachUploadConstraintRefusesNamingItself(): void {
		$pdf = ['name' => 'a.pdf', 'type' => 'application/pdf', 'size' => 100];

		$this->assertRefused(upload: ['required' => true], files: [], message: 'uploadRequired');
		$this->assertRefused(upload: [], files: [$pdf, $pdf], message: 'At most 1 file(s) are accepted (uploadMaxFiles); 2 were sent.');
		$this->assertRefused(upload: [], files: [$pdf], message: 'anchored to no case object', objectUuid: ' ');
		$this->assertRefused(upload: [], files: [['name' => ' ', 'size' => 1]], message: 'Every uploaded file needs a name.');
		$this->assertRefused(upload: ['maxSizeBytes' => '99'], files: [$pdf], message: "File 'a.pdf' is 100 bytes, larger than the 99 byte limit");
		$this->assertRefused(upload: ['acceptedTypes' => ['image/png']], files: [$pdf], message: "has type 'application/pdf', which is not one of image/png");

		// The flipped inputs: not required and empty, unlimited count, size at the limit.
		$task = $this->task(metadata: ['upload' => ['required' => false, 'maxFiles' => 0, 'maxSizeBytes' => 100]]);
		$task->setObjectUuid('');
		$this->service->assertUploadConstraints(task: $task, files: []);
		$task->setObjectUuid('case-7');
		$this->service->assertUploadConstraints(task: $task, files: [$pdf, $pdf, $pdf]);
		$this->assertSame(0, $this->countOfRefusals($task, [$pdf, $pdf, $pdf]));
	}//end testEachUploadConstraintRefusesNamingItself()

	/**
	 * How many refusals the constraints raise for the files (0 or 1).
	 *
	 * @param Task $task The task.
	 * @param array<int, array<string, mixed>> $files The uploads.
	 *
	 * @return int 1 when refused, 0 when admitted.
	 */
	private function countOfRefusals(Task $task, array $files): int {
		try {
			$this->service->assertUploadConstraints(task: $task, files: $files);
		} catch (TaskValidationException) {
			return 1;
		}

		return 0;
	}//end countOfRefusals()

	/**
	 * Accepted types admit an exact type, a wildcard or an extension, and
	 * refuse a wildcard against an undeclared type or a name with no extension.
	 *
	 * @return void
	 */
	public function testAcceptedTypesMatchExactWildcardAndExtension(): void {
		$task = $this->task(metadata: ['upload' => ['maxFiles' => 0, 'acceptedTypes' => [' ', 'image/*', 'Application/PDF', '.DOCX', 'txt']]]);

		$admitted = [
			['name' => 'p.png', 'type' => 'image/png'],
			['name' => 'x.bin', 'type' => 'application/pdf'],
			['name' => 'letter.docx', 'type' => ''],
			['name' => 'notes.TXT', 'type' => 'text/plain'],
		];
		foreach ($admitted as $file) {
			$this->assertSame(0, $this->countOfRefusals($task, [$file]), $file['name'] . ' should be admitted');
		}

		$refused = [
			['name' => 'p.gif', 'type' => ''],
			['name' => 'imagefile', 'type' => 'imagex/png'],
			['name' => 'docx', 'type' => 'video/mp4'],
		];
		foreach ($refused as $file) {
			$this->assertSame(1, $this->countOfRefusals($task, [$file]), $file['name'] . ' should be refused');
		}
	}//end testAcceptedTypesMatchExactWildcardAndExtension()
}//end class
