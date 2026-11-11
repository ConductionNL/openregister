<?php

/**
 * Characterisation golden for ActivityFilterService queries and AnalyticsSeriesService::register().
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\ActivityFilter;
use OCA\OpenRegister\Service\ActivityEntriesOptions;
use DateTime;
use OCA\OpenRegister\Db\AnalyticsSeries;
use OCA\OpenRegister\Db\AnalyticsSeriesMapper;
use OCA\OpenRegister\Service\ActivityFilterService;
use OCA\OpenRegister\Service\LinkServiceRuntime;
use OCA\OpenRegister\Service\AnalyticsSeriesService;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCP\App\IAppManager;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Pins every query-builder call, result and log line of ActivityFilterService and
 * the validation, persistence and widget declaration of AnalyticsSeriesService::register(),
 * compared with tests/Unit/Service/fixtures/activity-analytics-golden.json.
 * Run with UPDATE_GOLDEN=1 to re-record.
 *
 * @covers \OCA\OpenRegister\Service\ActivityFilterService
 * @covers \OCA\OpenRegister\Service\AnalyticsSeriesService
 * @uses   \OCA\OpenRegister\Db\AnalyticsSeries
 * @uses   \OCA\OpenRegister\Service\Integration\IntegrationRegistry
 */
class ActivityAnalyticsGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/activity-analytics-golden.json';

	private array $calls = [];

	private static function rows(): array {
		return [
			['activity_id' => 9, 'subject' => '[or:obj-1] created', 'type' => 'create', 'affecteduser' => 'alice', 'timestamp' => 300, 'object_id' => 'o'],
			['activity_id' => 8, 'subject' => '[or:obj-1] updated', 'type' => 'update', 'affecteduser' => 'bob', 'timestamp' => 200],
			['activity_id' => 7, 'subject' => '[or:obj-1] updated', 'type' => 'update', 'affecteduser' => '', 'timestamp' => 100],
		];
	}//end rows()

	public function testActivityAndAnalyticsMatchGolden(): void {
		$actual = [];
		$activity = [
			'entries plain' => [['obj-1'], []],
			'entries every filter' => [['obj-1', 'update', 'bob', 50, 2, 250, 'internal'], []],
			'entries empty filters' => [['obj-1', '', '', null, 0, null, null], []],
			'entries big limit' => [['obj-1', null, null, 0, 900, 0], []],
			'entries more pages' => [['obj-1', null, null, null, 2], []],
			'entries public' => [['obj-1', null, null, null, 100, null, ' PUBLIC '], []],
			'entries app missing' => [['obj-1'], ['installed' => false]],
			'entries query fails' => [['obj-1', 'x'], ['throws' => true]],
			'types' => [['obj-1'], ['distinct' => 'type']],
			'actors' => [['obj-1'], ['distinct' => 'affecteduser']],
			'types fail' => [['obj-1'], ['distinct' => 'type', 'throws' => true]],
		];
		foreach ($activity as $name => [$args, $over]) {
			$w = $over + ['installed' => true, 'throws' => false, 'distinct' => null];
			$service = new ActivityFilterService($this->database($w), new LinkServiceRuntime($this->appManager($w['installed']), $this->createMock(IUserSession::class), $this->logger()));
			$method = match ($w['distinct']) {
				'type' => 'getActivityTypes',
				'affecteduser' => 'getActivityActors',
				default => 'getActivityEntries',
			};
			$call = fn () => $service->$method(...$args);
			if ($method === 'getActivityEntries') {
				$call = fn () => $service->getActivityEntries(...self::entriesArgs(...$args));
			}

			$actual['activity ' . $name] = $this->outcome($call);
		}

		$series = [
			'blank key' => [[' ', [1], []], []],
			'bad visibility' => [['k', [1], [], null, 'line', 'world'], []],
			'bad chart' => [['k', [1], [], null, 'pie3d', 'public'], []],
			'anonymous' => [['k', [1], []], ['uid' => null]],
			'insert' => [['k', ['a' => 'Jan', 'b' => 'Feb'], ['x' => ['label' => 'n', 'data' => [1, 2]]], 'Title', 'bar', 'group', 3, 4], []],
			'update' => [['k', [], [], null, 'line', 'private'], ['existing' => true]],
		];
		foreach ($series as $name => [$args, $over]) {
			$w = $over + ['uid' => 'alice', 'existing' => false];
			$registry = new IntegrationRegistry(logger: $this->createMock(LoggerInterface::class));
			$service = new AnalyticsSeriesService(
				mapper: $this->seriesMapper($w),
				registry: $registry,
				userSession: $this->session($w['uid']),
				groupManager: $this->createMock(IGroupManager::class),
			);
			$outcome = $this->outcome(fn () => $service->registerSeries(draft: self::seriesDraft(...$args)));
			$outcome['widgets'] = $this->normalise($registry->listPageWidgets());
			$actual['series ' . $name] = $outcome;
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testActivityAndAnalyticsMatchGolden()

	private static function entriesArgs(
		string $objectUuid,
		?string $type = null,
		?string $actor = null,
		?int $after = null,
		int $limit = 100,
		?int $cursor = null,
		?string $visibility = null
	): array {
		$filter = (new ActivityFilter(objectUuid: $objectUuid, type: $type, actor: $actor, after: $after))->withCursor(cursor: $cursor);
		return [$filter, new ActivityEntriesOptions(limit: $limit, visibility: $visibility)];
	}

	private function record(string $call): void {
		$this->calls[] = $call;
	}//end record()

	private function outcome(callable $act): array {
		$this->calls = [];
		try {
			$result = $this->normalise($act());
		} catch (Throwable $e) {
			$result = ['throws' => get_class($e), 'message' => $e->getMessage(), 'code' => $e->getCode()];
		}

		return ['result' => $result, 'calls' => $this->calls];
	}//end outcome()

	private function normalise(mixed $value): mixed {
		if ($value instanceof AnalyticsSeries) {
			$value = $value->jsonSerialize();
		}

		if ($value instanceof DateTime) {
			return abs($value->getTimestamp() - time()) < 3600 ? 'NOW' : $value->format(DATE_ATOM);
		}

		if (is_array($value) === true) {
			return array_map(fn ($v) => $this->normalise($v), $value);
		}

		if (is_string($value) === true && preg_match('/^\d{4}-\d\d-\d\dT/', $value) === 1 && abs(strtotime($value) - time()) < 3600) {
			return 'NOW';
		}

		return $value;
	}//end normalise()

	private function appManager(bool $installed): IAppManager {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(fn (string $app) => $this->record('isInstalled(' . $app . ')') ?? $installed);
		return $appManager;
	}//end appManager()

	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(fn (string $m, array $c = []) => $this->record('debug ' . $m . ' ' . implode(',', array_keys($c))));
		return $logger;
	}//end logger()

	private function database(array $w): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(
			function () use ($w) {
				$this->record('getQueryBuilder');
				$state = ['count' => false];
				$expr = $this->createMock(IExpressionBuilder::class);
				foreach (['iLike', 'eq', 'gte', 'lt'] as $op) {
					$expr->method($op)->willReturnCallback(fn ($col, $param) => $op . '(' . $col . ',' . $param . ')');
				}

				$func = $this->createMock(IFunctionBuilder::class);
				$func->method('count')->willReturnCallback(
					function (...$args) use (&$state) {
						$state['count'] = true;
						$this->record('func.count(' . json_encode($args) . ')');
						return $this->createMock(IQueryFunction::class);
					}
				);
				$qb = $this->createMock(IQueryBuilder::class);
				$qb->method('expr')->willReturn($expr);
				$qb->method('func')->willReturn($func);
				$qb->method('createNamedParameter')->willReturnCallback(fn ($value, $type = null) => ':' . json_encode($value) . ($type === null ? '' : '/' . $type));
				foreach (['select', 'selectDistinct', 'from', 'where', 'andWhere', 'orderBy', 'addOrderBy', 'setMaxResults'] as $chain) {
					$qb->method($chain)->willReturnCallback(
						function (...$args) use ($qb, $chain) {
							$this->record('qb.' . $chain . '(' . json_encode(array_map(fn ($a) => is_object($a) ? 'FUNC' : $a, $args)) . ')');
							return $qb;
						}
					);
				}

				$qb->method('executeQuery')->willReturnCallback(
					function () use ($w, &$state) {
						$this->record('qb.executeQuery');
						if ($w['throws'] === true) {
							throw new RuntimeException('db down');
						}

						$rows = self::rows();
						if ($w['distinct'] !== null) {
							$rows = array_merge($rows, [['type' => 'create', 'affecteduser' => 'alice']]);
						}

						if ($state['count'] === true) {
							$rows = [['cnt' => '3']];
						}

						$result = $this->createMock(IResult::class);
						$result->method('fetch')->willReturnCallback(
							function () use (&$rows) {
								return array_shift($rows) ?? false;
							}
						);
						$result->method('closeCursor')->willReturnCallback(fn () => $this->record('closeCursor') ?? true);
						return $result;
					}
				);
				return $qb;
			}
		);
		return $db;
	}//end database()

	private function session(?string $uid): IUserSession {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		return $session;
	}//end session()

	private function seriesMapper(array $w): AnalyticsSeriesMapper {
		$mapper = $this->createMock(AnalyticsSeriesMapper::class);
		$mapper->method('findByKey')->willReturnCallback(
			function (string $key) use ($w) {
				$this->record('findByKey(' . $key . ')');
				if ($w['existing'] === false) {
					return null;
				}

				$series = new AnalyticsSeries();
				$series->setId(5);
				$series->setCreatedBy('bob');
				$series->setCreatedAt(new DateTime('@1600000000'));
				return $series;
			}
		);
		foreach (['insert', 'update'] as $write) {
			$mapper->method($write)->willReturnCallback(
				function (AnalyticsSeries $series) use ($write) {
					$this->record($write . '(' . json_encode($this->normalise($series)) . ')');
					return $series;
				}
			);
		}

		return $mapper;
	}//end seriesMapper()

	private static function seriesDraft(
		string $seriesKey,
		array $labels,
		array $datasets,
		?string $title = null,
		string $chartType = 'line',
		string $visibility = 'private',
		?int $registerId = null,
		?int $schemaId = null
	): \OCA\OpenRegister\Service\AnalyticsSeriesDraft {
		return (new \OCA\OpenRegister\Service\AnalyticsSeriesDraft(seriesKey: $seriesKey, title: $title))
			->withData(labels: $labels, datasets: $datasets)
			->shownAs(chartType: $chartType, visibility: $visibility)
			->inScope(registerId: $registerId, schemaId: $schemaId);
	}
}//end class
