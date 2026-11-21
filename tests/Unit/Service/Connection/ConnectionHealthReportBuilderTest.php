<?php

/**
 * ConnectionHealthReportBuilder unit tests.
 *
 * Each attention rule, the runs per source system, the subject, the formula-safe
 * CSV, the stated caps, a source's configuration never reaching the report, and
 * the missing register failing instead of reporting "all clear".
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Connection
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
 * @spec openspec/changes/connections-daily-report-mail/specs/scheduled-report-jobs/spec.md#requirement-the-report-says-what-needs-attention-and-what-each-source-delivered
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Connection;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Connection\ConnectionHealthReport;
use OCA\OpenRegister\Service\Connection\ConnectionHealthReportBuilder;
use OCA\OpenRegister\Service\Connection\ConnectionRegistryMissingException;
use OCA\OpenRegister\Service\Connection\ConnectionRegistryReader;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the connection report's content.
 *
 * @covers \OCA\OpenRegister\Service\Connection\ConnectionHealthReportBuilder
 * @uses   \OCA\OpenRegister\Service\Connection\ConnectionHealthReport
 */
final class ConnectionHealthReportBuilderTest extends TestCase {

	/**
	 * The report time: 2026-11-13T07:00:00Z.
	 */
	private const NOW = '2026-11-13T07:00:00+00:00';

	/**
	 * Build the report over the given rows per schema.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rows     Rows by schema slug.
	 * @param int                                             $lookback The lookback in hours.
	 *
	 * @return ConnectionHealthReport
	 */
	private function build(array $rows, int $lookback = 24): ConnectionHealthReport {
		$reader = $this->createMock(ConnectionRegistryReader::class);
		$reader->method('rows')->willReturnCallback(
			static fn (string $schemaSlug, int $limit, array $sort = []): array => array_slice(($rows[$schemaSlug] ?? []), 0, $limit)
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		return (new ConnectionHealthReportBuilder(reader: $reader, l10n: $l10n))->build(lookbackHours: $lookback, now: new DateTimeImmutable(self::NOW));
	}//end build()

	/**
	 * A run row.
	 *
	 * @param string               $sync    The synchronization id.
	 * @param string               $status  The status.
	 * @param string               $started When it started.
	 * @param array<string, mixed> $fields  More fields.
	 *
	 * @return array<string, mixed>
	 */
	private function runRow(string $sync, string $status, string $started, array $fields = []): array {
		return array_merge(['synchronizationId' => $sync, 'status' => $status, 'startedAt' => $started], $fields);
	}//end runRow()

	/**
	 * The names of one synchronization and its source; the source carries a secret that must not leak.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function names(): array {
		return [
			'source' => [['id' => 'src-1', 'name' => 'Zaaksysteem', 'configuration' => ['apikey' => 'TOP-SECRET-KEY']]],
			'synchronization' => [['id' => 'sync-1', 'name' => 'Publicaties uit Zaaksysteem', 'sourceId' => 'src-1']],
		];
	}//end names()

	/**
	 * The spec's morning: 24 runs, 2 failed with 401, and an unavailable llm connection.
	 *
	 * @return void
	 */
	public function testAMorningWithOneFailingPull(): void {
		$runs = [];
		$first = strtotime('2026-11-12T07:30:00+00:00');
		for ($hour = 0; $hour < 24; $hour++) {
			$started = ($first + ($hour * 3600));
			$status = 'success';
			$fields = ['finishedAt' => gmdate('Y-m-d\\TH:i:sP', ($started + 60)), 'found' => 3, 'created' => 1, 'updated' => 2, 'invalid' => 0];
			if ($hour === 5 || $hour === 6) {
				$status = 'failed';
				$fields['message'] = '401 Unauthorized';
			}

			$runs[] = $this->runRow(sync: 'sync-1', status: $status, started: gmdate('Y-m-d\\TH:i:sP', $started), fields: $fields);
		}

		$report = $this->build(
			array_merge(
				$this->names(),
				[
					'synchronization_run' => array_reverse($runs),
					'app_connection' => [
						['app' => 'openregister', 'key' => 'llm', 'title' => 'LLM', 'status' => 'unavailable', 'statusMessage' => 'No provider configured', 'settingsUrl' => '/settings/admin/openregister'],
						['app' => 'openregister', 'key' => 'search', 'title' => 'Search', 'status' => 'configured'],
					],
				]
			)
		);

		$this->assertSame(2, $report->attention);
		$this->assertSame('Connection report 2026-11-13: 2 need attention', $report->subject);
		$this->assertSame('Needs attention', $report->lines[0]);
		$this->assertSame('openregister / LLM: unavailable. No provider configured  /settings/admin/openregister', $report->lines[1]);
		$this->assertSame('Zaaksysteem / Publicaties uit Zaaksysteem: 2 of 24 runs failed. 401 Unauthorized 2026-11-13T06:31:00+00:00', $report->lines[2]);
		$this->assertContains(
			'Zaaksysteem / Publicaties uit Zaaksysteem: 24 runs, 22 succeeded, 2 failed, 72 found, 24 created, 48 updated, 0 invalid, last finished 2026-11-13T06:31:00+00:00.',
			$report->lines
		);
		$this->assertContains('openregister: Search (configured)', $report->lines);
		$this->assertStringContainsString("\nruns,,Zaaksysteem,\"Publicaties uit Zaaksysteem\",,24,22,2,72,24,48,0,2026-11-13T06:31:00+00:00,\"401 Unauthorized\"\n", $report->csv);
	}//end testAMorningWithOneFailingPull()

	/**
	 * Every connection status that needs attention does, and the others do not.
	 *
	 * @return void
	 */
	public function testConnectionStatusesThatNeedAttention(): void {
		$report = $this->build(
			[
				'app_connection' => [
					['app' => 'a', 'key' => 'e', 'status' => 'error'],
					['app' => 'a', 'key' => 'u', 'status' => 'unavailable'],
					['app' => 'a', 'key' => 'l', 'status' => 'limited'],
					['app' => 'a', 'key' => 's', 'status' => 'simulated'],
					['app' => 'a', 'key' => 'r', 'status' => 'simulated', 'reportedOnly' => true],
					['app' => 'a', 'key' => 'c', 'status' => 'configured'],
					['app' => 'a', 'key' => 'n', 'status' => 'not-configured'],
				],
			]
		);

		$this->assertSame(4, $report->attention);
		foreach (['a / e: error.', 'a / u: unavailable.', 'a / l: limited.', 'a / s: simulated.'] as $line) {
			$this->assertContains($line, $report->lines);
		}

		$this->assertNotContains('a / r: simulated.', $report->lines);
	}//end testConnectionStatusesThatNeedAttention()

	/**
	 * A synchronization that ran in the window before the lookback and not in it stopped running.
	 *
	 * @return void
	 */
	public function testASynchronizationThatStoppedRunning(): void {
		$report = $this->build(
			array_merge(
				$this->names(),
				['synchronization_run' => [$this->runRow(sync: 'sync-1', status: 'success', started: '2026-11-11T20:00:00+00:00')]]
			)
		);

		$this->assertSame(1, $report->attention);
		$this->assertSame(
			'Zaaksysteem / Publicaties uit Zaaksysteem: stopped running. No run in this period; the last one started at 2026-11-11T20:00:00+00:00. 2026-11-11T20:00:00+00:00',
			$report->lines[1]
		);
		$this->assertContains('No scheduled pulls ran in this period.', $report->lines);
	}//end testASynchronizationThatStoppedRunning()

	/**
	 * A run still running after six hours is stuck; one running for five is not.
	 *
	 * @return void
	 */
	public function testARunStillRunningAfterSixHoursIsStuck(): void {
		$report = $this->build(
			[
				'synchronization_run' => [
					$this->runRow(sync: 'sync-a', status: 'running', started: '2026-11-13T00:59:59+00:00'),
					$this->runRow(sync: 'sync-b', status: 'running', started: '2026-11-13T02:00:00+00:00'),
				],
			]
		);

		$this->assertSame(1, $report->attention);
		$this->assertSame('sync-a: stuck. Still running after more than 6 hours. 2026-11-13T00:59:59+00:00', $report->lines[1]);
	}//end testARunStillRunningAfterSixHoursIsStuck()

	/**
	 * A quiet day is all clear.
	 *
	 * @return void
	 */
	public function testAQuietDayIsAllClear(): void {
		$report = $this->build(
			array_merge(
				$this->names(),
				[
					'synchronization_run' => [$this->runRow(sync: 'sync-1', status: 'success', started: '2026-11-13T06:00:00+00:00')],
					'app_connection' => [['app' => 'a', 'key' => 'c', 'status' => 'configured']],
				]
			)
		);

		$this->assertSame(0, $report->attention);
		$this->assertSame('Connection report 2026-11-13: all clear', $report->subject);
		$this->assertSame(['Needs attention', 'Nothing needs attention.'], array_slice($report->lines, 0, 2));
		$this->assertSame(2, $report->rowCount);
	}//end testAQuietDayIsAllClear()

	/**
	 * The CSV starts with a byte order mark and a header, and no cell can start a formula.
	 *
	 * @return void
	 */
	public function testTheCsvIsFormulaSafe(): void {
		$report = $this->build(['app_connection' => [['app' => '=HYPERLINK("x")', 'key' => 'k', 'title' => '+1', 'status' => 'error', 'statusMessage' => '@cmd']]]);

		$this->assertStringStartsWith("\xEF\xBB\xBFsection,app,source,name,status,runs,succeeded,failed,found,created,updated,invalid,last,message\n", $report->csv);
		$lines = explode("\n", trim(substr($report->csv, 3)));
		$this->assertSame('attention,"\'=HYPERLINK(""x"")",,\'+1,error,,,,,,,,,\'@cmd', $lines[1]);
		$this->assertSame('connections,"\'=HYPERLINK(""x"")",,\'+1,error,,,,,,,,,', $lines[2]);
	}//end testTheCsvIsFormulaSafe()

	/**
	 * A source's configuration never reaches the mail or the CSV.
	 *
	 * @return void
	 */
	public function testASourceSecretNeverReachesTheReport(): void {
		$report = $this->build(
			array_merge($this->names(), ['synchronization_run' => [$this->runRow(sync: 'sync-1', status: 'failed', started: '2026-11-13T06:00:00+00:00')]])
		);

		$this->assertStringNotContainsString('TOP-SECRET-KEY', $report->csv);
		$this->assertStringNotContainsString('TOP-SECRET-KEY', implode("\n", $report->lines));
		$this->assertStringContainsString('Zaaksysteem', $report->csv);
	}//end testASourceSecretNeverReachesTheReport()

	/**
	 * A read cap that was hit is stated in the mail.
	 *
	 * @return void
	 */
	public function testAHitCapIsStated(): void {
		$runs = array_fill(0, ConnectionHealthReportBuilder::RUN_CAP, $this->runRow(sync: 'sync-1', status: 'success', started: '2026-11-13T06:00:00+00:00'));

		$report = $this->build(['synchronization_run' => $runs]);

		$this->assertSame('5000 runs shown; more ran.', $report->lines[array_key_last($report->lines)]);
	}//end testAHitCapIsStated()

	/**
	 * Without the connection registry the build fails rather than reporting all clear.
	 *
	 * @return void
	 */
	public function testAMissingRegistryFailsTheBuild(): void {
		$reader = $this->createMock(ConnectionRegistryReader::class);
		$reader->method('rows')->willThrowException(new ConnectionRegistryMissingException());

		$this->expectException(ConnectionRegistryMissingException::class);
		$this->expectExceptionMessage('integriq is not installed, so there are no connection rows to report on');

		(new ConnectionHealthReportBuilder(reader: $reader, l10n: $this->createMock(IL10N::class)))->build(lookbackHours: 24, now: new DateTimeImmutable(self::NOW));
	}//end testAMissingRegistryFailsTheBuild()
}//end class
