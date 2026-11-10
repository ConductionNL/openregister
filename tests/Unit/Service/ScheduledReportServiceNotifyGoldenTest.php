<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Notifications\Db\ScheduledReport;
use OCA\OpenRegister\Notifications\Db\ScheduledReportMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\ExportTooLargeException;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ScheduledReportService;
use OCA\OpenRegister\Service\ScheduledReportDelivery;
use OCA\OpenRegister\Service\ScheduledReportExporters;
use OCA\OpenRegister\Service\ScheduledReportOwnerServices;
use OCA\OpenRegister\Service\ScheduledReportStores;
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

/**
 * Records the owner notification and the stored outcome of runOne(), per scenario, against
 * fixtures/scheduled-report-notify-golden.json. Set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Service\ScheduledReportService
 * @uses   \OCA\OpenRegister\Notifications\Db\ScheduledReport
 * @uses   \OCA\OpenRegister\Service\ScheduledReportRun
 * @uses   \OCA\OpenRegister\Exception\ExportTooLargeException
 */
class ScheduledReportServiceNotifyGoldenTest extends TestCase {

	/**
	 * @var list<array>
	 */
	private array $calls = [];


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

		$this->notificationManager->method('createNotification')->willReturnCallback(function () {
			$notification = $this->createMock(INotification::class);
			foreach (['setApp', 'setUser', 'setDateTime'] as $setter) {
				$notification->method($setter)->willReturnSelf();
			}
			$notification->method('setObject')->willReturnCallback(function ($type, $id) use ($notification) {
				$this->calls[] = ['notify.object', $type, $id];
				return $notification;
			});
			$notification->method('setSubject')->willReturnCallback(function ($subject, $parameters) use ($notification) {
				$this->calls[] = ['notify.subject', $subject, $parameters];
				return $notification;
			});
			return $notification;
		});
		$this->notificationManager->method('notify')->willReturnCallback(function () {
			$this->calls[] = ['notify.sent'];
		});

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
			new ScheduledReportStores($this->mapper, new \OCA\OpenRegister\Db\SchemaCatalog(registers: $this->registerMapper, schemas: $this->schemaMapper, tables: $this->createMock(\OCA\OpenRegister\Db\MagicMapperInterface::class))),
			new ScheduledReportExporters($this->exportService, null, null),
			new ScheduledReportDelivery(
				new ScheduledReportOwnerServices($this->userManager, $this->userSession, $this->rootFolder, $this->notificationManager),
				$this->mailer,
				$this->config
			),
			$this->createMock(LoggerInterface::class)
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

	/**
	 * Scenario table.
	 *
	 * @return array<string, array>
	 */
	public static function scenarios(): array {
		return [
			'files delivered' => ['files', 'ok'],
			'email delivered' => ['email', 'ok'],
			'both, email fails' => ['both', 'mailFails'],
			'email only fails' => ['email', 'mailFails'],
			'export too large' => ['files', 'tooLarge'],
			'export throws' => ['files', 'throws'],
		];
	}

	/**
	 * @dataProvider scenarios
	 */
	public function testNotifyGolden(string $mode, string $case): void {
		$report = $this->makeReport($mode);
		$this->stubPermissiveFilesFolder();
		if ($case === 'tooLarge') {
			$this->exportService->method('exportToCsv')->willThrowException(new ExportTooLargeException(50001, 50000));
		} elseif ($case === 'throws') {
			$this->exportService->method('exportToCsv')->willThrowException(new \RuntimeException('export broke'));
		} else {
			$this->exportService->method('exportToCsv')->willReturn("id,name\n1,a");
		}
		if ($case === 'mailFails') {
			$this->mailer->method('send')->willThrowException(new \RuntimeException('smtp down'));
		}

		$this->service->runOne(report: $report);
		$this->calls[] = ['outcome', $report->getLastStatus(), $report->getLastError()];
		$actual = json_decode((string)preg_replace('/_\d{4}-\d{2}-\d{2}/', '_DATE', json_encode($this->calls)), true);

		$file = __DIR__ . '/fixtures/scheduled-report-notify-golden.json';
		$name = $this->dataName();
		$all = is_file($file) === true ? json_decode(file_get_contents($file), true) : [];
		if (getenv('UPDATE_GOLDEN') === '1') {
			$all[$name] = $actual;
			file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}
		$this->assertSame($all[$name], $actual);
	}
}
