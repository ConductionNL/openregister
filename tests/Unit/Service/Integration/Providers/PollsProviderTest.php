<?php

/**
 * Unit tests for PollsProvider after Tier-2 migration to PollLinkMapper.
 *
 * Covers:
 *  - metadata getters (id / label / icon / group / requiredApp / storage)
 *  - `isEnabled()` honours `IAppManager::isInstalled()`
 *  - `list()` returns empty when Polls is uninstalled
 *  - `list()` returns empty when no link rows exist
 *  - `list()` happy-path: walks `openregister_poll_links`, hydrates each
 *    row from `polls_polls` + per-option vote tallies, returns
 *    `{id,title,type,url,deadline,closed,voterCount,options[],linkId}`
 *  - `health()` reports `'unavailable'` when Polls is not installed
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/integration-polls/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Db\PollLink;
use OCA\OpenRegister\Db\PollLinkMapper;
use OCA\OpenRegister\Service\Integration\Providers\PollsProvider;
use OCP\App\IAppManager;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PollsProvider.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class PollsProviderTest extends TestCase {

	private function buildL10n(): IL10N {
		$mock = $this->createMock(IL10N::class);
		$mock->method('t')->willReturnArgument(0);
		return $mock;
	}//end buildL10n()

	private function buildAppManager(bool $installed): IAppManager {
		$mock = $this->createMock(IAppManager::class);
		$mock->method('isInstalled')->willReturn($installed);
		return $mock;
	}//end buildAppManager()

	private function buildUserSession(?string $uid): IUserSession {
		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
			return $session;
		}
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session->method('getUser')->willReturn($user);
		return $session;
	}//end buildUserSession()

	/**
	 * Build a query-builder mock that returns the supplied rows.
	 *
	 * @param array $rows Rows for `fetchAll()` / `fetch()` (first row).
	 * @param mixed $singleValue Value for `fetchOne()`.
	 *
	 * @return IQueryBuilder
	 */
	private function buildQb(array $rows, $singleValue = 0): IQueryBuilder {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');

		$literal = $this->createMock(IQueryFunction::class);
		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('count')->willReturn($literal);

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('selectDistinct')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('orderBy')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn(':p');
		$qb->method('expr')->willReturn($expr);
		$qb->method('func')->willReturn($func);

		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn($rows);
		$result->method('fetch')->willReturn($rows === [] ? false : $rows[0]);
		$result->method('fetchOne')->willReturn($singleValue);
		$qb->method('executeQuery')->willReturn($result);
		return $qb;
	}//end buildQb()

	private function buildMapper(array $links): PollLinkMapper {
		$mapper = $this->getMockBuilder(PollLinkMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findByObjectUuid'])
			->getMock();
		$mapper->method('findByObjectUuid')->willReturn($links);
		return $mapper;
	}//end buildMapper()

	public function testMetadata(): void {
		$provider = new PollsProvider(
			pollLinkMapper: $this->buildMapper([]),
			db: $this->createMock(IDBConnection::class),
			appManager: $this->buildAppManager(true),
			userSession: $this->buildUserSession('admin'),
			l10n: $this->buildL10n()
		);

		self::assertSame('polls', $provider->getId());
		self::assertSame('Polls', $provider->getLabel());
		self::assertSame('Poll', $provider->getIcon());
		self::assertSame('workflow', $provider->getGroup());
		self::assertSame('polls', $provider->getRequiredApp());
		self::assertSame('link-table', $provider->getStorageStrategy());
	}//end testMetadata()

	public function testIsEnabledFalseWhenPollsMissing(): void {
		$provider = new PollsProvider(
			pollLinkMapper: $this->buildMapper([]),
			db: $this->createMock(IDBConnection::class),
			appManager: $this->buildAppManager(false),
			userSession: $this->buildUserSession('admin'),
			l10n: $this->buildL10n()
		);
		self::assertFalse($provider->isEnabled());
		self::assertSame([], $provider->list(register: 'r', schema: 's', objectId: 'uuid'));
	}//end testIsEnabledFalseWhenPollsMissing()

	public function testListEmptyWhenNoLinks(): void {
		$provider = new PollsProvider(
			pollLinkMapper: $this->buildMapper([]),
			db: $this->createMock(IDBConnection::class),
			appManager: $this->buildAppManager(true),
			userSession: $this->buildUserSession('admin'),
			l10n: $this->buildL10n()
		);

		self::assertSame([], $provider->list(register: 'r', schema: 's', objectId: 'uuid'));
	}//end testListEmptyWhenNoLinks()

	public function testListHappyPath(): void {
		$uuid = 'abc-123';
		$now = time();
		$futureExpire = $now + 86400;

		$link = new PollLink();
		$link->setPollId(42);
		$link->setPollTitle('Lunch');
		$link->setPollType('datePoll');
		$link->setObjectUuid($uuid);

		$pollRows = [
			[
				'id' => 42,
				'title' => 'Lunch',
				'description' => 'Pick a day',
				'type' => 'datePoll',
				'expire' => $futureExpire,
			],
		];
		$optionRows = [
			['id' => 100, 'poll_option_text' => 'Mon', 'poll_option_hash' => 'h-mon'],
			['id' => 101, 'poll_option_text' => 'Tue', 'poll_option_hash' => 'h-tue'],
		];
		$voteRowsForVoters = [
			['user_id' => 'alice'],
			['user_id' => 'bob'],
			['user_id' => 'carol'],
		];

		$qbPoll = $this->buildQb($pollRows);
		$qbOptions = $this->buildQb($optionRows);
		$qbVotesMon = $this->buildQb([], 3);
		$qbVotesTue = $this->buildQb([], 1);
		$qbVoters = $this->buildQb($voteRowsForVoters);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$qbPoll,
			$qbOptions,
			$qbVotesMon,
			$qbVotesTue,
			$qbVoters
		);

		$provider = new PollsProvider(
			pollLinkMapper: $this->buildMapper([$link]),
			db: $db,
			appManager: $this->buildAppManager(true),
			userSession: $this->buildUserSession('admin'),
			l10n: $this->buildL10n()
		);

		$rows = $provider->list(register: 'r', schema: 's', objectId: $uuid);
		self::assertCount(1, $rows);

		$row = $rows[0];
		self::assertSame('42', $row['id']);
		self::assertSame(42, $row['pollId']);
		self::assertSame('Lunch', $row['title']);
		self::assertSame('datePoll', $row['type']);
		self::assertSame('/index.php/apps/polls/vote/42', $row['url']);
		self::assertSame($futureExpire, $row['deadline']);
		self::assertFalse($row['closed']);
		self::assertSame(3, $row['voterCount']);
		self::assertCount(2, $row['options']);
		self::assertSame('Mon', $row['options'][0]['text']);
		self::assertSame(3, $row['options'][0]['votes']);
		self::assertSame('Tue', $row['options'][1]['text']);
		self::assertSame(1, $row['options'][1]['votes']);
	}//end testListHappyPath()

	public function testHealthOk(): void {
		$provider = new PollsProvider(
			pollLinkMapper: $this->buildMapper([]),
			db: $this->createMock(IDBConnection::class),
			appManager: $this->buildAppManager(true),
			userSession: $this->buildUserSession('admin'),
			l10n: $this->buildL10n()
		);
		$h = $provider->health();
		self::assertSame('ok', $h['status']);
		self::assertSame('configured', $h['authStatus']);
		self::assertNull($h['message']);
	}//end testHealthOk()

	public function testHealthUnavailable(): void {
		$provider = new PollsProvider(
			pollLinkMapper: $this->buildMapper([]),
			db: $this->createMock(IDBConnection::class),
			appManager: $this->buildAppManager(false),
			userSession: $this->buildUserSession('admin'),
			l10n: $this->buildL10n()
		);
		$h = $provider->health();
		self::assertSame('unavailable', $h['status']);
		self::assertNotNull($h['message']);
	}//end testHealthUnavailable()
}//end class
