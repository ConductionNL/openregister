<?php

/**
 * Characterisation test for linking and listing emails in EmailLinkService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace Unit\Service;

use Exception;
use OCA\OpenRegister\Db\EmailLink;
use OCA\OpenRegister\Db\EmailLinkMapper;
use OCA\OpenRegister\Service\EmailLinkService;
use OCA\OpenRegister\Service\EmailMessageRef;
use OCA\OpenRegister\Service\LinkServiceRuntime;
use OCA\OpenRegister\Service\LinkTarget;
use OCP\App\IAppManager;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the outcomes of linkEmail (refusals, the not-found and the inserted
 * row) and the paging of getLinkedEmails.
 */
class EmailLinkServiceLinkGoldenTest extends TestCase {

	private EmailLinkMapper&MockObject $mapper;

	private IDBConnection&MockObject $db;

	private IUserSession&MockObject $userSession;

	private IAppManager&MockObject $appManager;

	private EmailLinkService $service;

	protected function setUp(): void {
		$this->mapper = $this->getMockBuilder(EmailLinkMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findByObjectUuid', 'findByObjectAccountMessageUid', 'countByObjectUuid', 'insert'])
			->getMock();
		$this->db          = $this->createMock(IDBConnection::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->appManager  = $this->createMock(IAppManager::class);
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$this->service = new EmailLinkService(
			$this->mapper,
			$this->db,
			new LinkServiceRuntime($this->appManager, $this->userSession, $this->createMock(LoggerInterface::class))
		);
	}//end setUp()

	/**
	 * A query builder whose first row is the given one.
	 *
	 * @param array<string,mixed>|false $row The row.
	 *
	 * @return IQueryBuilder
	 */
	private function builder(array|false $row): IQueryBuilder {
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn($row);
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'addSelect', 'selectAlias', 'from', 'where', 'andWhere', 'innerJoin', 'leftJoin', 'setMaxResults', 'orderBy'] as $method) {
			$qb->method($method)->willReturnSelf();
		}

		$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$qb->method('executeQuery')->willReturn($result);
		return $qb;
	}//end builder()

	/**
	 * The link target and message of every case.
	 *
	 * @return array{0:LinkTarget,1:EmailMessageRef}
	 */
	private function request(string $uid): array {
		return [(new LinkTarget('obj', 3))->inSchema(4), (new EmailMessageRef(42, '7'))->withUid($uid)];
	}//end request()

	/**
	 * A message Mail does not have is refused with 404 after the ownership check.
	 *
	 * @return void
	 */
	public function testAnUnknownMessageIsRefused(): void {
		$this->db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->builder(['user_id' => 'alice']),
			$this->builder(false)
		);
		$this->mapper->method('findByObjectAccountMessageUid')->with('obj', 42, 7, null)->willReturn(null);
		$this->mapper->expects($this->never())->method('insert');

		[$target, $message] = $this->request('');
		try {
			$this->service->linkEmail($target, $message);
			$this->fail('expected a refusal');
		} catch (Exception $e) {
			$this->assertSame('Mail message not found', $e->getMessage());
			$this->assertSame(404, $e->getCode());
		}
	}//end testAnUnknownMessageIsRefused()

	/**
	 * A found message is inserted with the harvested fields; the row's uid fills a missing one.
	 *
	 * @return void
	 */
	public function testAFoundMessageIsInsertedWithItsHarvest(): void {
		$this->db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->builder(['user_id' => 'alice']),
			$this->builder(['uid' => 'row-uid', 'subject' => 'Hi', 'sender_email' => 's@x.nl', 'message_id' => '<m@x>'])
		);
		$this->mapper->method('findByObjectAccountMessageUid')->willReturn(null);
		$this->mapper->expects($this->once())->method('insert')->willReturnArgument(0);

		[$target, $message] = $this->request('');
		$link = $this->service->linkEmail($target, $message);

		$this->assertInstanceOf(EmailLink::class, $link);
		$this->assertSame('obj', $link->getObjectUuid());
		$this->assertSame(3, $link->getRegisterId());
		$this->assertSame(4, $link->getSchemaId());
		$this->assertSame(42, $link->getMailAccountId());
		$this->assertSame(7, $link->getMailMessageId());
		$this->assertSame('row-uid', $link->getMailMessageUid());
		$this->assertSame('Hi', $link->getSubject());
		$this->assertSame('s@x.nl', $link->getSender());
		$this->assertNull($link->getMailDate());
		$this->assertSame('alice', $link->getLinkedBy());
		$this->assertNotNull($link->getLinkedAt());
	}//end testAFoundMessageIsInsertedWithItsHarvest()

	/**
	 * A given uid is kept over the row's.
	 *
	 * @return void
	 */
	public function testAGivenUidIsKept(): void {
		$this->db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
			$this->builder(['user_id' => 'alice']),
			$this->builder(['uid' => 'row-uid'])
		);
		$this->mapper->method('findByObjectAccountMessageUid')->with('obj', 42, 7, 'mine')->willReturn(null);
		$this->mapper->method('insert')->willReturnArgument(0);

		[$target, $message] = $this->request('mine');
		$this->assertSame('mine', $this->service->linkEmail($target, $message)->getMailMessageUid());
	}//end testAGivenUidIsKept()

	/**
	 * Mutation check: an account of somebody else is refused before any message lookup.
	 *
	 * @return void
	 */
	public function testAForeignAccountIsRefusedFirst(): void {
		$this->db->expects($this->once())->method('getQueryBuilder')->willReturn($this->builder(['user_id' => 'bob']));
		$this->mapper->expects($this->never())->method('findByObjectAccountMessageUid');

		[$target, $message] = $this->request('');
		$this->expectExceptionCode(403);
		$this->service->linkEmail($target, $message);
	}//end testAForeignAccountIsRefusedFirst()

	/**
	 * The page window: limit clamped to [1, 200], the cursor offset, the next cursor.
	 *
	 * @return void
	 */
	public function testLinkedEmailsPaging(): void {
		$calls = [];
		$this->mapper->method('countByObjectUuid')->willReturn(9);
		$this->mapper->method('findByObjectUuid')->willReturnCallback(
			function (string $uuid, int $limit, int $offset) use (&$calls): array {
				$calls[] = [$uuid, $limit, $offset];
				$rows = [];
				for ($i = 0; $i < min($limit, 3); $i++) {
					$link = new EmailLink();
					$link->setObjectUuid($uuid);
					$link->setMailMessageId($offset + $i);
					$rows[] = $link;
				}

				return $rows;
			}
		);

		$first  = $this->service->getLinkedEmails('obj');
		$second = $this->service->getLinkedEmails('obj', '4', 2);
		$third  = $this->service->getLinkedEmails('obj', '-3', 0);
		$fourth = $this->service->getLinkedEmails('obj', 'abc', 999);

		$this->assertSame([['obj', 51, 0], ['obj', 3, 4], ['obj', 2, 0], ['obj', 201, 0]], $calls);
		$this->assertSame([3, 9, null], [count($first['items']), $first['total'], $first['nextCursor']]);
		$this->assertSame([2, 9, 6], [count($second['items']), $second['total'], $second['nextCursor']]);
		$this->assertSame([1, 9, 1], [count($third['items']), $third['total'], $third['nextCursor']]);
		$this->assertSame([3, 9, null], [count($fourth['items']), $fourth['total'], $fourth['nextCursor']]);
		$this->assertSame(['items', 'total', 'nextCursor'], array_keys($first));
		$this->assertSame(4, $second['items'][0]['mailMessageId']);
	}//end testLinkedEmailsPaging()
}//end class
