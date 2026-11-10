<?php

/**
 * Golden test for the FlowRunMapper reads that are scoped to one caller.
 *
 * Records every query-builder call and the result of findByUuidVisibleTo(),
 * countSinceByFlow(), latestRunsByFlow(), findActive() and
 * expireQueuedBefore() over the three visibility modes (no scoping, a caller
 * alone, a caller who owns flows) and the empty answers. Pins both the
 * allowed read (a row comes back) and the refused one (the scoped query finds
 * nothing and the caller gets null). Re-record with UPDATE_GOLDEN=1.
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
use OCA\OpenRegister\Db\FlowActivityWindow;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\FlowRunQueueExpiry;
use OCA\OpenRegister\Db\FlowRunSubjectScope;
use OCA\OpenRegister\Db\FlowRunViewer;
use OCA\OpenRegister\Service\Registers\EntityEventPublisher;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\FlowRunMapper
 */
final class FlowRunMapperVisibleReadGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/flow-run-mapper-visible-read-golden.json';

	/**
	 * Method, named arguments and the rows each query is served, by name.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: array<int, array<int, array<string, mixed>>>}>
	 */
	private static function cases(): array {
		$since = new DateTime('2026-01-02T03:04:05+00:00', new DateTimeZone('UTC'));
		$row = ['id' => 4, 'uuid' => 'r4', 'flow_id' => 'f2', 'status' => 'completed'];
		$queued = ['id' => 5, 'uuid' => 'r5', 'flow_id' => 'f2', 'status' => FlowRun::STATUS_QUEUED];
		$moved = ['id' => 6, 'uuid' => 'r6', 'flow_id' => 'f2', 'status' => 'running'];
		return [
			'uuid / admin finds it' => ['findByUuidVisibleTo', ['uuid' => 'r4', 'requesterUid' => null], [[$row]]],
			'uuid / caller alone is refused' => ['findByUuidVisibleTo', ['uuid' => 'r4', 'requesterUid' => 'alice'], [[]]],
			'uuid / caller alone finds own' => ['findByUuidVisibleTo', ['uuid' => 'r4', 'requesterUid' => 'alice'], [[$row]]],
			'uuid / owner of the flow' => ['findByUuidVisibleTo', ['uuid' => 'r4', 'requesterUid' => 'bob', 'ownedFlowIds' => ['f2', 'f3']], [[$row]]],
			'uuid / owner of no flows' => ['findByUuidVisibleTo', ['uuid' => 'r4', 'requesterUid' => 'bob', 'ownedFlowIds' => []], [[]]],
			'counts / admin' => [
				'countSinceByFlow',
				['flowIds' => ['f1'], 'since' => $since, 'requesterUid' => null, 'ownedFlowIds' => []],
				[[['flow_id' => 'f1', 'total' => '2']]],
			],
			'counts / owner' => [
				'countSinceByFlow',
				['flowIds' => ['f1', 'f2'], 'since' => $since, 'requesterUid' => 'bob', 'ownedFlowIds' => ['f2']],
				[[['flow_id' => 'f2', 'total' => 1]]],
			],
			'latest / nothing visible' => ['latestRunsByFlow', ['flowIds' => ['f1'], 'requesterUid' => 'alice', 'ownedFlowIds' => []], [[]]],
			'latest / owner' => ['latestRunsByFlow', ['flowIds' => ['f2'], 'requesterUid' => 'bob', 'ownedFlowIds' => ['f2']], [[['latest' => '4']], [$row]]],
			'latest / admin' => ['latestRunsByFlow', ['flowIds' => ['f2'], 'requesterUid' => null, 'ownedFlowIds' => []], [[['latest' => 4]], [$row]]],
			'active / defaults' => ['findActive', [], [[$row]]],
			'active / organisation' => ['findActive', ['organisation' => 'org-1', 'limit' => 3], [[]]],
			'active / blank organisation' => ['findActive', ['organisation' => ''], [[]]],
			'active / organisation and subject' => ['findActive', ['organisation' => 'org-1', 'subject' => 's-1'], [[]]],
			'completed / organisation and subject' => ['findCompletedForSubject', ['organisation' => 'org-1', 'subject' => 's-1', 'limit' => 4], [[$row]]],
			'completed / blank subject is refused' => ['findCompletedForSubject', ['organisation' => 'org-1', 'subject' => ''], []],
			'completed / blank organisation is refused' => ['findCompletedForSubject', ['organisation' => '', 'subject' => 's-1'], []],
			'expire / zero limit' => ['expireQueuedBefore', ['before' => $since, 'reason' => 'stale', 'limit' => 0], []],
			'expire / skips a run that moved on' => ['expireQueuedBefore', ['before' => $since, 'reason' => 'stale', 'limit' => 2], [[$moved]]],
			'expire / nothing queued' => ['expireQueuedBefore', ['before' => $since, 'reason' => 'stale'], [[]]],
		];
	}//end cases()

	public function testOutcomesMatchGolden(): void {
		$actual = [];
		foreach (self::cases() as $name => [$method, $args, $rows]) {
			$actual[$name] = $this->outcome(method: $method, args: $args, rows: $rows);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		$this->assertSame($expected, json_decode((string)json_encode($actual), true));
	}//end testOutcomesMatchGolden()

	public function testAScopedReadThatFindsNothingAnswersNull(): void {
		$refused = $this->outcome(method: 'findByUuidVisibleTo', args: ['uuid' => 'r4', 'requesterUid' => 'alice'], rows: [[]]);

		$this->assertNull($refused['result']);
		$this->assertContains('#1 param :p2 = alice, 2', $refused['queries']);
	}//end testAScopedReadThatFindsNothingAnswersNull()

	public function testDroppingTheCallerWouldWidenTheRead(): void {
		// Mutation check: the unscoped and scoped single-run reads must differ,
		// so a refactor that loses the visibility predicate fails this test.
		$open = $this->outcome(method: 'findByUuidVisibleTo', args: ['uuid' => 'r4', 'requesterUid' => null], rows: [[]]);
		$scoped = $this->outcome(method: 'findByUuidVisibleTo', args: ['uuid' => 'r4', 'requesterUid' => 'alice', 'ownedFlowIds' => ['f2']], rows: [[]]);

		$this->assertNotSame($open['queries'], $scoped['queries']);
		$this->assertNotContains('#1 expr.eq(triggered_by, :p2, NULL)', $open['queries']);
		$this->assertContains('#1 expr.eq(triggered_by, :p2, NULL)', $scoped['queries']);
	}//end testDroppingTheCallerWouldWidenTheRead()

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
		$mapper = new FlowRunMapper($db, new EntityEventPublisher(dispatcher: $this->createMock(IEventDispatcher::class)));

		$outcome = [];
		try {
			$outcome['result'] = $this->render(value: $mapper->$method(...$this->valueObjectArgs(method: $method, args: $args)));
		} catch (\Throwable $e) {
			$outcome['thrown'] = get_class($e) . ': ' . $e->getMessage();
		}

		$outcome['queries'] = $this->queryLog;
		return $outcome;
	}//end outcome()

	/**
	 * The case tables keep the flat argument names the golden was recorded
	 * with; the mapper now takes them as value objects. Building those here
	 * leaves the case names and the recorded fixture untouched, so an
	 * unchanged fixture proves the queries and the allowed and refused
	 * outcomes did not change.
	 *
	 * @param string               $method The method.
	 * @param array<string, mixed> $args   Flat named arguments.
	 *
	 * @return array<string, mixed> The arguments the mapper takes.
	 */
	private function valueObjectArgs(string $method, array $args): array {
		$viewer = new FlowRunViewer(requesterUid: ($args['requesterUid'] ?? null), ownedFlowIds: ($args['ownedFlowIds'] ?? []));
		$scope = new FlowRunSubjectScope(organisation: ($args['organisation'] ?? null), subject: ($args['subject'] ?? null));
		return match ($method) {
			'findByUuidVisibleTo' => ['uuid' => $args['uuid'], 'viewer' => $viewer],
			'countSinceByFlow' => ['window' => new FlowActivityWindow(flowIds: $args['flowIds'], since: $args['since']), 'viewer' => $viewer],
			'latestRunsByFlow' => ['flowIds' => $args['flowIds'], 'viewer' => $viewer],
			'findActive', 'findCompletedForSubject' => ['scope' => $scope, 'limit' => ($args['limit'] ?? 25)],
			'expireQueuedBefore' => ['expiry' => new FlowRunQueueExpiry(before: $args['before'], reason: $args['reason'], limit: ($args['limit'] ?? 500))],
		};
	}//end valueObjectArgs()

	/**
	 * A result as plain data.
	 *
	 * @param mixed $value The result.
	 *
	 * @return mixed The rendering.
	 */
	private function render(mixed $value): mixed {
		if ($value instanceof FlowRun) {
			return [
				'uuid' => $value->getUuid(),
				'flowId' => $value->getFlowId(),
				'status' => $value->getStatus(),
				'error' => $value->getError(),
			];
		}

		if (is_array($value) === true) {
			return array_map(fn (mixed $item): mixed => $this->render(value: $item), $value);
		}

		return $value;
	}//end render()
}//end class
