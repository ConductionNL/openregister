<?php

/**
 * The flow-parallel-streams back-fill, applied twice over a seeded database
 * holding in-flight multi-token runs, leaves identical tables.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-parallel-streams/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Migration;

use DateTime;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowStream;
use OCA\OpenRegister\Migration\Version1Date20260901120000;
use OCP\DB\IResult;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * The back-fill over an in-memory runs/streams/steps store.
 *
 * Unlike {@see Version1Date20260901120000Test}, which counts statements, this
 * drives the back-fill against tables that keep what is written, so a second
 * application reads the first one's streams and stamps exactly as a real
 * database would hand them back.
 *
 * @covers \OCA\OpenRegister\Migration\Version1Date20260901120000
 * @uses \OCA\OpenRegister\Db\FlowStream
 */
final class Version1Date20260901120000BackFillTwiceTest extends TestCase {

	private const RUNS = 'openregister_flow_runs';

	private const STREAMS = 'openregister_flow_streams';

	private const STEPS = 'openregister_flow_steps';

	/**
	 * The in-memory tables, keyed by table name.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $tables = [];

	/**
	 * Seed the store: two in-flight multi-token runs, a queued run that never
	 * started, and a completed run whose history is still unstamped.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->tables = [
			self::RUNS => [
				['uuid' => 'run-a', 'status' => FlowRun::STATUS_RUNNING, 'marking' => json_encode(['review' => 1, 'archive' => 1, 'publish' => 2]), 'resume_at' => null],
				['uuid' => 'run-b', 'status' => FlowRun::STATUS_SUSPENDED, 'marking' => json_encode(['wait-signal', 'notify']), 'resume_at' => '2026-09-03 10:00:00'],
				['uuid' => 'run-c', 'status' => FlowRun::STATUS_QUEUED, 'marking' => null, 'resume_at' => null],
				['uuid' => 'run-d', 'status' => FlowRun::STATUS_COMPLETED, 'marking' => json_encode(['end' => 1]), 'resume_at' => null],
			],
			self::STREAMS => [],
			self::STEPS => [
				['run_uuid' => 'run-a', 'sequence' => 1, 'ordinal_path' => null, 'stream_id' => null],
				['run_uuid' => 'run-a', 'sequence' => 2, 'ordinal_path' => null, 'stream_id' => null],
				['run_uuid' => 'run-a', 'sequence' => 3, 'ordinal_path' => null, 'stream_id' => null],
				['run_uuid' => 'run-b', 'sequence' => 1, 'ordinal_path' => null, 'stream_id' => null],
				['run_uuid' => 'run-b', 'sequence' => 7, 'ordinal_path' => null, 'stream_id' => null],
				['run_uuid' => 'run-d', 'sequence' => 1, 'ordinal_path' => null, 'stream_id' => null],
			],
		];
	}//end setUp()

	/**
	 * The first application seeds one stream per marked place, in sorted
	 * place-name order, continuing each run's step sequence.
	 *
	 * @return void
	 */
	public function testTheFirstApplicationSeedsStreamsForInFlightMultiTokenRuns(): void {
		$this->apply();

		$streams = $this->streamsWithoutTimes();
		$this->assertSame(
			[
				['run-a', 'archive', FlowStream::ROOT_PATH, FlowRun::STATUS_RUNNING, 4],
				['run-a', 'publish', FlowStream::ROOT_PATH . '.0002', FlowRun::STATUS_RUNNING, 4],
				['run-a', 'review', FlowStream::ROOT_PATH . '.0003', FlowRun::STATUS_RUNNING, 4],
				['run-b', 'notify', FlowStream::ROOT_PATH, FlowRun::STATUS_SUSPENDED, 8],
				['run-b', 'wait-signal', FlowStream::ROOT_PATH . '.0002', FlowRun::STATUS_SUSPENDED, 8],
			],
			array_map(
				static fn (array $s): array => [$s['run_uuid'], $s['place'], $s['ordinal_path'], $s['status'], $s['next_sequence']],
				$streams
			)
		);

		// Stream ids are the deterministic ones, root first.
		$this->assertSame(Version1Date20260901120000::streamIdFor(runUuid: 'run-a', ordinal: 1), $streams[0]['stream_id']);
		$this->assertSame(Version1Date20260901120000::streamIdFor(runUuid: 'run-a', ordinal: 3), $streams[2]['stream_id']);

		// The suspended run keeps its wake on every stream; the running one has none.
		$this->assertSame('2026-09-03 10:00:00', $this->tables[self::STREAMS][3]['resume_at']->format('Y-m-d H:i:s'));
		$this->assertNull($this->tables[self::STREAMS][0]['resume_at']);

		// The queued run without a marking gets nothing: its first firing mints the root.
		$this->assertSame([], array_values(array_filter($streams, static fn (array $s): bool => $s['run_uuid'] === 'run-c')));
		// The completed run is not in flight and gets no stream.
		$this->assertSame([], array_values(array_filter($streams, static fn (array $s): bool => $s['run_uuid'] === 'run-d')));
	}//end testTheFirstApplicationSeedsStreamsForInFlightMultiTokenRuns()

	/**
	 * In-flight runs' steps carry the root stream; historical ones only the root path.
	 *
	 * @return void
	 */
	public function testStepsAreStampedWithTheRootPathAndInFlightOnesWithTheRootStream(): void {
		$this->apply();

		$rootA = Version1Date20260901120000::streamIdFor(runUuid: 'run-a', ordinal: 1);
		$rootB = Version1Date20260901120000::streamIdFor(runUuid: 'run-b', ordinal: 1);
		$this->assertSame(
			[
				['run-a', 1, FlowStream::ROOT_PATH, $rootA],
				['run-a', 2, FlowStream::ROOT_PATH, $rootA],
				['run-a', 3, FlowStream::ROOT_PATH, $rootA],
				['run-b', 1, FlowStream::ROOT_PATH, $rootB],
				['run-b', 7, FlowStream::ROOT_PATH, $rootB],
				['run-d', 1, FlowStream::ROOT_PATH, null],
			],
			array_map(
				static fn (array $s): array => [$s['run_uuid'], $s['sequence'], $s['ordinal_path'], $s['stream_id']],
				$this->tables[self::STEPS]
			)
		);
	}//end testStepsAreStampedWithTheRootPathAndInFlightOnesWithTheRootStream()

	/**
	 * A second application changes nothing: same rows, same values, no new
	 * stream, no re-stamp, and it reports that it seeded nothing.
	 *
	 * @return void
	 */
	public function testApplyingTheBackFillTwiceLeavesIdenticalTables(): void {
		$this->assertStringContainsString('2 in-flight run(s) given streams, 6 step row(s) stamped', $this->apply());
		$afterFirst = $this->tables;

		$this->assertStringContainsString('0 in-flight run(s) given streams, 0 step row(s) stamped', $this->apply());
		$this->assertEquals($afterFirst, $this->tables);
		$this->assertCount(5, $this->tables[self::STREAMS]);
	}//end testApplyingTheBackFillTwiceLeavesIdenticalTables()

	/**
	 * Two independent applications over the same seed write the same streams:
	 * the result is a function of the data, not of when the step ran.
	 *
	 * @return void
	 */
	public function testTheBackFillIsDeterministicOverTheSameSeed(): void {
		$seed = $this->tables;
		$this->apply();
		$first = $this->streamsWithoutTimes();

		$this->tables = $seed;
		$this->apply();

		$this->assertSame($first, $this->streamsWithoutTimes());
	}//end testTheBackFillIsDeterministicOverTheSameSeed()

	/**
	 * Run the back-fill once and return what it reported.
	 *
	 * @return string The info line.
	 */
	private function apply(): string {
		$reported = [];
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(static function (string $message) use (&$reported): void {
			$reported[] = $message;
		});

		$migration = new Version1Date20260901120000($this->fakeDb());
		$migration->postSchemaChange($output, static fn (): ?ISchemaWrapper => null, []);

		$this->assertCount(1, $reported);

		return $reported[0];
	}//end apply()

	/**
	 * The stream rows without their wall-clock columns.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function streamsWithoutTimes(): array {
		return array_map(
			static function (array $row): array {
				unset($row['created'], $row['updated']);
				if ($row['resume_at'] instanceof DateTime) {
					$row['resume_at'] = $row['resume_at']->format('Y-m-d H:i:s');
				}

				return $row;
			},
			$this->tables[self::STREAMS]
		);
	}//end streamsWithoutTimes()

	/**
	 * A connection whose query builders read and write $this->tables.
	 *
	 * @return IDBConnection The connection.
	 */
	private function fakeDb(): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->fakeQueryBuilder());

		return $db;
	}//end fakeDb()

	/**
	 * One query builder over the in-memory tables.
	 *
	 * Predicates are encoded as `op|column|placeholder` strings so that the
	 * statement can be evaluated against the stored rows when it executes.
	 *
	 * @return IQueryBuilder The builder.
	 */
	private function fakeQueryBuilder(): IQueryBuilder {
		$q = new stdClass();
		$q->op = null;
		$q->table = null;
		$q->params = [];
		$q->values = [];
		$q->sets = [];
		$q->conds = [];

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnCallback(static function () use ($q, $qb): IQueryBuilder {
			$q->op = 'select';
			return $qb;
		});
		foreach (['insert', 'update'] as $op) {
			$qb->method($op)->willReturnCallback(static function (string $table) use ($q, $qb, $op): IQueryBuilder {
				$q->op = $op;
				$q->table = $table;
				return $qb;
			});
		}

		$qb->method('from')->willReturnCallback(static function (string $table) use ($q, $qb): IQueryBuilder {
			$q->table = $table;
			return $qb;
		});
		$qb->method('values')->willReturnCallback(static function (array $values) use ($q, $qb): IQueryBuilder {
			$q->values = $values;
			return $qb;
		});
		$qb->method('set')->willReturnCallback(static function (string $column, string $placeholder) use ($q, $qb): IQueryBuilder {
			$q->sets[$column] = $placeholder;
			return $qb;
		});
		foreach (['where', 'andWhere'] as $method) {
			$qb->method($method)->willReturnCallback(static function (string $cond) use ($q, $qb): IQueryBuilder {
				$q->conds[] = $cond;
				return $qb;
			});
		}

		$qb->method('createNamedParameter')->willReturnCallback(static function (mixed $value) use ($q): string {
			$placeholder = ':p' . count($q->params);
			$q->params[$placeholder] = $value;
			return $placeholder;
		});

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(static fn (string $col, string $ph): string => 'eq|' . $col . '|' . $ph);
		$expr->method('in')->willReturnCallback(static fn (string $col, string $ph): string => 'in|' . $col . '|' . $ph);
		$expr->method('isNull')->willReturnCallback(static fn (string $col): string => 'isNull|' . $col . '|');
		$qb->method('expr')->willReturn($expr);

		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('count')->willReturn($this->createMock(IQueryFunction::class));
		$func->method('max')->willReturn($this->createMock(IQueryFunction::class));
		$qb->method('func')->willReturn($func);

		$qb->method('executeQuery')->willReturnCallback(fn (): IResult => $this->query(q: $q));
		$qb->method('executeStatement')->willReturnCallback(fn (): int => $this->statement(q: $q));

		return $qb;
	}//end fakeQueryBuilder()

	/**
	 * Evaluate a select: the run list, a stream count, or a step maximum.
	 *
	 * @param stdClass $q The recorded query.
	 *
	 * @return IResult The result.
	 */
	private function query(stdClass $q): IResult {
		$rows = $this->matching(q: $q);
		$all = [];
		$one = null;
		if ($q->table === self::RUNS) {
			$all = array_map(
				static fn (array $r): array => array_intersect_key($r, array_flip(['uuid', 'status', 'marking', 'resume_at'])),
				$rows
			);
		} else if ($q->table === self::STREAMS) {
			$one = (string)count($rows);
		} else if ($q->table === self::STEPS) {
			$sequences = array_column($rows, 'sequence');
			$one = $sequences === [] ? null : (string)max($sequences);
		}

		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn($all);
		$result->method('fetchOne')->willReturn($one);

		return $result;
	}//end query()

	/**
	 * Execute an insert or update against the store.
	 *
	 * @param stdClass $q The recorded statement.
	 *
	 * @return int Rows affected.
	 */
	private function statement(stdClass $q): int {
		if ($q->op === 'insert') {
			$row = [];
			foreach ($q->values as $column => $placeholder) {
				$row[$column] = $q->params[$placeholder];
			}

			$this->tables[$q->table][] = $row;
			return 1;
		}

		$affected = 0;
		foreach ($this->tables[$q->table] as $index => $row) {
			if ($this->rowMatches(row: $row, q: $q) === false) {
				continue;
			}

			foreach ($q->sets as $column => $placeholder) {
				$this->tables[$q->table][$index][$column] = $q->params[$placeholder];
			}

			$affected++;
		}

		return $affected;
	}//end statement()

	/**
	 * The rows of the query's table its predicates select.
	 *
	 * @param stdClass $q The recorded query.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function matching(stdClass $q): array {
		return array_values(
			array_filter(
				$this->tables[$q->table],
				fn (array $row): bool => $this->rowMatches(row: $row, q: $q)
			)
		);
	}//end matching()

	/**
	 * Whether a row satisfies every recorded predicate.
	 *
	 * @param array<string, mixed> $row The row.
	 * @param stdClass $q The recorded query.
	 *
	 * @return bool True on a match.
	 */
	private function rowMatches(array $row, stdClass $q): bool {
		foreach ($q->conds as $cond) {
			[$op, $column, $placeholder] = explode('|', $cond);
			$value = ($row[$column] ?? null);
			$matches = match ($op) {
				'eq' => $value === $q->params[$placeholder],
				'in' => in_array($value, $q->params[$placeholder], true),
				'isNull' => $value === null,
			};
			if ($matches === false) {
				return false;
			}
		}

		return true;
	}//end rowMatches()
}//end class
