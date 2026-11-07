<?php

/**
 * Golden test for AgentMapper list filters and FlowRunMapper run deletions.
 *
 * Records every query-builder call and the result of AgentMapper::findAll()
 * and ::count() over a range of filters, and of the three FlowRunMapper
 * deletions that select run uuids and delete them. Re-record with
 * UPDATE_GOLDEN=1.
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
use OCA\OpenRegister\Mcp\Db\AgentMapper;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * @covers \OCA\OpenRegister\Mcp\Db\AgentMapper
 * @covers \OCA\OpenRegister\Db\FlowRunMapper
 * @uses   \OCA\OpenRegister\Db\QueryPage
 */
class AgentAndFlowRunMapperGoldenTest extends TestCase {
	use RecordingQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/agent-and-flow-run-mapper-golden.json';

	private static function agentFilters(): array {
		return [
			'none' => [],
			'null' => null,
			'active true' => ['active' => 1],
			'active false' => ['active' => '0'],
			'list' => ['type' => ['chat', 'tool']],
			'scalar and route and null' => ['name' => 'x', '_route' => 'r', 'owner' => null, 'id' => 4],
		];
	}//end agentFilters()

	private static function agentOrders(): array {
		return [
			'none' => ['order' => []],
			'null' => ['order' => null],
			'fields' => ['order' => ['name' => 'asc', 'created' => 'DESC']],
			'list shaped' => ['order' => ['name', 'x' => 7]],
			'paged' => ['limit' => 5, 'offset' => 10, 'order' => ['name' => 'desc']],
			'limit only' => ['limit' => 3],
		];
	}//end agentOrders()

	private static function runDeletions(): array {
		$cutoff = new DateTime('2026-01-02 03:04:05');
		return [
			'older than, all flows' => ['deleteTerminalOlderThan', [$cutoff], [[['uuid' => 'a'], ['uuid' => 2]]]],
			'older than, one flow' => ['deleteTerminalOlderThan', [$cutoff, 'flow-1'], [[['uuid' => 'a']]]],
			'older than, empty flow id' => ['deleteTerminalOlderThan', [$cutoff, ''], [[]]],
			'excluding none' => ['deleteTerminalOlderThanExcluding', [$cutoff, []], [[['uuid' => 'b']]]],
			'excluding some' => ['deleteTerminalOlderThanExcluding', [$cutoff, ['f1', 'f2']], [[]]],
			'by flow' => ['deleteByFlow', ['flow-9'], [[['uuid' => 'c'], ['uuid' => 'd']]]],
			'by flow, nothing found' => ['deleteByFlow', ['flow-9'], [[]]],
			'by empty flow' => ['deleteByFlow', [''], []],
		];
	}//end runDeletions()

	public function testReadsAndDeletionsMatchGolden(): void {
		$actual = [];
		foreach (self::agentFilters() as $name => $filters) {
			$actual["agent findAll / {$name}"] = $this->agent(method: 'findAll', args: ['filters' => $filters], rows: [[]]);
			$actual["agent count / {$name}"] = $this->agent(method: 'count', args: ['filters' => $filters], rows: [[['COUNT(*)' => 3]]]);
		}

		foreach (self::agentOrders() as $name => $args) {
			$actual["agent findAll order / {$name}"] = $this->agent(method: 'findAll', args: $args, rows: [[]]);
		}

		foreach (self::runDeletions() as $name => [$method, $args, $rows]) {
			$actual["flow run / {$name}"] = $this->flowRun(method: $method, args: $args, rows: $rows);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testReadsAndDeletionsMatchGolden()

	public function testTheAgentReadsReachTheirFilters(): void {
		foreach (['findAll', 'count'] as $method) {
			$outcome = $this->agent(method: $method, args: ['filters' => ['name' => 'x']], rows: [[]]);
			$this->assertArrayNotHasKey('thrown', $outcome, $method);
			$this->assertContains('#1 andWhere(eq(name, :p1, NULL))', $outcome['queries'], $method);
		}
	}//end testTheAgentReadsReachTheirFilters()

	private function reset(array $rows): IDBConnection {
		$this->queryLog = [];
		$this->builderCount = 0;
		$this->parameterCount = 0;
		$this->queuedRows = $rows;

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());
		return $db;
	}//end reset()

	private function agent(string $method, array $args, array $rows): array {
		$db = $this->reset(rows: $rows);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(true);

		$mapper = $this->build(class: AgentMapper::class, given: ['db' => $db, 'userSession' => $session, 'groupManager' => $groups]);
		return $this->call(mapper: $mapper, call: [$method, $args]);
	}//end agent()

	private function flowRun(string $method, array $args, array $rows): array {
		$mapper = $this->build(class: FlowRunMapper::class, given: ['db' => $this->reset(rows: $rows)]);
		return $this->call(mapper: $mapper, call: [$method, $args]);
	}//end flowRun()

	private function call(object $mapper, array $call): array {
		[$method, $args] = $call;
		$outcome = [];
		try {
			$result = $mapper->$method(...$args);
			$outcome['result'] = is_array($result) === true ? array_map(fn ($r) => is_object($r) ? get_class($r) : $r, $result) : $result;
		} catch (\Throwable $e) {
			$outcome['thrown'] = get_class($e) . ': ' . $e->getMessage();
		}

		$outcome['queries'] = $this->queryLog;
		return $outcome;
	}//end call()

	private function build(string $class, array $given): object {
		$args = [];
		foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $param) {
			$type = $param->getType();
			if (array_key_exists($param->getName(), $given) === true) {
				$args[] = $given[$param->getName()];
				continue;
			}

			if ($type instanceof ReflectionNamedType && $type->isBuiltin() === false) {
				$args[] = $this->createMock($type->getName());
				continue;
			}

			$args[] = $param->getDefaultValue();
		}

		return new $class(...$args);
	}//end build()
}//end class
