<?php

/**
 * Unit tests for the connection-health kind of a scheduled report.
 *
 * A day with something needing attention mails one report with its CSV; a
 * quiet day under onlyWhenAttention mails nothing and says so; a missing
 * integriq fails the run without a mail; the kind needs no register, goes by
 * e-mail and is refused to a non-administrator; `@admins` notes reach the run
 * record; and an export report is validated as before.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/connections-daily-report-mail/specs/scheduled-report-jobs/spec.md#requirement-a-scheduled-report-can-be-a-daily-connection-health-report
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Kernel\Contracts\ReportCaller;
use OCA\OpenRegister\Kernel\Contracts\ScheduledReportUpdate;
use OCA\OpenRegister\Notifications\Db\ScheduledReport;
use OCA\OpenRegister\Notifications\Db\ScheduledReportMapper;
use OCA\OpenRegister\Service\AdminRecipientExpander;
use OCA\OpenRegister\Service\Connection\ConnectionHealthReport;
use OCA\OpenRegister\Service\Connection\ConnectionHealthReportBuilder;
use OCA\OpenRegister\Service\Connection\ConnectionRegistryMissingException;
use OCA\OpenRegister\Service\Connection\ConnectionReportForbiddenException;
use OCA\OpenRegister\Service\Connection\ConnectionReportKind;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ScheduledReportDelivery;
use OCA\OpenRegister\Service\ScheduledReportExporters;
use OCA\OpenRegister\Service\ScheduledReportOwnerServices;
use OCA\OpenRegister\Service\ScheduledReportService;
use OCA\OpenRegister\Service\ScheduledReportStores;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IGroupManager;
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

/**
 * Tests for a scheduled connection report.
 */
final class ScheduledReportConnectionHealthRunTest extends TestCase {

	private ScheduledReportMapper&MockObject $mapper;

	private IMailer&MockObject $mailer;

	private IRootFolder&MockObject $rootFolder;

	private ConnectionHealthReportBuilder&MockObject $builder;

	private IGroupManager&MockObject $groups;

	/**
	 * Attachments created: content, name, type.
	 *
	 * @var array<int, array<int, string|null>>
	 */
	private array $attachments = [];

	private ScheduledReportService $service;

	/**
	 * Build the service with a mocked builder, mailer and admin group.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->mapper = $this->createMock(ScheduledReportMapper::class);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->mapper->method('insert')->willReturnArgument(0);
		$this->mailer = $this->createMock(IMailer::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->builder = $this->createMock(ConnectionHealthReportBuilder::class);
		$this->groups = $this->createMock(IGroupManager::class);

		$owner = $this->createMock(IUser::class);
		$owner->method('getUID')->willReturn('admin1');
		$owner->method('getEMailAddress')->willReturn('admin1@example.nl');
		$owner->method('getDisplayName')->willReturn('Admin One');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->with('admin1')->willReturn($owner);
		$notifications = $this->createMock(IManager::class);
		$notifications->method('createNotification')->willReturn($this->createMock(INotification::class));
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(static fn ($key, $default = null) => $default);

		$this->mailer->method('createMessage')->willReturn($this->createMock(IMessage::class));
		$this->mailer->method('createEMailTemplate')->willReturn($this->createMock(IEMailTemplate::class));
		$this->mailer->method('createAttachment')->willReturnCallback(
			function (?string $data, ?string $name, ?string $type): IAttachment {
				$this->attachments[] = [$data, $name, $type];
				return $this->createMock(IAttachment::class);
			}
		);

		$this->service = new ScheduledReportService(
			new ScheduledReportStores(
				$this->mapper,
				new \OCA\OpenRegister\Db\SchemaCatalog(
					registers: $this->createMock(RegisterMapper::class),
					schemas: $this->createMock(SchemaMapper::class),
					tables: $this->createMock(\OCA\OpenRegister\Db\MagicMapperInterface::class)
				)
			),
			new ScheduledReportExporters($this->createMock(ExportService::class), null, null, new ConnectionReportKind(builder: $this->builder)),
			new ScheduledReportDelivery(
				new ScheduledReportOwnerServices($users, $this->createMock(IUserSession::class), $this->rootFolder, $notifications),
				$this->mailer,
				$config,
				new AdminRecipientExpander(groups: $this->groups)
			),
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * A connection report owned by admin1.
	 *
	 * @param array<string, mixed> $options    The options.
	 * @param array<int, string>   $recipients The recipients.
	 *
	 * @return ScheduledReport
	 */
	private function report(array $options = [], array $recipients = ['ops@example.nl']): ScheduledReport {
		$report = new ScheduledReport();
		$report->setOwner('admin1');
		$report->setName('Daily connection report');
		$report->setKind('connection-health');
		$report->setOptions(json_encode($options));
		$report->setFormat('csv');
		$report->setScheduleType('daily');
		$report->setScheduleHour(7);
		$report->setDeliveryMode('email');
		$report->setRecipients(json_encode($recipients));
		$report->setEnabled(true);
		return $report;
	}//end report()

	/**
	 * A built report.
	 *
	 * @param int $attention How many rows need attention.
	 *
	 * @return ConnectionHealthReport
	 */
	private function built(int $attention): ConnectionHealthReport {
		return new ConnectionHealthReport(
			attention: $attention,
			subject: 'Connection report 2026-11-13: ' . $attention . ' need attention',
			lines: ['Needs attention', 'openregister / LLM: unavailable.'],
			csv: "\xEF\xBB\xBFsection\nattention\n",
			rowCount: 1
		);
	}//end built()

	/**
	 * A day with something needing attention mails one report with its CSV, and touches no Files.
	 *
	 * @return void
	 */
	public function testAnAttentionDayMailsTheReport(): void {
		$this->builder->expects($this->once())->method('build')
			->with(24, $this->isInstanceOf(\DateTimeImmutable::class))
			->willReturn($this->built(attention: 2));
		$this->mailer->expects($this->once())->method('send');
		$this->rootFolder->expects($this->never())->method('getUserFolder');
		$report = $this->report();

		$this->service->runOne(report: $report);

		$this->assertSame('success', $report->getLastStatus());
		$this->assertNull($report->getLastError());
		$this->assertCount(1, $this->attachments);
		[$csv, $name, $type] = $this->attachments[0];
		$this->assertSame("\xEF\xBB\xBFsection\nattention\n", $csv);
		$this->assertMatchesRegularExpression('/^connection-report-\d{4}-\d{2}-\d{2}\.csv$/', $name);
		$this->assertStringStartsWith('text/csv', $type);
	}//end testAnAttentionDayMailsTheReport()

	/**
	 * A quiet day under onlyWhenAttention sends nothing and records why.
	 *
	 * @return void
	 */
	public function testAQuietDaySendsNothing(): void {
		$this->builder->method('build')->willReturn($this->built(attention: 0));
		$this->mailer->expects($this->never())->method('send');
		$report = $this->report(options: ['onlyWhenAttention' => true, 'lookbackHours' => 24]);

		$this->service->runOne(report: $report);

		$this->assertSame('success', $report->getLastStatus());
		$this->assertSame('Nothing needed attention; no mail sent', $report->getLastError());
	}//end testAQuietDaySendsNothing()

	/**
	 * Without integriq the run fails with that reason and no mail goes out.
	 *
	 * @return void
	 */
	public function testNoIntegriqFailsWithoutAMail(): void {
		$this->builder->method('build')->willThrowException(new ConnectionRegistryMissingException());
		$this->mailer->expects($this->never())->method('send');
		$report = $this->report();

		$this->service->runOne(report: $report);

		$this->assertSame('failed', $report->getLastStatus());
		$this->assertSame('integriq is not installed, so there are no connection rows to report on', $report->getLastError());
	}//end testNoIntegriqFailsWithoutAMail()

	/**
	 * `@admins` goes to the administrators, and a skipped one is named in the run record.
	 *
	 * @return void
	 */
	public function testAdminsAreMailedAndSkippedOnesNamed(): void {
		$withAddress = $this->createMock(IUser::class);
		$withAddress->method('getUID')->willReturn('anna');
		$withAddress->method('getEMailAddress')->willReturn('anna@example.nl');
		$withAddress->method('getDisplayName')->willReturn('Anna');
		$without = $this->createMock(IUser::class);
		$without->method('getUID')->willReturn('ben');
		$without->method('getEMailAddress')->willReturn(null);
		$group = $this->createMock(\OCP\IGroup::class);
		$group->method('getUsers')->willReturn([$withAddress, $without]);
		$this->groups->method('get')->with('admin')->willReturn($group);
		$this->builder->method('build')->willReturn($this->built(attention: 1));
		$this->mailer->expects($this->once())->method('send');
		$report = $this->report(recipients: ['@admins']);

		$this->service->runOne(report: $report);

		$this->assertSame('success', $report->getLastStatus());
		$this->assertSame('Administrators without an e-mail address were skipped: ben.', $report->getLastError());
	}//end testAdminsAreMailedAndSkippedOnesNamed()

	/**
	 * The kind needs no register, is stored as a CSV by e-mail, and refuses Files delivery.
	 *
	 * @return void
	 */
	public function testCreatingAConnectionReport(): void {
		$report = $this->service->create(
			data: ['name' => 'Daily', 'kind' => 'connection-health', 'scheduleType' => 'daily', 'scheduleHour' => 7, 'recipients' => ['@admins'], 'options' => ['onlyWhenAttention' => true]],
			ownerUid: 'admin1'
		);

		$this->assertSame('connection-health', $report->getKind());
		$this->assertNull($report->getRegisterId());
		$this->assertSame('csv', $report->getFormat());
		$this->assertSame('email', $report->getDeliveryMode());
		$this->assertSame(['@admins'], $report->getRecipientsArray());
		$this->assertSame(['onlyWhenAttention' => true, 'lookbackHours' => 24], $report->getOptionsArray());

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A connection-health report is delivered by e-mail.');
		$this->service->create(
			data: ['name' => 'Daily', 'kind' => 'connection-health', 'scheduleType' => 'daily', 'deliveryMode' => 'files'],
			ownerUid: 'admin1'
		);
	}//end testCreatingAConnectionReport()

	/**
	 * An unknown kind is refused, and an export report still needs its register.
	 *
	 * @return void
	 */
	public function testAnUnknownKindAndAnExportWithoutRegisterAreRefused(): void {
		try {
			$this->service->create(data: ['name' => 'X', 'kind' => 'gossip', 'scheduleType' => 'daily'], ownerUid: 'admin1');
			$this->fail('An unknown kind was accepted.');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('kind must be one of: export, connection-health', $e->getMessage());
		}

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A registerId is required.');
		$this->service->create(data: ['name' => 'X', 'scheduleType' => 'daily', 'format' => 'csv'], ownerUid: 'admin1');
	}//end testAnUnknownKindAndAnExportWithoutRegisterAreRefused()

	/**
	 * A non-administrator cannot change a connection report, nor turn a report into one.
	 *
	 * @return void
	 */
	public function testANonAdministratorCannotChangeTheKind(): void {
		$stored = $this->report();
		$stored->setOwner('casey');
		$this->mapper->method('find')->willReturn($stored);

		try {
			$this->service->applyUpdate(update: new ScheduledReportUpdate(id: 1, data: ['name' => 'Mine now'], caller: new ReportCaller(uid: 'casey', isAdmin: false)));
			$this->fail('A non-administrator changed a connection report.');
		} catch (ConnectionReportForbiddenException $e) {
			$this->assertSame('Daily connection report', $stored->getName());
		}

		$updated = $this->service->applyUpdate(update: new ScheduledReportUpdate(id: 1, data: ['name' => 'Renamed'], caller: new ReportCaller(uid: 'admin1', isAdmin: true)));
		$this->assertSame('Renamed', $updated->getName());
	}//end testANonAdministratorCannotChangeTheKind()
}//end class
