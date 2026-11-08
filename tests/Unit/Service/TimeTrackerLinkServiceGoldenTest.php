<?php

/**
 * Golden test for TimeTrackerLinkService: linking each entry kind, creating a
 * client, the client picker, the stale refresh and the reconcile walk.
 *
 * Records every query-builder call, the log lines and the value returned, per
 * scenario, in fixtures/time-tracker-link-golden.json. Set UPDATE_GOLDEN=1 to
 * rewrite it.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use DateTime;
use OCA\OpenRegister\Db\TimeTrackerLink;
use OCA\OpenRegister\Db\TimeTrackerLinkMapper;
use OCA\OpenRegister\Service\LinkTarget;
use OCA\OpenRegister\Service\TimeTrackerLinkService;
use OCP\App\IAppManager;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * @covers \OCA\OpenRegister\Service\TimeTrackerLinkService
 * @uses   \OCA\OpenRegister\Db\TimeTrackerLink
 * @uses   \OCA\OpenRegister\Service\LinkTarget
 */
class TimeTrackerLinkServiceGoldenTest extends TestCase {

	/**
	 * Every query-builder call, in order.
	 *
	 * @var array<int,string>
	 */
	private array $calls = [];

	/**
	 * Every log line, in order.
	 *
	 * @var array<int,string>
	 */
	private array $logs = [];

	/**
	 * Canned upstream rows by uuid.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $rows = [];

	/**
	 * Throw on the n-th executeStatement (1-based), 0 never.
	 *
	 * @var int
	 */
	private int $failStatement = 0;

	/**
	 * Mapper behaviour: findAll throws, update throws for these link names.
	 *
	 * @var array<string,mixed>
	 */
	private array $mapperBehaviour = [];

	/**
	 * Make the TimeManager mapper classes resolvable, as when the app is installed.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		if (class_exists('OCA\\TimeManager\\Db\\ClientMapper') === false) {
			class_alias(get_class(new class {
			}), 'OCA\\TimeManager\\Db\\ClientMapper');
		}

		if (class_exists('OCA\\TimeManager\\Db\\TaskMapper') === false) {
			class_alias(get_class(new class {
			}), 'OCA\\TimeManager\\Db\\TaskMapper');
		}
	}

	/**
	 * Scenarios: a kind and its argument.
	 *
	 * @return array<string, array>
	 */
	public static function scenarios(): array {
		return [
			'link a client through its mapper table' => ['link', ['client', 'cli-1']],
			'link a task when the mapper has no table' => ['link', ['task', 'tsk-1']],
			'link a time entry' => ['link', ['TIME ', 'tim-1']],
			'link a time entry with a bad start' => ['link', ['time', 'tim-bad']],
			'link a missing entry' => ['link', ['time', 'nope']],
			'link when the lookup fails' => ['link', ['time', 'boom']],
			'create a client' => ['create', 0],
			'create a client failing' => ['create', 1],
			'clients' => ['clients', null],
			'clients searched' => ['clients', '50%_off'],
			'clients failing' => ['clients', 'fail'],
			'linked entries refreshed' => ['linked', null],
			'reconcile' => ['reconcile', false],
			'reconcile dry run' => ['reconcile', true],
			'reconcile when the walk fails' => ['reconcile', 'fail'],
		];
	}

	/**
	 * @dataProvider scenarios
	 */
	public function testGolden(string $kind, mixed $arg): void {
		$this->rows = [
			'cli-1' => ['name' => 'Acme', 'payed' => 1],
			'tsk-1' => ['name' => 'Build', 'duration' => '90', 'billable' => 1],
			'tim-1' => ['name' => 'Standup', 'duration' => '', 'payed' => 0, 'start' => '2026-01-02 09:00:00'],
			'tim-bad' => ['start' => 'not a date'],
			'tim-same' => ['name' => 'Same', 'duration' => 30, 'billable' => 1, 'start' => '2026-03-04 10:00:00'],
			'tim-2' => ['name' => '', 'duration' => 45, 'start' => '2026-03-04 11:00:00'],
		];
		$this->failStatement = $kind === 'create' ? (int)$arg : 0;
		if ($arg === 'fail' && $kind === 'reconcile') {
			$this->mapperBehaviour['findAllThrows'] = true;
		}

		$service = $this->service();
		$before = time();
		try {
			$result = match ($kind) {
				'link' => $this->linkFields($service->linkEntry((new LinkTarget('obj-1', 3))->inSchema(4), $arg[0], $arg[1])),
				'create' => $this->linkFields($service->createAndLinkClient((new LinkTarget('obj-1', 3))->inSchema(4), ' New client ')),
				'clients' => $service->getAvailableClients($arg),
				'linked' => $service->getLinkedEntries('obj-1'),
				default => ['stats' => $service->reconcileAllLinks('obj-1', $arg === true), 'links' => array_map(fn ($l) => $this->linkFields($l), $this->storedLinks)],
			};
		} catch (Throwable $e) {
			$result = ['error' => [get_class($e), $e->getMessage(), $e->getCode()]];
		}

		$actual = $this->normalise(['calls' => $this->calls, 'logs' => $this->logs, 'result' => $result], $before, time());

		$file = __DIR__ . '/fixtures/time-tracker-link-golden.json';
		$name = $this->dataName();
		$all = is_file($file) === true ? json_decode(file_get_contents($file), true) : [];
		if (getenv('UPDATE_GOLDEN') === '1') {
			$all[$name] = $actual;
			ksort($all);
			file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$this->assertSame($all[$name], $actual);
	}

	/**
	 * The stored links of the listing and reconcile scenarios.
	 *
	 * @var array<int,TimeTrackerLink>
	 */
	private array $storedLinks = [];

	private function service(): TimeTrackerLinkService {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->builder());
		$db->method('escapeLikeParameter')->willReturnCallback(fn (string $value) => addcslashes($value, '%_\\'));

		$this->storedLinks = [
			$this->storedLink('client', 'cli-1', 'Old client', 'alice', null),
			$this->storedLink('task', 'tsk-1', 'Build', 'alice', null),
			$this->storedLink('time', 'tim-same', 'Same', 'alice', null, 30, true, '2026-03-04 10:00:00'),
			$this->storedLink('time', 'tim-2', 'Keep name', '', null, 10, null, null),
			$this->storedLink('time', 'nope', 'Gone', 'alice', null),
			$this->storedLink('time', 'tim-1', 'Fresh', 'alice', new DateTime()),
		];
		$mapper = $this->getMockBuilder(TimeTrackerLinkMapper::class)->disableOriginalConstructor()
			->onlyMethods(['insert', 'update', 'findByObjectAndEntry', 'findByObjectUuid', 'findAll'])->getMock();
		$mapper->method('insert')->willReturnArgument(0);
		$mapper->method('findByObjectAndEntry')->willReturn(null);
		$mapper->method('findByObjectUuid')->willReturn($this->storedLinks);
		$mapper->method('findAll')->willReturnCallback(
			function (?string $objectUuid) {
				$this->calls[] = 'findAll ' . json_encode($objectUuid);
				if (($this->mapperBehaviour['findAllThrows'] ?? false) === true) {
					throw new RuntimeException('walk down');
				}

				return $this->storedLinks;
			}
		);
		$mapper->method('update')->willReturnCallback(
			function (TimeTrackerLink $link) {
				$this->calls[] = 'update ' . $link->getName();
				if ($link->getEntryType() === 'task') {
					throw new RuntimeException('update down');
				}

				return $link;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $class) {
				$this->calls[] = 'container ' . $class;
				return new class(str_ends_with($class, 'ClientMapper')) {
					public function __construct(private bool $hasTable) {
					}

					public function getTableName(): string {
						if ($this->hasTable === false) {
							throw new RuntimeException('no table');
						}

						return 'tm_client';
					}
				};
			}
		);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->logs[] = $level . ': ' . $message;
				}
			);
		}

		return new TimeTrackerLinkService($mapper, $db, $container, $apps, $session, $logger);
	}

	private function storedLink(string $type, string $id, string $name, string $linkedBy, ?DateTime $linkedAt, ?int $duration = null, ?bool $billable = null, ?string $startedAt = null): TimeTrackerLink {
		$link = new TimeTrackerLink();
		$link->setId(count($this->storedLinks ?? []) + 1);
		$link->setObjectUuid('obj-1');
		$link->setEntryType($type);
		$link->setClientId($type === 'client' ? $id : null);
		$link->setTaskId($type === 'task' ? $id : null);
		$link->setTimeId($type === 'time' ? $id : null);
		$link->setName($name);
		$link->setDuration($duration);
		$link->setBillable($billable);
		$link->setStartedAt($startedAt === null ? null : new DateTime($startedAt));
		$link->setLinkedBy($linkedBy);
		$link->setLinkedAt($linkedAt);
		return $link;
	}

	/**
	 * A query builder that records its calls and answers from the canned rows.
	 *
	 * @return IQueryBuilder
	 */
	private function builder(): IQueryBuilder {
		$qb = $this->createMock(IQueryBuilder::class);
		$state = ['table' => '', 'params' => []];
		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['eq', 'iLike'] as $op) {
			$expr->method($op)->willReturnCallback(fn ($a, $b) => $op . '(' . $a . ',' . $b . ')');
		}

		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(
			function ($value, $type = IQueryBuilder::PARAM_STR) use (&$state) {
				$state['params'][] = $value;
				$this->calls[] = 'param ' . json_encode([$value, $type]);
				return ':p' . count($state['params']);
			}
		);
		foreach (['select', 'from', 'where', 'andWhere', 'orderBy', 'setMaxResults', 'insert', 'values'] as $method) {
			$qb->method($method)->willReturnCallback(
				function (...$args) use ($qb, $method, &$state) {
					$this->calls[] = $method . ' ' . json_encode($args);
					if ($method === 'from' || $method === 'insert') {
						$state['table'] = $args[0];
					}

					return $qb;
				}
			);
		}

		$qb->method('executeStatement')->willReturnCallback(
			function () use (&$state) {
				$this->calls[] = 'executeStatement ' . $state['table'];
				if ($this->failStatement === 1) {
					throw new RuntimeException('insert down');
				}

				return 1;
			}
		);
		$qb->method('executeQuery')->willReturnCallback(
			function () use (&$state) {
				$this->calls[] = 'executeQuery ' . $state['table'];
				return $this->answer($state);
			}
		);
		return $qb;
	}

	/**
	 * The canned result for a query.
	 *
	 * @param array $state The query's table and parameters.
	 *
	 * @return IResult
	 */
	private function answer(array $state): IResult {
		$result = $this->createMock(IResult::class);
		$result->method('closeCursor')->willReturnCallback(
			function (): bool {
				$this->calls[] = 'closeCursor';
				return true;
			}
		);
		$first = $state['params'][0] ?? null;
		if ($first === 'boom') {
			throw new RuntimeException('lookup down');
		}

		if ($state['table'] === 'tm_client' && count($state['params']) <= 2 && in_array($first, array_keys($this->rows), true) === false) {
			if (in_array('%fail%', $state['params'], true) === true) {
				throw new RuntimeException('clients down');
			}

			$result->method('fetch')->willReturnOnConsecutiveCalls(['uuid' => 'c-1', 'name' => 'Acme'], ['name' => 'No uuid'], ['uuid' => 'c-2'], false);
			return $result;
		}

		$result->method('fetch')->willReturn($this->rows[$first] ?? false);
		return $result;
	}

	private function linkFields(TimeTrackerLink $link): array {
		return $link->jsonSerialize();
	}

	/**
	 * Replace values that depend on the clock or randomness.
	 *
	 * @param array $data   The recorded data.
	 * @param int   $before Time before the call.
	 * @param int   $after  Time after the call.
	 *
	 * @return array
	 */
	private function normalise(array $data, int $before, int $after): array {
		$json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$json = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/', 'UUID', $json);
		$json = preg_replace_callback(
			'/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}/',
			static function (array $m) use ($before, $after): string {
				$ts = (new DateTime($m[0]))->getTimestamp();
				return ($ts >= $before && $ts <= $after) ? 'NOW' : $m[0];
			},
			$json
		);
		return json_decode($json, true);
	}
}
