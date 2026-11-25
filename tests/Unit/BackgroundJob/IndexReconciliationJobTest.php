<?php

/**
 * Tests for IndexReconciliationJob: the daily run of the index probes, through run().
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use OCA\OpenRegister\BackgroundJob\IndexReconciliationJob;
use OCA\OpenRegister\BackgroundJob\RecordedJobRuntime;
use OCA\OpenRegister\Service\Operations\ConsistencyCheckService;
use OCA\OpenRegister\Service\Operations\IndexReconciliationReport;
use OCA\OpenRegister\Service\Operations\JobRunRecorderInterface;
use OCA\OpenRegister\Service\Operations\JobRunStart;
use OCA\OpenRegister\Service\Operations\JobScheduleServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversClass(IndexReconciliationJob::class)]
#[CoversClass(IndexReconciliationReport::class)]
final class IndexReconciliationJobTest extends TestCase {

	/** @var array<string, string> The app config store. */
	private array $store = [];

	/** @var list<string> The job classes whose runs were recorded. */
	private array $recorded = [];

	private bool $mayRun = true;

	/**
	 * A check over fixture probes: two index probes with rows and one shipped probe
	 * the report must leave out.
	 */
	private function check(): ConsistencyCheckService {
		$rows = [
			'filecache' => array_map(static fn (int $id): array => ['fileid' => $id], range(1, 25)),
			'openregister_chunks' => [['id' => 7, 'source_id' => 99]],
			'openregister_object_relations' => [['id' => 1]],
		];
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(function () use ($rows): IQueryBuilder {
			$from = '';
			$qb = $this->createMock(IQueryBuilder::class);
			$qb->method('from')->willReturnCallback(function (string $table) use ($qb, &$from): IQueryBuilder {
				$from = $table;
				return $qb;
			});
			$qb->method('getSQL')->willReturn('SELECT 1');
			$qb->method('setMaxResults')->willReturnSelf();
			$qb->method('executeQuery')->willReturnCallback(function () use ($rows, &$from): IResult {
				$result = $this->createMock(IResult::class);
				$result->method('fetchAll')->willReturn($rows[$from] ?? []);
				return $result;
			});
			return $qb;
		});
		$probe = static fn (string $table): array => [
			'title' => 'Probe of ' . $table,
			'description' => 'd',
			'table' => $table,
			'query' => static fn (IQueryBuilder $qb): IQueryBuilder => $qb->from($table),
		];

		return new ConsistencyCheckService(
			$db,
			[
				'orphan-relations' => $probe('openregister_object_relations'),
				'index-file-missing' => $probe('filecache'),
				'index-chunk-orphaned' => $probe('openregister_chunks'),
				'index-embedding-missing' => $probe('openregister_chunks') + ['skip' => 'Vectorisation is switched off, so missing embeddings are not checked.'],
			]
		);
	}

	private function report(): IndexReconciliationReport {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => $this->store[$key] ?? $default);
		$appConfig->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->store[$key] = $value;
			return true;
		});

		return new IndexReconciliationReport($this->check(), $appConfig);
	}

	private function job(IndexReconciliationReport $report): IndexReconciliationJob {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-11-25T03:00:00+00:00'));
		$recorder = $this->createMock(JobRunRecorderInterface::class);
		$recorder->method('around')->willReturnCallback(function (JobRunStart $start, callable $work): mixed {
			$this->recorded[] = $start->jobClass;
			$work();
			return null;
		});
		$schedule = $this->createMock(JobScheduleServiceInterface::class);
		$schedule->method('mayRun')->willReturnCallback(fn (): bool => $this->mayRun);

		return new IndexReconciliationJob(
			runtime: new RecordedJobRuntime(time: $time, recorder: $recorder),
			schedule: $schedule,
			report: $report
		);
	}

	public function testItRunsDailyAndStoresTheIndexProbesReportWithItsRunTime(): void {
		$report = $this->report();
		$job = $this->job($report);

		(new ReflectionMethod($job, 'run'))->invoke($job, null);

		$this->assertSame(IndexReconciliationJob::INTERVAL_SECONDS, $job->getInterval());
		$this->assertSame(86400, IndexReconciliationJob::INTERVAL_SECONDS);
		$this->assertSame([IndexReconciliationJob::class], $this->recorded, 'the run is recorded as this job');

		$stored = $report->latest();
		$this->assertSame('2026-11-25T03:00:00+00:00', $stored['ranAt']);
		$this->assertSame(['index-file-missing', 'index-chunk-orphaned', 'index-embedding-missing'], array_column($stored['findings'], 'slug'), 'only the index probes, not the shipped ones');

		$missing = $stored['findings'][0];
		$this->assertSame(25, $missing['count']);
		$this->assertCount(IndexReconciliationReport::SAMPLE_SIZE, $missing['samples'], 'at most 20 sample rows are kept');
		$this->assertSame(['fileid' => 1], $missing['samples'][0]);
		$this->assertNull($missing['unavailable']);

		$this->assertSame([['id' => 7, 'source_id' => 99]], $stored['findings'][1]['samples']);
		$this->assertSame('Vectorisation is switched off, so missing embeddings are not checked.', $stored['findings'][2]['unavailable']);
		$this->assertSame(0, $stored['findings'][2]['count']);
		$this->assertArrayHasKey(IndexReconciliationReport::CONFIG_KEY, $this->store);
	}

	public function testAJobOutsideItsScheduleStoresNothing(): void {
		$this->mayRun = false;
		$report = $this->report();
		$job = $this->job($report);

		(new ReflectionMethod($job, 'run'))->invoke($job, null);

		$this->assertSame([], $this->recorded);
		$this->assertNull($report->latest());
	}

	public function testABrokenStoredReportReadsAsNone(): void {
		$report = $this->report();

		$this->store[IndexReconciliationReport::CONFIG_KEY] = '{"findings": "not a list"}';
		$this->assertNull($report->latest());

		$this->store[IndexReconciliationReport::CONFIG_KEY] = 'not json';
		$this->assertNull($report->latest());
	}
}
