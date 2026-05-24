<?php

/**
 * Unit tests for PollsProvider after Phase B-3 payload widening.
 *
 * Covers:
 *  - metadata getters (id / label / icon / group / requiredApp / storage)
 *  - `isEnabled()` honours `IAppManager::isInstalled()`
 *  - `list()` happy-path: walks `polls_polls`, filters by `[or:{uuid}]`
 *    marker, attaches `options[]` from `polls_options` joined with
 *    `polls_votes` yes-tallies, and `voterCount` (distinct users), plus
 *    `deadline` + `closed` derived from the poll's `expire` field
 *  - `list()` no-user: returns `[]` cleanly when no session user
 *  - `list()` no-marker-match: a poll without the marker is excluded
 *  - `health()` reports `'unavailable'` when Polls is not installed
 *
 * The provider talks to `polls_polls` / `polls_options` / `polls_votes`
 * via OR's lazy-resolved `IDBConnection`; the test injects a mock
 * `ContainerInterface` so the DB plumbing is exercisable without
 * spinning up the full NC server container.
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
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Integration\Providers\PollsProvider;
use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\DB\QueryBuilder\ILiteral;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Unit tests for PollsProvider.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class PollsProviderTest extends TestCase
{

    /**
     * Build an IL10N pass-through.
     *
     * @return IL10N
     */
    private function buildL10n(): IL10N
    {
        $mock = $this->createMock(IL10N::class);
        $mock->method('t')->willReturnArgument(0);
        return $mock;
    }//end buildL10n()


    /**
     * Build an IAppManager mock that reports Polls as installed (or not).
     *
     * @param bool $installed Whether the Polls app reports installed.
     *
     * @return IAppManager
     */
    private function buildAppManager(bool $installed): IAppManager
    {
        $mock = $this->createMock(IAppManager::class);
        $mock->method('isInstalled')->willReturn($installed);
        return $mock;
    }//end buildAppManager()


    /**
     * Build an IUserSession that returns a user with the given UID.
     *
     * @param string|null $uid Uid or null for no user.
     *
     * @return IUserSession
     */
    private function buildUserSession(?string $uid): IUserSession
    {
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
     * Build a query-builder mock that returns the supplied rows when
     * `executeQuery()->fetchAll()` is called, or `$singleValue` when
     * `executeQuery()->fetchOne()` is called.
     *
     * The fluent setters (`select`, `selectDistinct`, `from`, `where`,
     * `andWhere`, `orderBy`, `createNamedParameter`, `expr`, `func`)
     * all return self / safe stub values, mirroring how the provider
     * threads them.
     *
     * @param array $rows        Rows for `fetchAll()`.
     * @param mixed $singleValue Value for `fetchOne()`.
     *
     * @return IQueryBuilder
     */
    private function buildQb(array $rows, $singleValue = 0): IQueryBuilder
    {
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
        $result->method('fetchOne')->willReturn($singleValue);
        $qb->method('executeQuery')->willReturn($result);
        return $qb;
    }//end buildQb()


    public function testMetadata(): void
    {
        $provider = new PollsProvider(
            container: $this->createMock(ContainerInterface::class),
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


    public function testIsEnabledFalseWhenPollsMissing(): void
    {
        $provider = new PollsProvider(
            container: $this->createMock(ContainerInterface::class),
            appManager: $this->buildAppManager(false),
            userSession: $this->buildUserSession('admin'),
            l10n: $this->buildL10n()
        );
        self::assertFalse($provider->isEnabled());
        self::assertSame([], $provider->list(register: 'r', schema: 's', objectId: 'uuid'));
    }//end testIsEnabledFalseWhenPollsMissing()


    public function testListEmptyWhenNoUser(): void
    {
        $provider = new PollsProvider(
            container: $this->createMock(ContainerInterface::class),
            appManager: $this->buildAppManager(true),
            userSession: $this->buildUserSession(null),
            l10n: $this->buildL10n()
        );

        self::assertSame([], $provider->list(register: 'r', schema: 's', objectId: 'uuid'));
    }//end testListEmptyWhenNoUser()


    public function testListWidenedPayloadHappyPath(): void
    {
        $uuid = 'abc-123';
        $marker = '[or:'.$uuid.']';
        $now = time();
        $futureExpire = $now + 86400;

        // Three QB hops per matched poll: polls, options, votes-count.
        // Plus the distinct-users voterCount QB.
        $pollsRows = [
            [
                'id'          => 42,
                'title'       => 'Lunch ' . $marker,
                'description' => 'Pick a day',
                'type'        => 'datePoll',
                'expire'      => $futureExpire,
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

        $qbPolls    = $this->buildQb($pollsRows);
        $qbOptions  = $this->buildQb($optionRows);
        $qbVotesMon = $this->buildQb([], 3); // 3 yes votes for Mon
        $qbVotesTue = $this->buildQb([], 1); // 1 yes vote for Tue
        $qbVoters   = $this->buildQb($voteRowsForVoters);

        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
            $qbPolls,
            $qbOptions,
            $qbVotesMon,
            $qbVotesTue,
            $qbVoters
        );

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->with('OCP\\IDBConnection')->willReturn($db);

        $provider = new PollsProvider(
            container: $container,
            appManager: $this->buildAppManager(true),
            userSession: $this->buildUserSession('admin'),
            l10n: $this->buildL10n()
        );

        $rows = $provider->list(register: 'r', schema: 's', objectId: $uuid);
        self::assertCount(1, $rows);

        $row = $rows[0];
        self::assertSame('42', $row['id']);
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
    }//end testListWidenedPayloadHappyPath()


    public function testListMarkerMismatchExcluded(): void
    {
        $pollsRows = [
            ['id' => 7, 'title' => 'Unrelated poll', 'description' => '', 'type' => 'textPoll', 'expire' => 0],
        ];
        $qb = $this->buildQb($pollsRows);

        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturn($qb);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($db);

        $provider = new PollsProvider(
            container: $container,
            appManager: $this->buildAppManager(true),
            userSession: $this->buildUserSession('admin'),
            l10n: $this->buildL10n()
        );

        $rows = $provider->list(register: 'r', schema: 's', objectId: 'no-match');
        self::assertSame([], $rows);
    }//end testListMarkerMismatchExcluded()


    public function testHealthOk(): void
    {
        $provider = new PollsProvider(
            container: $this->createMock(ContainerInterface::class),
            appManager: $this->buildAppManager(true),
            userSession: $this->buildUserSession('admin'),
            l10n: $this->buildL10n()
        );
        $h = $provider->health();
        self::assertSame('ok', $h['status']);
        self::assertSame('configured', $h['authStatus']);
        self::assertNull($h['message']);
    }//end testHealthOk()


    public function testHealthUnavailable(): void
    {
        $provider = new PollsProvider(
            container: $this->createMock(ContainerInterface::class),
            appManager: $this->buildAppManager(false),
            userSession: $this->buildUserSession('admin'),
            l10n: $this->buildL10n()
        );
        $h = $provider->health();
        self::assertSame('unavailable', $h['status']);
        self::assertNotNull($h['message']);
    }//end testHealthUnavailable()
}//end class
