<?php

/**
 * The Polls leaf when the Polls tables do not answer: a linked poll stays
 * listed from its link with no deadline, no options and no voters, and a
 * failing vote count reads as zero votes for that option only.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration\Providers
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Db\PollLink;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Db\PollLinkMapperInterface;
use OCA\OpenRegister\Service\Integration\Providers\LeafProviderPlatform;
use OCA\OpenRegister\Service\Integration\Providers\PollsProvider;
use OCP\App\IAppManager;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * PollsProvider degrade paths.
 */
class PollsProviderDegradeBehaviourTest extends TestCase {

	/**
	 * What each query answers, keyed by table (and "polls_votes:count" /
	 * "polls_votes:voters"); a Throwable makes it fail.
	 *
	 * @var array<string, mixed>
	 */
	private array $answers = [];

	private function provider(PollLink $link): PollsProvider {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn(true);
		$mapper = $this->createMock(PollLinkMapperInterface::class);
		$mapper->method('findByObjectUuid')->willReturn([$link]);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->queryBuilder());

		return new PollsProvider(new LeafProviderPlatform($db, $apps, $this->createMock(IL10N::class)), $mapper);
	}//end provider()

	private function queryBuilder(): IQueryBuilder {
		$state = ['table' => '', 'kind' => ''];
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['where', 'andWhere', 'orderBy'] as $fluent) {
			$qb->method($fluent)->willReturnSelf();
		}
		$qb->method('select')->willReturnCallback(
			function (...$columns) use (&$state, $qb): IQueryBuilder {
				$state['kind'] = ($columns[0] instanceof IQueryFunction ? ':count' : '');
				return $qb;
			}
		);
		$qb->method('selectDistinct')->willReturnCallback(
			function () use (&$state, $qb): IQueryBuilder {
				$state['kind'] = ':voters';
				return $qb;
			}
		);
		$qb->method('from')->willReturnCallback(
			function (string $table) use (&$state, $qb): IQueryBuilder {
				$state['table'] = $table;
				return $qb;
			}
		);
		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('count')->willReturn($this->createMock(IQueryFunction::class));
		$qb->method('func')->willReturn($func);
		$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$qb->method('createNamedParameter')->willReturn(':p');
		$qb->method('executeQuery')->willReturnCallback(
			function () use (&$state): IResult {
				$answer = ($this->answers[$state['table'].$state['kind']] ?? null);
				if ($answer instanceof \Throwable) {
					throw $answer;
				}
				$result = $this->createMock(IResult::class);
				$result->method('fetch')->willReturn($answer ?? false);
				$result->method('fetchAll')->willReturn($answer ?? []);
				$result->method('fetchOne')->willReturn($answer ?? 0);
				return $result;
			}
		);
		return $qb;
	}//end queryBuilder()

	private static function link(): PollLink {
		$link = new PollLink();
		$link->setId(11);
		$link->setPollId(5);
		$link->setPollTitle('Linked title');
		$link->setPollType('datePoll');
		return $link;
	}//end link()

	public function testAPollWhoseTablesFailIsListedFromItsLinkWithNothingCounted(): void {
		$failure = new RuntimeException('no polls tables');
		$this->answers = ['polls_polls' => $failure, 'polls_options' => $failure, 'polls_votes:voters' => $failure];

		$rows = $this->provider(self::link())->list(new ObjectRef(register: 'r', schema: 's', id: 'obj-1'));

		$this->assertSame(
			[
				[
					'id' => '5',
					'pollId' => 5,
					'title' => 'Linked title',
					'description' => '',
					'type' => 'datePoll',
					'url' => '/index.php/apps/polls/vote/5',
					'deadline' => null,
					'closed' => false,
					'voterCount' => 0,
					'options' => [],
					'linkId' => 11,
				],
			],
			$rows
		);
	}//end testAPollWhoseTablesFailIsListedFromItsLinkWithNothingCounted()

	public function testAFailingVoteCountReadsAsZeroVotesForThatOption(): void {
		$this->answers = [
			'polls_polls' => ['id' => 5, 'title' => 'Live', 'description' => 'D', 'type' => 'textPoll', 'expire' => 0],
			'polls_options' => [['id' => 1, 'poll_option_text' => 'Yes please', 'poll_option_hash' => 'h1']],
			'polls_votes:count' => new RuntimeException('votes table locked'),
			'polls_votes:voters' => [['user_id' => 'a'], ['user_id' => 'b']],
		];

		$row = $this->provider(self::link())->list(new ObjectRef(register: 'r', schema: 's', id: 'obj-1'))[0];

		$this->assertSame([['id' => 1, 'text' => 'Yes please', 'votes' => 0]], $row['options']);
		$this->assertSame(2, $row['voterCount']);
		$this->assertNull($row['deadline']);
		$this->assertFalse($row['closed']);
		$this->assertSame(['Live', 'textPoll'], [$row['title'], $row['type']]);
	}//end testAFailingVoteCountReadsAsZeroVotesForThatOption()
}//end class
