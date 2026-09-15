<?php

/**
 * ScheduledReportServiceProfileRunTest
 *
 * A schedule may name an export profile instead of a format plus a filter map.
 * Two things are asserted, and nothing else is mocked into existence: the
 * profile is run as the schedule's OWNER, not as whoever happened to trip the
 * cron, and the delivered filename takes the profile's format rather than the
 * schedule's.
 *
 * The third test is the one that would otherwise be a silent no-op: a schedule
 * naming a profile the runner cannot load must fail loudly instead of quietly
 * exporting the plain format export instead.
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
 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\ExportProfile;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\ScheduledReport;
use OCA\OpenRegister\Db\ScheduledReportMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Export\ExportProfileService;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ScheduledReportService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Mail\IMailer;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ScheduledReportServiceProfileRunTest extends TestCase {

	private ScheduledReportMapper&MockObject $mapper;

	private ExportService&MockObject $exportService;

	private IRootFolder&MockObject $rootFolder;

	private IUserManager&MockObject $userManager;

	private IManager&MockObject $notificationManager;

	/**
	 * The filenames handed to the Files delivery.
	 *
	 * @var array<int, string>
	 */
	private array $delivered = [];

	protected function setUp(): void {
		parent::setUp();

		$this->mapper = $this->createMock(ScheduledReportMapper::class);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->exportService = $this->createMock(ExportService::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->notificationManager = $this->createMock(IManager::class);
		$this->notificationManager->method('createNotification')
			->willReturn($this->createMock(INotification::class));
		$this->delivered = [];
	}//end setUp()

	private function service(?ExportProfileService $profiles): ScheduledReportService {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(
			static fn ($key, $default = null) => $default
		);

		return new ScheduledReportService(
			$this->mapper,
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->exportService,
			$this->rootFolder,
			$this->userManager,
			$this->createMock(IUserSession::class),
			$this->notificationManager,
			new NullLogger(),
			$this->createMock(IMailer::class),
			$config,
			$profiles
		);
	}//end service()

	private function report(): ScheduledReport {
		$report = new ScheduledReport();
		$reflection = new \ReflectionClass($report);
		$id = $reflection->getProperty('id');
		$id->setAccessible(true);
		$id->setValue($report, 7);

		$report->setOwner('eigenaar-1');
		$report->setName('Maandelijkse aanlevering');
		$report->setRegisterId(1);
		$report->setSchemaId(2);
		$report->setFilters('[]');
		// The schedule says excel. The profile says json, and the profile wins.
		$report->setFormat('excel');
		$report->setProfileId(42);
		$report->setScheduleType('monthly');
		$report->setScheduleHour(3);
		$report->setDeliveryFolder('Reports/');
		$report->setEnabled(true);

		return $report;
	}//end report()

	private function mockOwnerFolder(): void {
		$owner = $this->createMock(IUser::class);
		$owner->method('getUID')->willReturn('eigenaar-1');
		$this->userManager->method('get')->willReturn($owner);

		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willReturn(false);
		$folder->method('newFile')->willReturnCallback(
			function (...$args) {
				$this->delivered[] = (string)$args[0];

				return $this->createMock(\OCP\Files\File::class);
			}
		);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('nodeExists')->willReturn(true);
		$userFolder->method('get')->willReturn($folder);

		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);
	}//end mockOwnerFolder()

	private function profileService(): ExportProfileService&MockObject {
		$profile = new ExportProfile();
		$profile->setName('Maandelijkse aanlevering');
		$profile->setFormat('json');
		$profile->setValueMode(ExportProfile::MODE_RENDERED);

		$profiles = $this->createMock(ExportProfileService::class);
		$profiles->method('find')->willReturn($profile);

		return $profiles;
	}//end profileService()

	public function testAScheduledExportRunsAsItsOwner(): void {
		$this->mockOwnerFolder();

		$seen = null;
		$profiles = $this->profileService();
		$profiles->method('run')->willReturnCallback(
			function (...$args) use (&$seen): array {
				$seen = $args[1];

				return ['bytes' => '{"results":[]}', 'rowCount' => 3, 'metadata' => [], 'filename' => 'x.json'];
			}
		);

		// The plain export path must not run at all. If it did, the file would
		// hold whatever the schedule's own format and filters produce, which is
		// exactly the drift naming a profile is meant to end.
		$this->exportService->expects(self::never())->method('exportToCsv');
		$this->exportService->expects(self::never())->method('exportToExcel');

		$report = $this->report();
		$this->service($profiles)->runOne(report: $report);

		self::assertSame('eigenaar-1', $seen);
		self::assertSame('success', $report->getLastStatus());
	}//end testAScheduledExportRunsAsItsOwner()

	public function testTheDeliveredFileTakesTheProfilesFormat(): void {
		$this->mockOwnerFolder();

		$profiles = $this->profileService();
		$profiles->method('run')->willReturn(
			['bytes' => '{"results":[]}', 'rowCount' => 3, 'metadata' => [], 'filename' => 'x.json']
		);

		$this->service($profiles)->runOne(report: $this->report());

		self::assertCount(1, $this->delivered);
		self::assertStringEndsWith('.json', $this->delivered[0]);
	}//end testTheDeliveredFileTakesTheProfilesFormat()

	public function testAProfileTheRunnerCannotLoadFailsLoudly(): void {
		$owner = $this->createMock(IUser::class);
		$owner->method('getUID')->willReturn('eigenaar-1');
		$this->userManager->method('get')->willReturn($owner);

		// No profile service at all. The run must not fall through to the plain
		// format export and report success over a file nobody asked for.
		$this->exportService->expects(self::never())->method('exportToExcel');

		$report = $this->report();
		$this->service(null)->runOne(report: $report);

		self::assertSame('failed', $report->getLastStatus());
		self::assertStringContainsString('export profile 42', (string)$report->getLastError());
	}//end testAProfileTheRunnerCannotLoadFailsLoudly()
}//end class
