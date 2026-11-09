<?php

/**
 * Unit tests for PollLinkService — the paths that read and write the Polls tables.
 *
 * PollLinkServiceTest covers the guards that refuse before any query. These
 * tests stand a small in-memory Polls database behind the IDBConnection mock
 * (polls, votes, option counts, inserts) and assert what the service reads,
 * what it writes and what each failing query decides: a poll that is gone,
 * an expired deadline, a search term, a date poll's option timestamps, blank
 * options, a lost insert id and a failing insert.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTimeImmutable;
use Exception;
use OCA\OpenRegister\Db\PollLink;
use OCA\OpenRegister\Db\PollLinkMapperInterface;
use OCA\OpenRegister\Service\LinkServiceRuntime;
use OCA\OpenRegister\Service\LinkTarget;
use OCA\OpenRegister\Service\PollDraft;
use OCA\OpenRegister\Service\PollLinkService;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\Entity;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class PollLinkServiceDatabaseTest extends TestCase {

	/** An expire timestamp long past. */
	private const PAST = 1000;

	/** An expire timestamp far ahead (2100-01-01). */
	private const FUTURE = 4102444800;

	/** @var array<int, array<string, mixed>> Poll rows by id. */
	private array $polls = [];

	/** @var array<int, string[]> Distinct voters by poll id. */
	private array $voters = [];

	/** @var array<int, int> Option counts by poll id. */
	private array $optionCounts = [];

	/** @var string[] Tables whose reads throw. */
	private array $failingReads = [];

	/** @var string[] Tables whose inserts throw. */
	private array $failingInserts = [];

	/** @var array<int, array{0: string, 1: array<string, mixed>}> Inserts made, as [table, column => value]. */
	private array $inserts = [];

	/** @var array<int, array<int, mixed>> The parameters of each owned-polls query. */
	private array $ownedQueries = [];

	private int $lastInsertId = 42;

	private bool $pollsEnabled = true;

	private ?string $uid = 'alice';

	/** @var string[] */
	private array $logs = [];

	private PollLinkMapperInterface&MockObject $mapper;

	private function service(): PollLinkService {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->builder());
		$db->method('lastInsertId')->willReturnCallback(fn () => $this->lastInsertId);

		$this->mapper = $this->createMock(PollLinkMapperInterface::class);
		$this->mapper->method('insert')->willReturnCallback(static fn (Entity $e): Entity => $e);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturnCallback(fn (string $app) => $app === 'polls' && $this->pollsEnabled);
		$session = $this->createMock(IUserSession::class);
		if ($this->uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($this->uid);
			$session->method('getUser')->willReturn($user);
		}
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message) use ($level): void {
				$this->logs[] = $level . ': ' . $message;
			});
		}

		return new PollLinkService($this->mapper, $db, new LinkServiceRuntime($apps, $session, $logger));
	}

	private function builder(): IQueryBuilder {
		$qb = $this->createMock(IQueryBuilder::class);
		$state = (object) ['table' => '', 'params' => [], 'values' => []];

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$expr->method('iLike')->willReturn('ilike');
		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('count')->willReturn($this->createMock(IQueryFunction::class));
		$qb->method('expr')->willReturn($expr);
		$qb->method('func')->willReturn($func);
		$qb->method('createNamedParameter')->willReturnCallback(function ($value) use ($state) {
			$state->params[] = $value;
			return ':p' . (count($state->params) - 1);
		});
		foreach (['select', 'selectDistinct', 'where', 'andWhere', 'orderBy'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$qb->method('from')->willReturnCallback(function (string $table) use ($qb, $state) {
			$state->table = $table;
			return $qb;
		});
		$qb->method('insert')->willReturnCallback(function (string $table) use ($qb, $state) {
			$state->table = $table;
			return $qb;
		});
		$qb->method('values')->willReturnCallback(function (array $values) use ($qb, $state) {
			$state->values = $values;
			return $qb;
		});
		$qb->method('executeStatement')->willReturnCallback(function () use ($state): int {
			if (in_array($state->table, $this->failingInserts, true) === true) {
				throw new RuntimeException('insert into ' . $state->table . ' refused');
			}
			$row = [];
			foreach ($state->values as $column => $placeholder) {
				$row[$column] = $state->params[(int) substr((string) $placeholder, 2)];
			}
			$this->inserts[] = [$state->table, $row];
			return 1;
		});
		$qb->method('executeQuery')->willReturnCallback(fn () => $this->answer($state));
		return $qb;
	}

	private function answer(object $state): IResult {
		if (in_array($state->table, $this->failingReads, true) === true) {
			throw new RuntimeException('read of ' . $state->table . ' failed');
		}
		$result = $this->createMock(IResult::class);
		$pollId = $state->params[0] ?? null;
		switch ($state->table) {
			case 'polls_polls':
				if (is_string($pollId) === true) {
					$this->ownedQueries[] = $state->params;
					$rows = array_values(array_filter($this->polls, static fn (array $r): bool => ($r['owner'] ?? '') === $pollId));
					$result->method('fetchAll')->willReturn($rows);
					break;
				}
				$result->method('fetch')->willReturn($this->polls[$pollId] ?? false);
				break;
			case 'polls_votes':
				$result->method('fetchAll')->willReturn(array_map(static fn ($u) => ['user_id' => $u], $this->voters[$pollId] ?? []));
				break;
			case 'polls_options':
				$result->method('fetchOne')->willReturn((string) ($this->optionCounts[$pollId] ?? 0));
				break;
		}
		return $result;
	}

	private function target(): LinkTarget {
		return (new LinkTarget('obj-1', 3))->inSchema(4);
	}

	private function stored(int $pollId, string $title): PollLink {
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

	public function testLinkPollStoresTheLiveTitleTypeCountsAndDeadline(): void {
		$this->polls = [5 => ['id' => 5, 'title' => 'Lunch', 'type' => 'datePoll', 'expire' => self::FUTURE]];
		$this->voters = [5 => ['a', 'b', 'c']];
		$this->optionCounts = [5 => 4];
		$service = $this->service();
		$this->mapper->method('findByObjectAndPoll')->willReturn(null);

		$link = $service->linkPoll($this->target(), 5);

		$this->assertSame(
			[5, 'Lunch', 'datePoll', 3, 4, false, self::FUTURE, 'obj-1', 3, 4, 'alice'],
			[$link->getPollId(), $link->getPollTitle(), $link->getPollType(), $link->getVoterCount(), $link->getOptionCount(), $link->getClosed(), $link->getDeadline()->getTimestamp(), $link->getObjectUuid(), $link->getRegisterId(), $link->getSchemaId(), $link->getLinkedBy()]
		);
	}

	public function testAPollPastItsDeadlineIsLinkedClosedAndOneWithoutExpireHasNoDeadline(): void {
		$this->polls = [5 => ['id' => 5, 'title' => 'Old', 'expire' => self::PAST], 6 => ['id' => 6]];
		$service = $this->service();
		$this->mapper->method('findByObjectAndPoll')->willReturn(null);

		$old = $service->linkPoll($this->target(), 5);
		$bare = $service->linkPoll($this->target(), 6);

		$this->assertTrue($old->getClosed());
		$this->assertNull($bare->getDeadline());
		$this->assertFalse($bare->getClosed());
		$this->assertSame(['', ''], [$bare->getPollTitle(), $bare->getPollType()]);
	}

	public function testLinkingAPollThatIsGoneIs404(): void {
		$service = $this->service();
		$this->mapper->method('findByObjectAndPoll')->willReturn(null);
		$this->mapper->expects($this->never())->method('insert');

		try {
			$service->linkPoll($this->target(), 404);
			$this->fail('A missing poll must be refused.');
		} catch (Exception $e) {
			$this->assertSame([404, 'Poll not found'], [$e->getCode(), $e->getMessage()]);
		}
	}

	public function testAFailingPollReadIsLoggedAsDebugAndReadsAsGone(): void {
		$this->failingReads = ['polls_polls'];
		$service = $this->service();
		$this->mapper->method('findByObjectAndPoll')->willReturn(null);

		try {
			$service->linkPoll($this->target(), 5);
			$this->fail('An unreadable poll must be refused.');
		} catch (Exception $e) {
			$this->assertSame(404, $e->getCode());
		}
		$this->assertSame(['debug: fetchPollRow failed: read of polls_polls failed'], $this->logs);
	}

	public function testFailingCountQueriesReadAsZero(): void {
		$this->polls = [5 => ['id' => 5, 'title' => 'T', 'expire' => 0]];
		$this->voters = [5 => ['a']];
		$this->optionCounts = [5 => 2];
		$this->failingReads = ['polls_votes', 'polls_options'];
		$service = $this->service();
		$this->mapper->method('findByObjectAndPoll')->willReturn(null);

		$link = $service->linkPoll($this->target(), 5);

		$this->assertSame([0, 0], [$link->getVoterCount(), $link->getOptionCount()]);
	}

	public function testLinkedPollsAreRefreshedFromPollsAndKeepTheCacheForAGonePoll(): void {
		$this->polls = [5 => ['id' => 5, 'title' => 'Live five', 'type' => 'datePoll', 'expire' => self::PAST], 7 => ['id' => 7, 'expire' => 0]];
		$this->voters = [5 => ['a', 'b']];
		$this->optionCounts = [5 => 6];
		$service = $this->service();
		$this->mapper->method('findByObjectUuid')->with('obj-1')->willReturn([$this->stored(5, 'Cached five'), $this->stored(404, 'Cached gone'), $this->stored(7, 'Cached seven')]);

		$rows = $service->getLinkedPolls('obj-1');

		$this->assertSame(['Live five', 'datePoll', 2, 6, true, '1970-01-01T00:16:40+00:00', '/apps/polls/vote/5'], [$rows[0]['pollTitle'], $rows[0]['pollType'], $rows[0]['voterCount'], $rows[0]['optionCount'], $rows[0]['closed'], $rows[0]['deadline'], $rows[0]['url']]);
		$this->assertSame(['Cached gone', 99, '/apps/polls/vote/404'], [$rows[1]['pollTitle'], $rows[1]['voterCount'], $rows[1]['url']], 'A poll that is gone keeps its cached row.');
		$this->assertSame(['Cached seven', 'textPoll', null, 0], [$rows[2]['pollTitle'], $rows[2]['pollType'], $rows[2]['deadline'], $rows[2]['voterCount']], 'Missing live fields fall back to the cached ones.');
	}

	public function testAvailablePollsListTheOwnersPollsWithTheirStats(): void {
		$this->polls = [
			5 => ['id' => 5, 'title' => 'Mine', 'type' => 'textPoll', 'expire' => self::FUTURE, 'owner' => 'alice'],
			6 => ['id' => 6, 'title' => 'Theirs', 'owner' => 'bob'],
			8 => ['id' => '8', 'owner' => 'alice'],
		];
		$this->voters = [5 => ['x']];
		$this->optionCounts = [5 => 3];
		$service = $this->service();

		$rows = $service->getAvailablePolls(null);

		$this->assertSame(
			[
				['id' => 5, 'title' => 'Mine', 'type' => 'textPoll', 'deadline' => '2100-01-01T00:00:00+00:00', 'closed' => false, 'voterCount' => 1, 'optionCount' => 3],
				['id' => 8, 'title' => '', 'type' => '', 'deadline' => null, 'closed' => false, 'voterCount' => 0, 'optionCount' => 0],
			],
			$rows
		);
		$this->assertSame([['alice', 0]], $this->ownedQueries, 'Without a search term no title filter is added.');
	}

	public function testASearchTermAddsATitleFilterAndAnEmptyOneDoesNot(): void {
		$service = $this->service();

		$service->getAvailablePolls('lun');
		$service->getAvailablePolls('');

		$this->assertSame([['alice', 0, '%lun%'], ['alice', 0]], $this->ownedQueries);
	}

	public function testAFailingAvailablePollsQueryIsLoggedAndListsNothing(): void {
		$this->failingReads = ['polls_polls'];
		$service = $this->service();

		$this->assertSame([], $service->getAvailablePolls('x'));
		$this->assertSame(['warning: PollLinkService::getAvailablePolls failed: read of polls_polls failed'], $this->logs);
	}

	public function testCreateAndLinkPollWritesThePollItsNonBlankOptionsAndTheLink(): void {
		$service = $this->service();
		$deadline = new DateTimeImmutable('@' . self::FUTURE);
		$draft = (new PollDraft('Team lunch', 'Pick a day'))->withType('date')->withOptions(['2026-12-01 12:00:00 UTC', '  ', 'not a date'], $deadline);

		$link = $service->createAndLinkPoll($this->target(), $draft);

		$this->assertSame(['polls_polls', 'polls_options', 'polls_options'], array_column($this->inserts, 0));
		$poll = $this->inserts[0][1];
		$this->assertSame(['datePoll', 'Team lunch', 'Pick a day', 'alice', self::FUTURE, 'private', 'simple'], [$poll['type'], $poll['title'], $poll['description'], $poll['owner'], $poll['expire'], $poll['access'], $poll['voting_variant']]);
		$first = $this->inserts[1][1];
		$second = $this->inserts[2][1];
		$this->assertSame([42, '2026-12-01 12:00:00 UTC', 1, strtotime('2026-12-01 12:00:00 UTC'), 'alice'], [$first['poll_id'], $first['poll_option_text'], $first['order'], $first['timestamp'], $first['owner']]);
		$this->assertSame(substr(hash('sha256', '42-2026-12-01 12:00:00 UTC-1'), 0, 32), $first['poll_option_hash']);
		$this->assertSame(['not a date', 2, 0], [$second['poll_option_text'], $second['order'], $second['timestamp']], 'A blank option is skipped; an unparseable date gets timestamp 0.');
		$this->assertSame([42, 'Team lunch', 'datePoll', 0, 3, false, self::FUTURE, 'alice'], [$link->getPollId(), $link->getPollTitle(), $link->getPollType(), $link->getVoterCount(), $link->getOptionCount(), $link->getClosed(), $link->getDeadline()->getTimestamp(), $link->getLinkedBy()]);
	}

	public function testATextPollsOptionsCarryNoTimestamp(): void {
		$service = $this->service();
		$draft = (new PollDraft('Colour'))->withOptions(['2026-12-01'], null);

		$link = $service->createAndLinkPoll($this->target(), $draft);

		$this->assertSame(0, $this->inserts[1][1]['timestamp']);
		$this->assertSame(0, $this->inserts[0][1]['expire']);
		$this->assertNull($link->getDeadline());
	}

	public function testALostInsertIdFailsTheCreateWithoutWritingOptionsOrALink(): void {
		$this->lastInsertId = 0;
		$service = $this->service();
		$this->mapper->expects($this->never())->method('insert');

		try {
			$service->createAndLinkPoll($this->target(), (new PollDraft('X'))->withOptions(['a'], null));
			$this->fail('A lost insert id must fail the create.');
		} catch (Exception $e) {
			$this->assertSame([500, 'Failed to create poll: Failed to retrieve created poll id'], [$e->getCode(), $e->getMessage()]);
		}
		$this->assertSame(['polls_polls'], array_column($this->inserts, 0));
		$this->assertSame(['warning: Failed to create poll: Failed to retrieve created poll id'], $this->logs);
	}

	public function testAFailingOptionInsertFailsTheCreate(): void {
		$this->failingInserts = ['polls_options'];
		$service = $this->service();
		$this->mapper->expects($this->never())->method('insert');

		$this->expectExceptionMessage('Failed to create poll: insert into polls_options refused');
		$service->createAndLinkPoll($this->target(), (new PollDraft('X'))->withOptions(['a'], null));
	}
}
