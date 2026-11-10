<?php

/**
 * AuditTrailExportJob marks a truncated export in its filename, refuses a non-folder
 * export location and runs through the recorder.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\RecordedJobRuntime;
use OCA\OpenRegister\BackgroundJob\AuditExportDelivery;
use OCA\OpenRegister\BackgroundJob\AuditExportSource;
use OCA\OpenRegister\BackgroundJob\AuditTrailExportJob;
use OCA\OpenRegister\Db\AuditTrailPageQueryInterface;
use OCA\OpenRegister\Service\LogService;
use OCA\OpenRegister\Service\Operations\JobRunStart;
use OCA\OpenRegister\Service\Operations\JobRunRecorderInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * Behaviour of deliver() beyond the plain happy path, and of the recorded run.
 */
class AuditTrailExportJobBehaviourTest extends TestCase {

	/**
	 * @var AuditTrailPageQueryInterface&MockObject
	 */
	private AuditTrailPageQueryInterface $pageQuery;

	/**
	 * @var LogService&MockObject
	 */
	private LogService $logService;

	/**
	 * @var Folder&MockObject
	 */
	private Folder $userFolder;

	/**
	 * Files written: name => content.
	 *
	 * @var array<string, string>
	 */
	private array $written = [];

	/**
	 * Subject parameters of the notification.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $subject = null;

	/**
	 * Recorder calls: [jobClass, cause, actor].
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $recorded = [];

	private AuditTrailExportJob $job;

	protected function setUp(): void {
		$this->pageQuery  = $this->createMock(AuditTrailPageQueryInterface::class);
		$this->logService = $this->createMock(LogService::class);
		$this->userFolder = $this->createMock(Folder::class);

		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('admin')->willReturn($this->userFolder);

		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject'] as $method) {
			$notification->method($method)->willReturnSelf();
		}

		$notification->method('setSubject')->willReturnCallback(
			function (string $subject, array $params) use ($notification): INotification {
				$this->subject = $params;
				return $notification;
			}
		);
		$notifications = $this->createMock(IManager::class);
		$notifications->method('createNotification')->willReturn($notification);

		$recorder = $this->createMock(JobRunRecorderInterface::class);
		$recorder->method('around')->willReturnCallback(
			function (JobRunStart $start, callable $work): void {
				$this->recorded[] = [$start->jobClass, $start->context->cause, $start->context->actor];
				$work();
			}
		);

		$this->job = new AuditTrailExportJob(
			new RecordedJobRuntime($this->createMock(ITimeFactory::class), $recorder),
			new AuditExportSource(pageQuery: $this->pageQuery, logService: $this->logService),
			new AuditExportDelivery(rootFolder: $root, notifications: $notifications)
		);
	}

	/**
	 * An export folder that records what is written into it.
	 *
	 * @return Folder
	 */
	private function exportsFolder(): Folder {
		$exports = $this->createMock(Folder::class);
		$exports->method('newFile')->willReturnCallback(
			function (string $name, $content): File {
				$this->written[$name] = $content;
				return $this->createMock(File::class);
			}
		);
		return $exports;
	}

	public function testTruncatedExportIsMarkedInTheFilenameAndTheNotification(): void {
		$this->pageQuery->method('collect')->willReturn(['results' => [['id' => 1]], 'truncated' => true]);
		$this->logService->method('exportRows')->willReturn(['filename' => 'audit-trail.csv', 'content' => 'a,b']);
		$this->userFolder->method('nodeExists')->willReturn(true);
		$this->userFolder->expects($this->never())->method('newFolder');
		$this->userFolder->method('get')->with('Audit exports')->willReturn($this->exportsFolder());

		$path = $this->job->deliver(['actor' => 'admin']);

		$expected = 'audit-trail_first-'.AuditTrailExportJob::JOB_LIMIT.'.csv';
		$this->assertSame('Audit exports/'.$expected, $path);
		$this->assertSame([$expected => 'a,b'], $this->written);
		$this->assertSame(
			['folder' => 'Audit exports/', 'filename' => $expected, 'rows' => 1, 'truncated' => true],
			$this->subject
		);
	}

	public function testFormatIsLowercasedAndChangesAreIncludedByDefault(): void {
		$this->pageQuery->expects($this->once())->method('collect')
			->with([], 'needle', AuditTrailExportJob::JOB_LIMIT)
			->willReturn(['results' => [], 'truncated' => false]);
		$this->logService->expects($this->once())->method('exportRows')
			->with('json', [], ['includeChanges' => true])
			->willReturn(['filename' => 'audit-trail.json', 'content' => '[]']);
		$this->userFolder->method('nodeExists')->willReturn(true);
		$this->userFolder->method('get')->willReturn($this->exportsFolder());

		$path = $this->job->deliver(['actor' => 'admin', 'format' => 'JSON', 'search' => 'needle']);

		$this->assertSame('Audit exports/audit-trail.json', $path);
	}

	public function testIncludeChangesFalseIsPassedOn(): void {
		$this->pageQuery->method('collect')->willReturn(['results' => [], 'truncated' => false]);
		$this->logService->expects($this->once())->method('exportRows')
			->with('csv', [], ['includeChanges' => false])
			->willReturn(['filename' => 'audit-trail.csv', 'content' => '']);
		$this->userFolder->method('nodeExists')->willReturn(true);
		$this->userFolder->method('get')->willReturn($this->exportsFolder());

		$this->assertSame('Audit exports/audit-trail.csv', $this->job->deliver(['actor' => 'admin', 'includeChanges' => false]));
	}

	public function testExportLocationThatIsNotAFolderIsRefusedAndNothingIsWritten(): void {
		$this->pageQuery->method('collect')->willReturn(['results' => [], 'truncated' => false]);
		$this->logService->method('exportRows')->willReturn(['filename' => 'audit-trail.csv', 'content' => '']);
		$this->userFolder->method('nodeExists')->willReturn(true);
		$this->userFolder->method('get')->willReturn($this->createMock(File::class));

		try {
			$this->job->deliver(['actor' => 'admin']);
			$this->fail('A file in place of the export folder must be refused.');
		} catch (RuntimeException $e) {
			$this->assertSame('"Audit exports" in the requester\'s Files is not a folder.', $e->getMessage());
		}

		$this->assertNull($this->subject);
	}

	public function testRecordedRunDeliversWithTheRequesterAsManualActor(): void {
		$this->pageQuery->method('collect')->willReturn(['results' => [], 'truncated' => false]);
		$this->logService->method('exportRows')->willReturn(['filename' => 'audit-trail.csv', 'content' => 'x']);
		$this->userFolder->method('nodeExists')->willReturn(true);
		$this->userFolder->method('get')->willReturn($this->exportsFolder());

		(new ReflectionMethod($this->job, 'run'))->invoke($this->job, ['actor' => 'admin']);

		$this->assertSame([[AuditTrailExportJob::class, 'manual', 'admin']], $this->recorded);
		$this->assertSame(['audit-trail.csv' => 'x'], $this->written);
	}
}
