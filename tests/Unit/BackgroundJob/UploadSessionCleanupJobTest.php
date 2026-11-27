<?php

/**
 * The hourly cleanup removes an abandoned upload session with its parts,
 * leaves a live one alone, and does not report a failed run as done.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/large-file-handling/specs/file-actions/spec.md#requirement-an-interrupted-upload-resumes-and-states-its-completeness-req-lfh-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use DateTime;
use DateTimeImmutable;
use OCA\OpenRegister\BackgroundJob\UploadSessionCleanupJob;
use OCA\OpenRegister\Db\UploadSession;
use OCA\OpenRegister\Db\UploadSessionMapper;
use OCA\OpenRegister\Service\File\ChunkedUploadService;
use OCA\OpenRegister\Service\FileWriterInterface;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\BackgroundJob\UploadSessionCleanupJob
 * @uses   \OCA\OpenRegister\Service\File\ChunkedUploadService
 * @uses   \OCA\OpenRegister\Db\UploadSession
 * @uses   \OCA\OpenRegister\Service\Settings\FileSettingsHandler
 */
class UploadSessionCleanupJobTest extends TestCase {

	/**
	 * The app data folders that exist, by path.
	 *
	 * @var array<string, true>
	 */
	private array $folders = [];

	/**
	 * The folders deleted.
	 *
	 * @var list<string>
	 */
	private array $deletedFolders = [];

	/**
	 * The sessions table.
	 *
	 * @var UploadSessionMapper&MockObject
	 */
	private UploadSessionMapper&MockObject $sessions;

	protected function setUp(): void {
		parent::setUp();
		$this->sessions = $this->createMock(UploadSessionMapper::class);
	}//end setUp()

	/**
	 * The real upload service over an in-memory app data store and a clock at 2026-11-14 12:00 UTC.
	 *
	 * @return ChunkedUploadService
	 */
	private function service(): ChunkedUploadService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $default
		);

		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willReturnCallback(
			function (string $path): ISimpleFolder {
				if (isset($this->folders[$path]) === false) {
					throw new NotFoundException($path);
				}

				$folder = $this->createMock(ISimpleFolder::class);
				$folder->method('delete')->willReturnCallback(
					function () use ($path): void {
						unset($this->folders[$path]);
						$this->deletedFolders[] = $path;
					}
				);
				return $folder;
			}
		);
		$factory = $this->createMock(IAppDataFactory::class);
		$factory->method('get')->with('openregister')->willReturn($appData);

		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('now')->willReturn(new DateTimeImmutable('2026-11-14T12:00:00+00:00'));

		return new ChunkedUploadService(
			sessions: $this->sessions,
			appDataFactory: $factory,
			settings: new FileSettingsHandler(appConfig: $appConfig),
			files: $this->createMock(FileWriterInterface::class),
			clock: $clock,
			logger: new NullLogger()
		);
	}//end service()

	/**
	 * A session with this upload id that expires at this moment.
	 *
	 * @param string $uploadId  The upload id.
	 * @param string $expiresAt The expiry.
	 *
	 * @return UploadSession
	 */
	private function session(string $uploadId, string $expiresAt): UploadSession {
		$session = new UploadSession();
		$session->setUploadId($uploadId);
		$session->setUserId('user-a');
		$session->setObjectUuid('5f0c0a1e-0000-4000-8000-000000000001');
		$session->setName('mail-export.mbox');
		$session->setSize(30000000);
		$session->setSha256(str_repeat('ab', 32));
		$session->setPartSize(10485760);
		$session->setReceived('[1,2]');
		$session->setExpiresAt(new DateTime($expiresAt));
		return $session;
	}//end session()

	/**
	 * Run the protected run().
	 *
	 * @param UploadSessionCleanupJob $job The job.
	 *
	 * @return void
	 */
	private function runJob(UploadSessionCleanupJob $job): void {
		$run = new ReflectionMethod($job, 'run');
		$run->invoke($job, null);
	}//end runJob()

	/**
	 * A session last touched 25 hours ago (expired an hour ago under the
	 * default PT24H) loses its parts folder and its row; the run is hourly and
	 * logs the count.
	 *
	 * @return void
	 */
	public function testAnExpiredSessionIsRemoved(): void {
		$expired = $this->session(uploadId: 'aaaaaaaa-0000-4000-8000-000000000001', expiresAt: '2026-11-14T11:00:00+00:00');
		$this->folders['uploads/aaaaaaaa-0000-4000-8000-000000000001'] = true;
		$this->folders['uploads/bbbbbbbb-0000-4000-8000-000000000002'] = true;

		$this->sessions->expects($this->once())->method('findExpired')->with(
			$this->callback(fn (DateTime $now): bool => $now->format(DATE_ATOM) === '2026-11-14T12:00:00+00:00')
		)->willReturn([$expired]);
		$this->sessions->expects($this->once())->method('delete')->with($this->identicalTo($expired))->willReturn($expired);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with(
			$this->stringContains('removed expired upload sessions'),
			$this->identicalTo(['removed' => 1])
		);

		$job = new UploadSessionCleanupJob(time: $this->createMock(ITimeFactory::class), uploads: $this->service(), logger: $logger);
		$this->assertSame(3600, $job->getInterval());
		$this->runJob(job: $job);

		$this->assertSame(['uploads/aaaaaaaa-0000-4000-8000-000000000001'], $this->deletedFolders);
		$this->assertArrayHasKey('uploads/bbbbbbbb-0000-4000-8000-000000000002', $this->folders, 'a live session keeps its parts');
	}//end testAnExpiredSessionIsRemoved()

	/**
	 * An expired session whose parts folder is already gone still loses its row.
	 *
	 * @return void
	 */
	public function testAnExpiredSessionWithoutPartsLosesItsRow(): void {
		$expired = $this->session(uploadId: 'cccccccc-0000-4000-8000-000000000003', expiresAt: '2026-11-13T12:00:00+00:00');
		$this->sessions->method('findExpired')->willReturn([$expired]);
		$this->sessions->expects($this->once())->method('delete')->with($this->identicalTo($expired))->willReturn($expired);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with($this->anything(), $this->identicalTo(['removed' => 1]));

		$this->runJob(job: new UploadSessionCleanupJob(time: $this->createMock(ITimeFactory::class), uploads: $this->service(), logger: $logger));
		$this->assertSame([], $this->deletedFolders);
	}//end testAnExpiredSessionWithoutPartsLosesItsRow()

	/**
	 * A session that cannot be removed is not counted and stays for the next
	 * run; the others are still removed.
	 *
	 * @return void
	 */
	public function testASessionThatCannotBeRemovedIsNotCounted(): void {
		$stuck = $this->session(uploadId: 'dddddddd-0000-4000-8000-000000000004', expiresAt: '2026-11-14T10:00:00+00:00');
		$gone  = $this->session(uploadId: 'eeeeeeee-0000-4000-8000-000000000005', expiresAt: '2026-11-14T10:00:00+00:00');
		$this->sessions->method('findExpired')->willReturn([$stuck, $gone]);
		$this->sessions->expects($this->exactly(2))->method('delete')->willReturnCallback(
			function (UploadSession $session) use ($stuck): UploadSession {
				if ($session === $stuck) {
					throw new RuntimeException('database gone');
				}

				return $session;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with($this->anything(), $this->identicalTo(['removed' => 1]));

		$this->runJob(job: new UploadSessionCleanupJob(time: $this->createMock(ITimeFactory::class), uploads: $this->service(), logger: $logger));
	}//end testASessionThatCannotBeRemovedIsNotCounted()

	/**
	 * When the expired sessions cannot be read, the failure is logged and
	 * rethrown, so the job list does not record a run that did not happen.
	 *
	 * @return void
	 */
	public function testAFailedRunIsLoggedAndRethrown(): void {
		$this->sessions->method('findExpired')->willThrowException(new RuntimeException('table missing'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			$this->stringContains('failed'),
			$this->identicalTo(['exception' => 'table missing'])
		);
		$logger->expects($this->never())->method('info');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('table missing');
		$this->runJob(job: new UploadSessionCleanupJob(time: $this->createMock(ITimeFactory::class), uploads: $this->service(), logger: $logger));
	}//end testAFailedRunIsLoggedAndRethrown()
}//end class
