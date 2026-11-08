<?php

/**
 * Golden test for the PollLinkService read paths: linkPoll, getLinkedPolls and
 * getAvailablePolls.
 *
 * Records every query-builder call, the value returned and the log lines, per
 * scenario, in fixtures/poll-link-read-golden.json. Set UPDATE_GOLDEN=1 to rewrite it.
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

use OCA\OpenRegister\Db\PollLink;
use OCA\OpenRegister\Db\PollLinkMapper;
use OCA\OpenRegister\Service\LinkTarget;
use OCA\OpenRegister\Service\PollLinkService;
use OCP\App\IAppManager;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * @covers \OCA\OpenRegister\Service\PollLinkService
 * @uses   \OCA\OpenRegister\Db\PollLink
 * @uses   \OCA\OpenRegister\Service\LinkTarget
 */
class PollLinkServiceReadGoldenTest extends TestCase {

	/**
	 * Past and future expire timestamps, so `closed` does not depend on the clock.
	 */
	private const PAST = 1577836800;

	private const FUTURE = 4102444800;

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
	 * Scenarios: a name and a callable taking the service and returning the result.
	 *
	 * @return array<string, array>
	 */
	public static function scenarios(): array {
		return [
			'link a closed poll' => ['link', 5],
			'link a missing poll' => ['link', 404],
			'link when the lookup fails' => ['link', 500],
			'link an open poll without counts' => ['link', 7],
			'linked polls refreshed' => ['linked', 0],
			'available polls' => ['available', null],
			'available polls searched' => ['available', 'lu'],
			'available polls failing' => ['available', 'fail'],
		];
	}

	/**
	 * @dataProvider scenarios
	 */
	public function testReadPathsGolden(string $kind, int|string|null $arg): void {
		$service = $this->service();
		try {
			$result = match ($kind) {
				'link' => $this->linkFields($service->linkPoll((new LinkTarget('obj-1', 3))->inSchema(4), (int)$arg)),
				'linked' => $service->getLinkedPolls('obj-1'),
				default => $service->getAvailablePolls($arg),
			};
		} catch (Throwable $e) {
			$result = ['error' => [get_class($e), $e->getMessage(), $e->getCode()]];
		}

		$actual = json_decode(json_encode(['calls' => $this->calls, 'logs' => $this->logs, 'result' => $result], JSON_UNESCAPED_UNICODE), true);

		$file = __DIR__ . '/fixtures/poll-link-read-golden.json';
		$name = $this->dataName();
		$all = is_file($file) === true ? json_decode(file_get_contents($file), true) : [];
		if (getenv('UPDATE_GOLDEN') === '1') {
			$all[$name] = $actual;
			ksort($all);
			file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$this->assertSame($all[$name], $actual);
	}

	private function service(): PollLinkService {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->builder());

		$mapper = $this->getMockBuilder(PollLinkMapper::class)->disableOriginalConstructor()
			->onlyMethods(['insert', 'findByObjectAndPoll', 'findByObjectUuid'])->getMock();
		$mapper->method('insert')->willReturnArgument(0);
		$mapper->method('findByObjectAndPoll')->willReturn(null);
		$mapper->method('findByObjectUuid')->willReturn([$this->storedLink(5, 'Cached five'), $this->storedLink(404, 'Cached gone'), $this->storedLink(7, 'Cached seven')]);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->logs[] = $level . ': ' . $message;
				}
			);
		}

		return new PollLinkService($mapper, $db, $apps, $session, $logger);
	}

	private function storedLink(int $pollId, string $title): PollLink {
		$link = new PollLink();
		$link->setObjectUuid('obj-1');
		$link->setPollId($pollId);
		$link->setPollTitle($title);
		$link->setPollType('textPoll');
		$link->setVoterCount(99);
		$link->setOptionCount(99);
		$link->setClosed(false);
		return $link;
	}

	/**
	 * A query builder that records its calls and answers from canned poll data.
	 *
	 * @return IQueryBuilder
	 */
	private function builder(): IQueryBuilder {
		$qb = $this->createMock(IQueryBuilder::class);
		$state = ['table' => '', 'params' => [], 'select' => []];
		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['eq', 'iLike'] as $op) {
			$expr->method($op)->willReturnCallback(fn ($a, $b) => $op . '(' . $a . ',' . $b . ')');
		}

		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('count')->willReturnCallback(
			function ($a, $b) {
				$function = $this->createMock(IQueryFunction::class);
				$function->method('__toString')->willReturn('count(' . $a . ' as ' . $b . ')');
				return $function;
			}
		);
		$qb->method('expr')->willReturn($expr);
		$qb->method('func')->willReturn($func);
		$qb->method('createNamedParameter')->willReturnCallback(
			function ($value, $type = IQueryBuilder::PARAM_STR) use (&$state) {
				$state['params'][] = $value;
				$this->calls[] = 'param ' . json_encode([$value, $type]);
				return ':p' . count($state['params']);
			}
		);
		foreach (['select', 'selectDistinct', 'from', 'where', 'andWhere', 'orderBy'] as $method) {
			$qb->method($method)->willReturnCallback(
				function (...$args) use ($qb, $method, &$state) {
					$this->calls[] = $method . ' ' . json_encode($args);
					if ($method === 'from') {
						$state['table'] = $args[0];
					}

					if ($method === 'select' || $method === 'selectDistinct') {
						$state['select'] = $args;
					}

					return $qb;
				}
			);
		}

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
	 * @param array $state The query's table, parameters and select list.
	 *
	 * @return IResult
	 */
	private function answer(array $state): IResult {
		$result = $this->createMock(IResult::class);
		$id = $state['params'][0] ?? null;
		$rows = [
			5 => ['id' => 5, 'title' => 'Lunch', 'type' => 'textPoll', 'expire' => self::PAST, 'owner' => 'alice', 'deleted' => 0],
			7 => ['id' => 7, 'expire' => self::FUTURE, 'owner' => 'alice', 'deleted' => 0],
		];
		if ($state['table'] === 'polls_polls' && in_array('owner', $state['select'], true) === true) {
			if ($id === 500) {
				throw new RuntimeException('db down');
			}

			$result->method('fetch')->willReturn($rows[$id] ?? false);
			return $result;
		}

		if ($state['table'] === 'polls_polls') {
			if (in_array('%fail%', $state['params'], true) === true) {
				throw new RuntimeException('search down');
			}

			$result->method('fetchAll')->willReturn([$rows[5], ['id' => '8', 'title' => 'Lunch two', 'type' => 'datePoll', 'expire' => '0'], []]);
			return $result;
		}

		if ($state['table'] === 'polls_votes') {
			if ($id === 7) {
				throw new RuntimeException('votes down');
			}

			$result->method('fetchAll')->willReturn([['user_id' => 'a'], ['user_id' => 'b'], ['user_id' => 'c']]);
			return $result;
		}

		$result->method('fetchOne')->willReturn(match ($id) {
			5 => '4',
			7 => null,
			8 => false,
			default => 2,
		});
		return $result;
	}

	private function linkFields(PollLink $link): array {
		$row = $link->jsonSerialize();
		$row['linkedAt'] = get_debug_type($link->getLinkedAt());
		return $row;
	}
}
