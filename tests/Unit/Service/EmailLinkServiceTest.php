<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\EmailLinkService}.
 *
 * Exercises the Tier-2 service contract (link/unlink/list + account
 * discovery) against a mocked EmailLinkMapper and IDBConnection. Tests
 * that require real Mail-table joins are tagged
 * `requires-app-mail` and excluded from the default suite (per the
 * Mail-app PHPUnit group convention).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/integration-email/tasks.md
 */

declare(strict_types=1);

namespace Unit\Service;

use Exception;
use OCA\OpenRegister\Db\EmailLink;
use OCA\OpenRegister\Db\EmailLinkMapper;
use OCA\OpenRegister\Service\EmailLinkService;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * EmailLinkServiceTest.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class EmailLinkServiceTest extends TestCase {
	private EmailLinkMapper&MockObject $mapper;
	private IAppManager&MockObject $appManager;
	private IDBConnection&MockObject $db;
	private IUserSession&MockObject $userSession;
	private LoggerInterface&MockObject $logger;
	private EmailLinkService $service;

	protected function setUp(): void {
		$this->mapper = $this->getMockBuilder(EmailLinkMapper::class)
			->disableOriginalConstructor()
			->onlyMethods([
				'findByObjectUuid',
				'findByObjectAndMessage',
				'findByObjectAccountMessageUid',
				'countByObjectUuid',
				'deleteByObjectAndId',
				'insert',
			])
			->getMock();
		$this->appManager = $this->createMock(IAppManager::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new EmailLinkService(
			$this->mapper,
			$this->appManager,
			$this->db,
			$this->userSession,
			$this->logger
		);
	}

	private function setupUser(string $uid = 'admin'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	public function testIsMailAvailableTrue(): void {
		$this->appManager->method('isEnabledForUser')->with('mail')->willReturn(true);
		$this->assertTrue($this->service->isMailAvailable());
	}

	public function testIsMailAvailableFalseWhenAppManagerThrows(): void {
		$this->appManager
			->method('isEnabledForUser')
			->willThrowException(new Exception('boom'));

		$this->assertFalse($this->service->isMailAvailable());
	}

	public function testLinkEmailThrowsWhenNoUser(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->expectException(Exception::class);
		$this->expectExceptionCode(401);
		$this->service->linkEmail('uuid', 1, 1, 42, '7', 'uid-7');
	}

	public function testLinkEmailThrowsWhenMailDisabled(): void {
		$this->setupUser();
		$this->appManager->method('isEnabledForUser')->with('mail')->willReturn(false);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(503);
		$this->service->linkEmail('uuid', 1, 1, 42, '7', 'uid-7');
	}

	public function testLinkEmailReturnsExistingRowWhenAlreadyLinked(): void {
		$this->setupUser();
		$this->appManager->method('isEnabledForUser')->with('mail')->willReturn(true);

		$existing = new EmailLink();
		$existing->setObjectUuid('uuid');
		$existing->setMailAccountId(42);
		$existing->setMailMessageId(7);
		$existing->setMailMessageUid('uid-7');

		$this->mapper
			->expects($this->once())
			->method('findByObjectAccountMessageUid')
			->with('uuid', 42, 7, 'uid-7')
			->willReturn($existing);

		// insert() must NOT be called.
		$this->mapper->expects($this->never())->method('insert');

		$result = $this->service->linkEmail('uuid', 1, 1, 42, '7', 'uid-7');
		$this->assertSame($existing, $result);
	}

	public function testLinkEmailRejectsBadMessageId(): void {
		$this->setupUser();
		$this->appManager->method('isEnabledForUser')->with('mail')->willReturn(true);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(400);
		$this->service->linkEmail('uuid', 1, 1, 42, '0', '');
	}

	public function testUnlinkEmailThrowsWhenNotFound(): void {
		$this->mapper
			->expects($this->once())
			->method('deleteByObjectAndId')
			->with('uuid', 123)
			->willReturn(0);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(404);
		$this->service->unlinkEmail('uuid', 123);
	}

	public function testUnlinkEmailSucceedsWhenRowFound(): void {
		$this->mapper
			->expects($this->once())
			->method('deleteByObjectAndId')
			->with('uuid', 123)
			->willReturn(1);

		// Should not throw. The mapper ->expects($this->once()) above is
		// the behavioural assertion (verified at tear-down); the explicit
		// assertion below documents the "completes without exception"
		// contract and keeps the test from being flagged as risky.
		$this->service->unlinkEmail('uuid', 123);
		$this->addToAssertionCount(1);
	}

	public function testGetLinkedEmailsReturnsPagedShape(): void {
		$link = new EmailLink();
		$link->setObjectUuid('uuid');
		$link->setMailAccountId(1);
		$link->setMailMessageId(7);

		$this->mapper
			->expects($this->once())
			->method('findByObjectUuid')
			->with('uuid', 51, 0)
			->willReturn([$link]);
		$this->mapper
			->expects($this->once())
			->method('countByObjectUuid')
			->with('uuid')
			->willReturn(1);

		$result = $this->service->getLinkedEmails('uuid', null, 50);

		$this->assertSame(1, $result['total']);
		$this->assertNull($result['nextCursor']);
		$this->assertCount(1, $result['items']);
	}

	public function testGetLinkedEmailsHasNextCursorWhenPageFull(): void {
		// Build limit+1 links to trigger "has more".
		$links = [];
		for ($i = 0; $i < 3; $i++) {
			$link = new EmailLink();
			$link->setObjectUuid('uuid');
			$link->setMailAccountId(1);
			$link->setMailMessageId($i);
			$links[] = $link;
		}

		$this->mapper
			->method('findByObjectUuid')
			->willReturn($links);
		$this->mapper
			->method('countByObjectUuid')
			->willReturn(10);

		$result = $this->service->getLinkedEmails('uuid', null, 2);

		$this->assertSame(10, $result['total']);
		$this->assertCount(2, $result['items']);
		$this->assertSame(2, $result['nextCursor']);
	}

	public function testGetAvailableAccountsReturnsEmptyWhenMailDisabled(): void {
		$this->setupUser();
		$this->appManager->method('isEnabledForUser')->with('mail')->willReturn(false);

		$this->assertSame([], $this->service->getAvailableAccounts());
	}

	public function testGetAvailableAccountsReturnsEmptyWhenNoUser(): void {
		$this->userSession->method('getUser')->willReturn(null);
		// No DB call should happen — guard returns first.
		$this->db->expects($this->never())->method('getQueryBuilder');

		$this->assertSame([], $this->service->getAvailableAccounts());
	}

	public function testGetMailboxesForAccountReturnsEmptyOnInvalidAccount(): void {
		$this->appManager->method('isEnabledForUser')->with('mail')->willReturn(true);

		$this->assertSame([], $this->service->getMailboxesForAccount(0));
	}

	public function testGetMessagesForMailboxReturnsEmptyShape(): void {
		$this->appManager->method('isEnabledForUser')->with('mail')->willReturn(false);

		$result = $this->service->getMessagesForMailbox(1, 'INBOX', null, 25);
		$this->assertSame(['items' => [], 'nextCursor' => null], $result);
	}
}//end class
