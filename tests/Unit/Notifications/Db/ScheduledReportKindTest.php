<?php

/**
 * Unit tests for the report kinds a scheduled report owns.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Db
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

namespace OCA\OpenRegister\Tests\Unit\Notifications\Db;

use OCA\OpenRegister\Notifications\Db\ScheduledReport;
use OCA\OpenRegister\Service\Connection\ConnectionReportKind;
use PHPUnit\Framework\TestCase;

/**
 * The scheduled report owns its kind values; the connection report kind reads them.
 *
 * @covers \OCA\OpenRegister\Notifications\Db\ScheduledReport
 */
final class ScheduledReportKindTest extends TestCase {

	/**
	 * The stored values stay the ones rows and requests carry.
	 *
	 * @return void
	 */
	public function testTheKindValuesAreTheStoredOnes(): void {
		$this->assertSame('export', ScheduledReport::KIND_EXPORT);
		$this->assertSame('connection-health', ScheduledReport::KIND_CONNECTION_HEALTH);
	}//end testTheKindValuesAreTheStoredOnes()

	/**
	 * The connection report kind names the same values.
	 *
	 * @return void
	 */
	public function testTheConnectionReportKindNamesTheSameValues(): void {
		$this->assertSame(ScheduledReport::KIND_EXPORT, ConnectionReportKind::EXPORT);
		$this->assertSame(ScheduledReport::KIND_CONNECTION_HEALTH, ConnectionReportKind::CONNECTION_HEALTH);
		$this->assertSame([ScheduledReport::KIND_EXPORT, ScheduledReport::KIND_CONNECTION_HEALTH], ConnectionReportKind::KINDS);
	}//end testTheConnectionReportKindNamesTheSameValues()

	/**
	 * A report without a stored kind is an export.
	 *
	 * @return void
	 */
	public function testAReportWithoutAKindIsAnExport(): void {
		$report = new ScheduledReport();
		$this->assertSame(ScheduledReport::KIND_EXPORT, $report->getKindOrDefault());

		$report->setKind('');
		$this->assertSame(ScheduledReport::KIND_EXPORT, $report->getKindOrDefault());

		$report->setKind(ScheduledReport::KIND_CONNECTION_HEALTH);
		$this->assertSame(ScheduledReport::KIND_CONNECTION_HEALTH, $report->getKindOrDefault());
	}//end testAReportWithoutAKindIsAnExport()
}//end class
