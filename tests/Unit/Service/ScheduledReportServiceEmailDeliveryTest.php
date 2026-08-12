<?php

/**
 * ScheduledReportServiceEmailDeliveryTest
 *
 * Unit tests for `ScheduledReportService::runOne()`'s email-delivery leg
 * (scheduled-report-email-delivery): the `deliveryMode` matrix
 * (files/email/both), attachment-vs-oversize-link fallback, default-to-owner
 * recipient resolution, and `email_failed` isolation (Files still delivered
 * when the email leg fails under mode `both`, vs a hard `failed` when mode
 * is `email` only). Mocks service boundaries (`ExportService`, Files,
 * `IMailer`, notifications, session) — not business logic.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/changes/scheduled-report-email-delivery/specs/scheduled-report-jobs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\ScheduledReport;
use OCA\OpenRegister\Db\ScheduledReportMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ScheduledReportService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Mail\IAttachment;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ScheduledReportServiceEmailDeliveryTest extends TestCase {

	private ScheduledReportMapper&MockObject $mapper;

	private RegisterMapper&MockObject $registerMapper;

	private SchemaMapper&MockObject $schemaMapper;

	private ExportService&MockObject $exportService;

	private IRootFolder&MockObject $rootFolder;

	private IUserManager&MockObject $userManager;

	private IUserSession&MockObject $userSession;

	private IManager&MockObject $notificationManager;

	private IMailer&MockObject $mailer;

	private IConfig&MockObject $config;

	private IUser&MockObject $owner;

	private IMessage&MockObject $message;

	private ScheduledReportService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->mapper = $this->createMock(ScheduledReportMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->exportService = $this->createMock(ExportService::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->notificationManager = $this->createMock(IManager::class);
		$this->mailer = $this->createMock(IMailer::class);
		$this->config = $this->createMock(IConfig::class);

		$this->mapper->method('update')->willReturnArgument(0);
		$this->config->method('getSystemValue')->willReturnCallback(
			static fn ($key, $default = null) => $default
		);

		$this->owner = $this->createMock(IUser::class);
		$this->owner->method('getUID')->willReturn('alice');
		$this->owner->method('getDisplayName')->willReturn('Alice');
		$this->owner->method('getEMailAddress')->willReturn('alice@example.com');
		$this->userManager->method('get')->with('alice')->willReturn($this->owner);

		$this->notificationManager->method('createNotification')->willReturn($this->createMock(INotification::class));

		// A single shared message mock, registered exactly once as
		// createMessage()'s return value — tests that care about setTo()
		// etc. add expectations directly on $this->message rather than
		// re-registering createMessage() a second time (PHPUnit evaluates
		// every configured behaviour for a method on each invocation, so a
		// second `method('createMessage')` stub would be ambiguous about
		// which return value/expectation applies).
		$this->message = $this->createMock(IMessage::class);
		$this->mailer->method('createMessage')->willReturn($this->message);
		$this->mailer->method('createEMailTemplate')->willReturn($this->createMock(IEMailTemplate::class));
		$this->mailer->method('createAttachment')->willReturn($this->createMock(IAttachment::class));

		$this->service = new ScheduledReportService(
			$this->mapper,
			$this->registerMapper,
			$this->schemaMapper,
			$this->exportService,
			$this->rootFolder,
			$this->userManager,
			$this->userSession,
			$this->notificationManager,
			$this->createMock(LoggerInterface::class),
			$this->mailer,
			$this->config
		);
	}//end setUp()

	private function makeReport(string $mode, array $recipients = []): ScheduledReport {
		$report = new ScheduledReport();
		$ref = new \ReflectionClass($report);
		$prop = $ref->getProperty('id');
		$prop->setAccessible(true);
		$prop->setValue($report, 7);

		$report->setOwner('alice');
		$report->setName('Weekly cases');
		$report->setRegisterId(1);
		$report->setSchemaId(2);
		$report->setFilters('[]');
		$report->setFormat('csv');
		$report->setScheduleType('weekly');
		$report->setScheduleHour(8);
		$report->setScheduleDayOfWeek(0);
		$report->setDeliveryFolder('Reports/');
		$report->setDeliveryMode($mode);
		$report->setRecipients(json_encode($recipients));
		$report->setEnabled(true);

		return $report;
	}//end makeReport()

	/**
	 * Stub a permissive Files folder (folder + file both already exist, so
	 * every call just overwrites) for tests where Files delivery is expected
	 * to happen but its exact mechanics aren't the point of the test.
	 *
	 * Takes the exact expected call count itself (rather than letting a
	 * caller separately register `expects()` on the same mock method) —
	 * PHPUnit evaluates every matching configured behaviour on each
	 * invocation, so registering a count constraint on a second, separate
	 * `method()`/`expects()` call for `getUserFolder` is ambiguous about
	 * which stub's return value wins.
	 *
	 * @param int|null $expectedCalls Exact expected call count, or null for "any number of times".
	 */
	private function stubPermissiveFilesFolder(?int $expectedCalls = null): void {
		$file = $this->createMock(\OCP\Files\File::class);
		$file->method('putContent');

		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willReturn(true);
		$folder->method('get')->willReturn($file);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('nodeExists')->willReturn(true);
		$userFolder->method('get')->willReturn($folder);

		$matcher = ($expectedCalls === null) ? self::any() : self::exactly($expectedCalls);
		$this->rootFolder->expects($matcher)->method('getUserFolder')->with('alice')->willReturn($userFolder);
	}//end stubPermissiveFilesFolder()

	public function testFilesModeNeverTouchesMailer(): void {
		$report = $this->makeReport('files');
		$this->stubPermissiveFilesFolder();
		$this->exportService->method('exportToCsv')->willReturn("id,name\n1,a\n2,b");

		$this->mailer->expects(self::never())->method('createMessage');

		$this->service->runOne(report: $report);

		self::assertSame('success', $report->getLastStatus());
	}//end testFilesModeNeverTouchesMailer()

	public function testEmailModeSendsEmailAndSkipsFiles(): void {
		$report = $this->makeReport('email');
		$this->exportService->method('exportToCsv')->willReturn("id,name\n1,a\n2,b");

		$this->rootFolder->expects(self::never())->method('getUserFolder');
		$this->mailer->expects(self::once())->method('createAttachment');
		$this->mailer->expects(self::once())->method('send');

		$this->service->runOne(report: $report);

		self::assertSame('success', $report->getLastStatus());
	}//end testEmailModeSendsEmailAndSkipsFiles()

	public function testBothModeDeliversFilesAndEmail(): void {
		$report = $this->makeReport('both');
		$this->stubPermissiveFilesFolder(expectedCalls: 1);
		$this->exportService->method('exportToCsv')->willReturn("id,name\n1,a\n2,b");

		$this->mailer->expects(self::once())->method('send');

		$this->service->runOne(report: $report);

		self::assertSame('success', $report->getLastStatus());
	}//end testBothModeDeliversFilesAndEmail()

	public function testEmailModeOversizeFallsBackToFilesWithoutAttachment(): void {
		$report = $this->makeReport('email');
		// Fallback: the export was never delivered to Files by the (skipped)
		// Files leg, so the oversize path must write it there itself — expect exactly once.
		$this->stubPermissiveFilesFolder(expectedCalls: 1);

		$oversizeCsv = "id,name\n" . str_repeat('x', (ScheduledReportService::MAX_EMAIL_ATTACHMENT_BYTES + 1));
		$this->exportService->method('exportToCsv')->willReturn($oversizeCsv);

		$this->mailer->expects(self::never())->method('createAttachment');
		$this->mailer->expects(self::once())->method('send');

		$this->service->runOne(report: $report);

		self::assertSame('success', $report->getLastStatus());
	}//end testEmailModeOversizeFallsBackToFilesWithoutAttachment()

	public function testBothModeOversizeDoesNotDoubleWriteFiles(): void {
		$report = $this->makeReport('both');
		// Files leg already delivers it once — the oversize fallback inside
		// the email leg must not write it again, so still exactly once total.
		$this->stubPermissiveFilesFolder(expectedCalls: 1);

		$oversizeCsv = "id,name\n" . str_repeat('x', (ScheduledReportService::MAX_EMAIL_ATTACHMENT_BYTES + 1));
		$this->exportService->method('exportToCsv')->willReturn($oversizeCsv);

		$this->mailer->expects(self::never())->method('createAttachment');
		$this->mailer->expects(self::once())->method('send');

		$this->service->runOne(report: $report);

		self::assertSame('success', $report->getLastStatus());
	}//end testBothModeOversizeDoesNotDoubleWriteFiles()

	public function testEmailFailureIsIsolatedWhenFilesSucceeds(): void {
		$report = $this->makeReport('both');
		$this->stubPermissiveFilesFolder();
		$this->exportService->method('exportToCsv')->willReturn("id,name\n1,a");

		$this->mailer->method('send')->willThrowException(new \RuntimeException('SMTP unreachable'));

		$this->service->runOne(report: $report);

		self::assertSame('email_failed', $report->getLastStatus());
		self::assertStringContainsString('SMTP unreachable', (string)$report->getLastError());
	}//end testEmailFailureIsIsolatedWhenFilesSucceeds()

	public function testEmailOnlyFailureMarksReportFailedWithoutTouchingFiles(): void {
		$report = $this->makeReport('email');
		$this->exportService->method('exportToCsv')->willReturn("id,name\n1,a");

		$this->mailer->method('send')->willThrowException(new \RuntimeException('SMTP unreachable'));
		$this->rootFolder->expects(self::never())->method('getUserFolder');

		$this->service->runOne(report: $report);

		self::assertSame('failed', $report->getLastStatus());
		self::assertStringContainsString('SMTP unreachable', (string)$report->getLastError());
	}//end testEmailOnlyFailureMarksReportFailedWithoutTouchingFiles()

	public function testDefaultRecipientIsOwnerEmailWhenNoneConfigured(): void {
		$report = $this->makeReport('email', []);
		$this->exportService->method('exportToCsv')->willReturn("id,name\n1,a");

		$this->message->expects(self::once())->method('setTo')->with(['alice@example.com' => 'Alice']);

		$this->service->runOne(report: $report);

		self::assertSame('success', $report->getLastStatus());
	}//end testDefaultRecipientIsOwnerEmailWhenNoneConfigured()

	public function testExplicitRecipientsOverrideOwnerEmail(): void {
		$report = $this->makeReport('email', ['team@example.com']);
		$this->exportService->method('exportToCsv')->willReturn("id,name\n1,a");

		$this->message->expects(self::once())->method('setTo')->with(['team@example.com' => 'team@example.com']);

		$this->service->runOne(report: $report);

		self::assertSame('success', $report->getLastStatus());
	}//end testExplicitRecipientsOverrideOwnerEmail()

	public function testOwnerWithoutEmailAndNoRecipientsFailsEmailOnlyMode(): void {
		$report = $this->makeReport('email', []);
		$this->owner = $this->createMock(IUser::class);
		$this->owner->method('getUID')->willReturn('alice');
		$this->owner->method('getEMailAddress')->willReturn(null);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->userManager->method('get')->with('alice')->willReturn($this->owner);

		// Rebuild the service so the new userManager/owner mocks take effect.
		$this->service = new ScheduledReportService(
			$this->mapper,
			$this->registerMapper,
			$this->schemaMapper,
			$this->exportService,
			$this->rootFolder,
			$this->userManager,
			$this->userSession,
			$this->notificationManager,
			$this->createMock(LoggerInterface::class),
			$this->mailer,
			$this->config
		);

		$this->exportService->method('exportToCsv')->willReturn("id,name\n1,a");
		$this->mailer->expects(self::never())->method('createMessage');

		$this->service->runOne(report: $report);

		self::assertSame('failed', $report->getLastStatus());
		self::assertStringContainsString('No valid recipient', (string)$report->getLastError());
	}//end testOwnerWithoutEmailAndNoRecipientsFailsEmailOnlyMode()

	public function testOwnerWithoutEmailStillDeliversFilesUnderBothMode(): void {
		$report = $this->makeReport('both', []);
		$this->stubPermissiveFilesFolder(expectedCalls: 1);

		$this->owner = $this->createMock(IUser::class);
		$this->owner->method('getUID')->willReturn('alice');
		$this->owner->method('getEMailAddress')->willReturn(null);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->userManager->method('get')->with('alice')->willReturn($this->owner);

		$this->service = new ScheduledReportService(
			$this->mapper,
			$this->registerMapper,
			$this->schemaMapper,
			$this->exportService,
			$this->rootFolder,
			$this->userManager,
			$this->userSession,
			$this->notificationManager,
			$this->createMock(LoggerInterface::class),
			$this->mailer,
			$this->config
		);

		$this->exportService->method('exportToCsv')->willReturn("id,name\n1,a");

		$this->service->runOne(report: $report);

		self::assertSame('email_failed', $report->getLastStatus());
		self::assertStringContainsString('No valid recipient', (string)$report->getLastError());
	}//end testOwnerWithoutEmailStillDeliversFilesUnderBothMode()
}//end class
