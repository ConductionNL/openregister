<?php

/**
 * Golden test for the FlowRunMapper run list, its per-flow summary and the queue.
 *
 * Records every query-builder call and the result of findAllRuns(),
 * summarizeByFlow() and findQueued() over their filters, their visibility
 * scoping (no scoping, a caller alone, a caller who owns flows) and the
 * queue's sharing rule. Re-record with UPDATE_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use DateTimeZone;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\FlowRunMapper
 */
final class FlowRunMapperListGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/flow-run-mapper-list-golden.json';

	/**
	 * findAllRuns() argument sets, by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function listCases(): array {
		$since = new DateTime('2026-01-02T03:04:05+00:00', new DateTimeZone('UTC'));
		return [
			'defaults' => [],
			'flow id' => ['flowId' => 'f1'],
			'empty flow id' => ['flowId' => ''],
			'status' => ['status' => 'failed'],
			'empty status' => ['status' => ''],
			'paging' => ['limit' => 10, 'offset' => 20],
			'since' => ['since' => $since],
			'flow ids' => ['flowIds' => [3 => 'a', 7 => 'b']],
			'empty flow ids' => ['flowIds' => []],
			'caller alone' => ['requesterUid' => 'alice'],
			'caller with owned flows' => ['requesterUid' => 'alice', 'ownedFlowIds' => ['f9', 'f8']],
			'owned flows without caller' => ['ownedFlowIds' => ['f9']],
			'everything' => [
				'flowId' => 'f1',
				'status' => 'running',
				'limit' => 5,
				'offset' => 1,
				'requesterUid' => 'bob',
				'ownedFlowIds' => ['f2'],
				'since' => $since,
				'flowIds' => ['f1', 'f2'],
			],
		];
	}//end listCases()

	/**
	 * summarizeByFlow() cases: arguments and the rows each query is served.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<int, array<int, array<string, mixed>>>}>
	 */
	private static function summaryCases(): array {
		$since = new DateTime('2026-01-02T03:04:05+00:00', new DateTimeZone('UTC'));
		$run = [
			'id' => 7, 'uuid' => 'r7', 'flow_id' => 'f1', 'status' => 'completed', 'log' => null,
			'error' => null, 'created' => '2026-01-02T03:04:05+00:00', 'updated' => null,
		];
		$stray = ['id' => 9, 'uuid' => 'r9', 'flow_id' => 'zz', 'status' => 'failed', 'log' => null, 'error' => 'boom'];
		return [
			'no flows' => [['flowIds' => [], 'since' => $since], []],
			'only blank flows' => [['flowIds' => ['', '  '], 'since' => $since], []],
			'never ran' => [['flowIds' => [' f1 ', 'f2', 'f1'], 'since' => $since], [[], []]],
			'counts and a latest run' => [
				['flowIds' => ['f1', 'f2', 12], 'since' => $since, 'requesterUid' => 'alice', 'ownedFlowIds' => ['f2']],
				[[['flow_id' => 'f1', 'total' => '3'], ['flow_id' => 'unknown', 'total' => '4']], [['latest' => '7'], ['latest' => '9']], [$run, $stray]],
			],
			'numeric flow id' => [['flowIds' => [12], 'since' => $since], [[['flow_id' => 12, 'total' => 2]], []]],
			'caller alone' => [['flowIds' => ['f1'], 'since' => $since, 'requesterUid' => 'carol'], [[], []]],
		];
	}//end summaryCases()

	/**
	 * findQueued() cases: the limit and the rows each query is served.
	 *
	 * @return array<string, array{0: int, 1: array<int, array<int, array<string, mixed>>>}>
	 */
	private static function queueCases(): array {
		$queued = static fn (int $id, string $flow): array => ['id' => $id, 'uuid' => 'q' . $id, 'flow_id' => $flow, 'status' => FlowRun::STATUS_QUEUED];
		return [
			'zero limit' => [0, []],
			'negative limit' => [-3, []],
			'nothing queued' => [5, [[]]],
			'one flow' => [3, [[['flow_id' => 'a', 'oldest_id' => 1]], [$queued(1, 'a'), $queued(2, 'a'), $queued(3, 'a')]]],
			'two flows share' => [
				5,
				[[['flow_id' => 'a', 'oldest_id' => 1], ['flow_id' => 'b', 'oldest_id' => 2]], [$queued(1, 'a'), $queued(3, 'a'), $queued(5, 'a')], [$queued(2, 'b')]],
			],
			'more flows than slots' => [
				2,
				[[['flow_id' => 'a', 'oldest_id' => 1], ['flow_id' => 'b', 'oldest_id' => 2], ['flow_id' => 'c', 'oldest_id' => 3]], [$queued(1, 'a')], [$queued(2, 'b')]],
			],
			'short flow leaves room' => [
				4,
				[[['flow_id' => 'a', 'oldest_id' => 1], ['flow_id' => 'b', 'oldest_id' => 2]], [], [$queued(2, 'b'), $queued(4, 'b')]],
			],
		];
	}//end queueCases()

	public function testOutcomesMatchGolden(): void {
		$actual = [];
		foreach (self::listCases() as $name => $args) {
			$actual['findAllRuns / ' . $name] = $this->outcome(method: 'findAllRuns', args: $args, rows: [[['id' => 1, 'uuid' => 'r1', 'flow_id' => 'f1']]]);
		}

		foreach (self::summaryCases() as $name => [$args, $rows]) {
			$actual['summarizeByFlow / ' . $name] = $this->outcome(method: 'summarizeByFlow', args: $args, rows: $rows);
		}

		foreach (self::queueCases() as $name => [$limit, $rows]) {
			$actual['findQueued / ' . $name] = $this->outcome(method: 'findQueued', args: ['limit' => $limit], rows: $rows);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		$this->assertSame($expected, json_decode((string)json_encode($actual), true));
	}//end testOutcomesMatchGolden()

	public function testAnUnscopedListAndAScopedListDiffer(): void {
		$open = $this->outcome(method: 'findAllRuns', args: [], rows: [[]]);
		$scoped = $this->outcome(method: 'findAllRuns', args: ['requesterUid' => 'alice'], rows: [[]]);

		$this->assertNotSame($open['queries'], $scoped['queries']);
		$this->assertContains('#1 param :p1 = alice, 2', $scoped['queries']);
	}//end testAnUnscopedListAndAScopedListDiffer()

	/**
	 * Run one mapper method against a recording connection.
	 *
	 * @param string $method The method.
	 * @param array<string, mixed> $args Named arguments.
	 * @param array<int, array<int, array<string, mixed>>> $rows Rows per executeQuery().
	 *
	 * @return array<string, mixed> The result and the query log.
	 */
	private function outcome(string $method, array $args, array $rows): array {
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$this->queuedRows = $rows;

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());
		$mapper = new FlowRunMapper($db, $this->createMock(IEventDispatcher::class));

		$outcome = [];
		try {
			$outcome['result'] = $this->render(value: $mapper->$method(...$args));
		} catch (\Throwable $e) {
			$outcome['thrown'] = get_class($e) . ': ' . $e->getMessage();
		}

		$outcome['queries'] = $this->queryLog;
		return $outcome;
	}//end outcome()

	/**
	 * A result as plain data.
	 *
	 * @param mixed $value The result.
	 *
	 * @return mixed The rendering.
	 */
	private function render(mixed $value): mixed {
		if ($value instanceof FlowRun) {
			return ['uuid' => $value->getUuid(), 'flowId' => $value->getFlowId(), 'status' => $value->getStatus()];
		}

		if (is_array($value) === true) {
			return array_map(fn (mixed $item): mixed => $this->render(value: $item), $value);
		}

		return $value;
	}//end render()
}//end class
