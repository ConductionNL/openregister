<?php

/**
 * Characterisation golden for the owner-or-admin gate of ScheduledReportService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Kernel\Contracts\ReportCaller;
use OCA\OpenRegister\Notifications\Db\ScheduledReport;
use OCA\OpenRegister\Notifications\Db\ScheduledReportMapper;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ScheduledReportDelivery;
use OCA\OpenRegister\Service\ScheduledReportExporters;
use OCA\OpenRegister\Service\ScheduledReportOwnerServices;
use OCA\OpenRegister\Service\ScheduledReportService;
use OCA\OpenRegister\Service\ScheduledReportStores;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Mail\IMailer;
use OCP\Notification\IManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins who may delete a scheduled report and who passes the owner-or-admin gate.
 */
class ScheduledReportServiceAccessGoldenTest extends TestCase {

	/**
	 * The ids the mapper was asked to delete.
	 *
	 * @var array<int, int>
	 */
	private array $deleted = [];

	/**
	 * The service under test.
	 *
	 * @var ScheduledReportService
	 */
	private ScheduledReportService $service;

	/**
	 * Build the service over a mapper holding one report owned by alice.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$mapper = $this->createMock(ScheduledReportMapper::class);
		$mapper->method('find')->willReturnCallback(fn (int $id): ScheduledReport => $this->report(owner: 'alice', id: $id));
		$mapper->method('delete')->willReturnCallback(
			function (ScheduledReport $report): ScheduledReport {
				$this->deleted[] = $report->getId();
				return $report;
			}
		);
		$this->service = new ScheduledReportService(
			new ScheduledReportStores($mapper, new SchemaCatalog(registers: $this->createMock(RegisterMapper::class), schemas: $this->createMock(SchemaMapper::class), tables: $this->createMock(MagicMapperInterface::class))),
			new ScheduledReportExporters($this->createMock(ExportService::class), null, null),
			new ScheduledReportDelivery(
				new ScheduledReportOwnerServices($this->createMock(IUserManager::class), $this->createMock(IUserSession::class), $this->createMock(IRootFolder::class), $this->createMock(IManager::class)),
				$this->createMock(IMailer::class),
				$this->createMock(IConfig::class)
			),
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * A stored report.
	 *
	 * @param string $owner The owner uid.
	 * @param int    $id    The report id.
	 *
	 * @return ScheduledReport
	 */
	private function report(string $owner, int $id): ScheduledReport {
		$report = new ScheduledReport();
		$report->setId($id);
		$report->setOwner($owner);
		return $report;
	}//end report()

	/**
	 * The outcome of a call: 'allowed' or the refusal class and message.
	 *
	 * @param callable $call The call.
	 *
	 * @return string
	 */
	private function outcome(callable $call): string {
		try {
			$call();
			return 'allowed';
		} catch (RuntimeException $e) {
			return get_class($e).': '.$e->getMessage();
		}
	}//end outcome()

	/**
	 * The owner and an admin may delete; anyone else is refused before the delete.
	 *
	 * @return void
	 */
	public function testDelete(): void {
		$refused = RuntimeException::class.': You do not have permission to access this scheduled report.';
		$this->assertSame('allowed', $this->outcome(fn () => $this->service->deleteAs(id: 4, caller: new ReportCaller(uid: 'alice', isAdmin: false))));
		$this->assertSame('allowed', $this->outcome(fn () => $this->service->deleteAs(id: 5, caller: new ReportCaller(uid: 'bob', isAdmin: true))));
		$this->assertSame($refused, $this->outcome(fn () => $this->service->deleteAs(id: 6, caller: new ReportCaller(uid: 'bob', isAdmin: false))));
		$this->assertSame($refused, $this->outcome(fn () => $this->service->deleteAs(id: 7, caller: new ReportCaller(uid: '', isAdmin: false))));
		$this->assertSame([4, 5], $this->deleted);
	}//end testDelete()

	/**
	 * The gate on its own: owner or admin passes, a near-miss uid does not.
	 *
	 * @return void
	 */
	public function testAssertOwnerOrAdmin(): void {
		$refused = RuntimeException::class.': You do not have permission to access this scheduled report.';
		$report  = $this->report(owner: 'alice', id: 1);
		$this->assertSame('allowed', $this->outcome(fn () => $this->service->assertMayAccess(report: $report, caller: new ReportCaller(uid: 'alice', isAdmin: false))));
		$this->assertSame('allowed', $this->outcome(fn () => $this->service->assertMayAccess(report: $report, caller: new ReportCaller(uid: 'carol', isAdmin: true))));
		$this->assertSame($refused, $this->outcome(fn () => $this->service->assertMayAccess(report: $report, caller: new ReportCaller(uid: 'Alice', isAdmin: false))));
		$this->assertSame($refused, $this->outcome(fn () => $this->service->assertMayAccess(report: $report, caller: new ReportCaller(uid: 'alice ', isAdmin: false))));
	}//end testAssertOwnerOrAdmin()
}//end class
