<?php

/**
 * Behaviour tests for EmailLinkService: the Mail picker reads, account ownership,
 * and the paging of linked emails.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;


use OCA\OpenRegister\Db\EmailLink;
use OCA\OpenRegister\Db\EmailLinkMapperInterface;
use OCA\OpenRegister\Service\EmailLinkService;
use OCA\OpenRegister\Service\EmailMessageRef;
use OCA\OpenRegister\Service\LinkTarget;
use OCA\OpenRegister\Service\LinkServiceRuntime;
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
use OCA\OpenRegister\Service\MailboxPage;

/**
 * Behaviour of the Mail picker reads and link pages of EmailLinkService over
 * stand-in Mail tables.
 */
class EmailLinkServiceBehaviourTest extends TestCase {

	/** @var array<string, list<mixed>> Answers per Mail table, in order. */
	private array $answers = [];

	/** @var string[] Tables whose query fails. */
	private array $failing = [];

	/** @var string[] Warnings logged. */
	private array $warnings = [];

	/** @var EmailLinkMapperInterface&MockObject */
	private EmailLinkMapperInterface $mapper;

	private bool $mailOn = true;

	private ?string $uid = 'alice';

	protected function setUp(): void {
		$this->mapper = $this->createMock(EmailLinkMapperInterface::class);
	}//end setUp()

	private function service(): EmailLinkService {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->with('mail')->willReturnCallback(fn (): bool => $this->mailOn);
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($this->uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($this->uid);
		}

		$session->method('getUser')->willReturn($user);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->builder());
		return new EmailLinkService($this->mapper, $db, new LinkServiceRuntime($apps, $session, $logger));
	}//end service()

	private function builder(): IQueryBuilder {
		$qb    = $this->createMock(IQueryBuilder::class);
		$table = '';
		foreach (['select', 'addSelect', 'where', 'andWhere', 'setMaxResults', 'orderBy', 'addOrderBy', 'leftJoin', 'innerJoin'] as $fluent) {
			$qb->method($fluent)->willReturnSelf();
		}

		$qb->method('from')->willReturnCallback(
			static function (string $from) use ($qb, &$table): IQueryBuilder {
				$table = $from;
				return $qb;
			}
		);
		$expr = $this->createMock(IExpressionBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn(':p');
		$qb->method('executeQuery')->willReturnCallback(
			function () use (&$table): IResult {
				if (in_array($table, $this->failing, true) === true) {
					throw new \RuntimeException($table.' unavailable');
				}

				$answer = array_shift($this->answers[$table]);
				$result = $this->createMock(IResult::class);
				$result->method('fetchAll')->willReturn(is_array($answer) === true && array_is_list($answer) === true ? $answer : []);
				$result->method('fetch')->willReturn($answer ?? false);
				return $result;
			}
		);
		return $qb;
	}//end builder()

	private function ownsAccount(): void {
		$this->answers['mail_accounts'][] = ['user_id' => 'alice'];
	}//end ownsAccount()

	public function testUnlinkRemovesTheLinkOrSaysItIsNotThere(): void {
		$this->mapper->method('deleteByObjectAndId')->willReturnMap([['obj-1', 5, 1], ['obj-1', 6, 0]]);

		$this->service()->unlinkEmail('obj-1', 5);

		$this->expectExceptionCode(404);
		$this->expectExceptionMessage('Email link not found');
		$this->service()->unlinkEmail('obj-1', 6);
	}

	public function testLinkedEmailsArePagedWithANextCursor(): void {
		$links = [];
		foreach ([1, 2, 3] as $id) {
			$link = new EmailLink();
			$link->setId($id);
			$links[] = $link;
		}

		$this->mapper->expects($this->once())->method('findByObjectUuid')->with('obj-1', 3, 4)->willReturn($links);
		$this->mapper->method('countByObjectUuid')->willReturn(9);

		$page = $this->service()->getLinkedEmails('obj-1', '4', 2);

		$this->assertSame([1, 2], array_column($page['items'], 'id'));
		$this->assertSame(9, $page['total']);
		$this->assertNotNull($page['nextCursor']);
	}

	public function testTheLastPageHasNoNextCursorAndABadCursorStartsAtZero(): void {
		$this->mapper->expects($this->once())->method('findByObjectUuid')->with('obj-1', 201, 0)->willReturn([]);
		$this->mapper->method('countByObjectUuid')->willReturn(0);

		$page = $this->service()->getLinkedEmails('obj-1', '-3', 999);

		$this->assertSame(['items' => [], 'total' => 0, 'nextCursor' => null], $page);
	}

	public function testAccountsAreListedWithALabelAndInvalidRowsDropped(): void {
		$this->answers['mail_accounts'][] = [['id' => 1, 'email' => 'a@x.nl', 'name' => 'Werk'], ['id' => 2, 'email' => 'b@x.nl', 'name' => ''], ['id' => 0]];

		$this->assertSame(
			[['id' => 1, 'label' => 'Werk', 'email' => 'a@x.nl'], ['id' => 2, 'label' => 'b@x.nl', 'email' => 'b@x.nl']],
			$this->service()->getAvailableAccounts()
		);
	}

	public function testAccountsAreEmptyWithoutAUserOrMailOrWhenTheQueryFails(): void {
		$this->uid = null;
		$this->assertSame([], $this->service()->getAvailableAccounts());

		$this->uid    = 'alice';
		$this->mailOn = false;
		$this->assertSame([], $this->service()->getAvailableAccounts());

		$this->mailOn    = true;
		$this->failing[] = 'mail_accounts';
		$this->assertSame([], $this->service()->getAvailableAccounts());
		$this->assertSame(['[EmailLinkService] getAvailableAccounts failed: mail_accounts unavailable'], $this->warnings);
	}

	public function testMailboxesOfAnOwnedAccountAreListedWithTheirLastSegment(): void {
		$this->ownsAccount();
		$this->answers['mail_mailboxes'][] = [['id' => 3, 'name' => 'INBOX.Archief'], ['id' => 4, 'name' => 'INBOX.'], ['id' => 5, 'name' => ''], ['id' => 0, 'name' => 'X']];

		$this->assertSame(
			[['id' => 3, 'name' => 'INBOX.Archief', 'displayName' => 'Archief'], ['id' => 4, 'name' => 'INBOX.', 'displayName' => 'INBOX.']],
			$this->service()->getMailboxesForAccount(7)
		);
	}

	public function testMailboxesAreEmptyForAnInvalidAccountOrAFailingQuery(): void {
		$this->assertSame([], $this->service()->getMailboxesForAccount(0));

		$this->ownsAccount();
		$this->failing[] = 'mail_mailboxes';
		$this->assertSame([], $this->service()->getMailboxesForAccount(7));
		$this->assertSame(['[EmailLinkService] getMailboxesForAccount failed: mail_mailboxes unavailable'], $this->warnings);
	}

	public function testOwnershipRefusesAnotherUsersAccount(): void {
		$this->answers['mail_accounts'][] = ['user_id' => 'mallory'];

		$this->expectExceptionCode(403);
		$this->service()->getMailboxesForAccount(7);
	}

	public function testOwnershipRefusesAnUnknownAccount(): void {
		$this->answers['mail_accounts'][] = false;

		$this->expectExceptionCode(403);
		$this->service()->assertAccountOwnership(7);
	}

	public function testOwnershipNeedsAUser(): void {
		$this->uid = null;

		$this->expectExceptionCode(401);
		$this->service()->assertAccountOwnership(7);
	}

	public function testOwnershipLookupFailureIsNotFound(): void {
		$this->failing[] = 'mail_accounts';

		try {
			$this->service()->assertAccountOwnership(7);
			$this->fail('expected a refusal');
		} catch (\Exception $e) {
			$this->assertSame([404, 'Mail account not found'], [$e->getCode(), $e->getMessage()]);
		}

		$this->assertSame(['[EmailLinkService] assertAccountOwnership lookup failed: mail_accounts unavailable'], $this->warnings);
	}

	public function testMessagesArePagedAfterTheLastIdWithUtcDates(): void {
		$this->ownsAccount();
		$this->answers['mail_mailboxes'][] = ['id' => 3];
		$this->answers['mail_messages'][]  = [
			['id' => 10, 'uid' => '100', 'subject' => 'A', 'sender_email' => 'x@y.nl', 'sent_at' => 0],
			['id' => 9, 'uid' => '99', 'subject' => 'B'],
			['id' => 8],
		];

		$page = $this->service()->getMessagesForMailbox((new MailboxPage(7, 'INBOX'))->startingAfter(null, 2));

		$this->assertSame(9, $page['nextCursor']);
		$this->assertSame(['id' => 10, 'uid' => '100', 'subject' => 'A', 'sender' => 'x@y.nl', 'date' => date('c', 0)], $page['items'][0]);
		$this->assertSame(['id' => 9, 'uid' => '99', 'subject' => 'B', 'sender' => null, 'date' => null], $page['items'][1]);
	}

	public function testAShortPageHasNoNextCursor(): void {
		$this->ownsAccount();
		$this->answers['mail_mailboxes'][] = ['id' => 3];
		$this->answers['mail_messages'][]  = [['id' => 10]];

		$this->assertNull($this->service()->getMessagesForMailbox((new MailboxPage(7, 'INBOX'))->startingAfter('50', 2))['nextCursor']);
	}

	public function testAnExactlyFullPageHasNoNextCursor(): void {
		$this->ownsAccount();
		$this->answers['mail_mailboxes'][] = ['id' => 3];
		$this->answers['mail_messages'][]  = [['id' => 10], ['id' => 9]];

		$page = $this->service()->getMessagesForMailbox((new MailboxPage(7, 'INBOX'))->startingAfter(null, 2));

		$this->assertCount(2, $page['items']);
		$this->assertNull($page['nextCursor']);
	}

	public function testMessagesAreEmptyForAMissingMailbox(): void {
		$this->ownsAccount();
		$this->answers['mail_mailboxes'][] = false;

		$this->assertSame(['items' => [], 'nextCursor' => null], $this->service()->getMessagesForMailbox((new MailboxPage(7, 'Nope'))->startingAfter(null, 50)));
	}

	public function testMessagesAreEmptyWhenTheMailboxLookupFails(): void {
		$this->ownsAccount();
		$this->failing[] = 'mail_mailboxes';

		$this->assertSame(['items' => [], 'nextCursor' => null], $this->service()->getMessagesForMailbox((new MailboxPage(7, 'INBOX'))->startingAfter(null, 50)));
		$this->assertSame(['[EmailLinkService] resolveMailboxId failed: mail_mailboxes unavailable'], $this->warnings);
	}

	public function testMessagesAreEmptyWhenTheMessageQueryFails(): void {
		$this->ownsAccount();
		$this->answers['mail_mailboxes'][] = ['id' => 3];
		$this->failing[] = 'mail_messages';

		$this->assertSame(['items' => [], 'nextCursor' => null], $this->service()->getMessagesForMailbox((new MailboxPage(7, 'INBOX'))->startingAfter(null, 50)));
		$this->assertSame(['[EmailLinkService] getMessagesForMailbox failed: mail_messages unavailable'], $this->warnings);
	}

	public function testLinkingAMessageWhoseLookupFailsIsNotFound(): void {
		$this->ownsAccount();
		$this->failing[] = 'mail_messages';
		$this->mapper->method('findByObjectAccountMessageUid')->willReturn(null);
		$this->mapper->expects($this->never())->method('insert');

		try {
			$this->service()->linkEmail(new LinkTarget('obj-1', 1), new EmailMessageRef(7, '42'));
			$this->fail('expected a refusal');
		} catch (\Exception $e) {
			$this->assertSame([404, 'Mail message not found'], [$e->getCode(), $e->getMessage()]);
		}

		$this->assertSame(['[EmailLinkService] fetchMessageRow failed: mail_messages unavailable'], $this->warnings);
	}

	public function testAnUnreadableMailDateIsLeftEmpty(): void {
		$this->ownsAccount();
		$this->answers['mail_messages'][] = ['uid' => '9', 'subject' => 'S', 'sender_email' => 'a@b.nl', 'message_id' => '<m@x>'];
		$this->mapper->method('findByObjectAccountMessageUid')->willReturn(null);
		$this->mapper->method('insert')->willReturnArgument(0);

		$link = $this->service()->linkEmail(new LinkTarget('obj-1', 1), new EmailMessageRef(7, '42'));

		$this->assertNull($link->getMailDate());
		$this->assertSame('S', $link->getSubject());
		$this->assertSame('alice', $link->getLinkedBy());
	}

	public function testMessagesAreEmptyWithoutAMailboxName(): void {
		$this->assertSame(['items' => [], 'nextCursor' => null], $this->service()->getMessagesForMailbox((new MailboxPage(7, ''))->startingAfter(null, 50)));
	}
}//end class
