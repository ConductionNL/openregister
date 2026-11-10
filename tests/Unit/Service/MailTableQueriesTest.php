<?php

/**
 * MailTableQueries: the queries the mail picker runs against the Mail app's tables.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\MailboxPage;
use OCA\OpenRegister\Service\MailTableQueries;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Each query, as the builder calls it records; parameters show as ":value[:type]".
 *
 * @covers \OCA\OpenRegister\Service\MailTableQueries
 * @covers \OCA\OpenRegister\Service\MailboxPage
 */
class MailTableQueriesTest extends TestCase {

	/**
	 * Builder calls, in order: [method, arguments].
	 *
	 * @var array<int, array{0: string, 1: array<int, mixed>}>
	 */
	private array $calls = [];

	/**
	 * The text of each composite expression handed out, by object id.
	 *
	 * @var array<int, string>
	 */
	private array $composites = [];

	/**
	 * The builder the connection hands out.
	 *
	 * @var IQueryBuilder&MockObject
	 */
	private IQueryBuilder $qb;

	/**
	 * The queries under test.
	 *
	 * @var MailTableQueries
	 */
	private MailTableQueries $queries;

	/**
	 * A recording builder: fluent calls are logged, parameters and expressions render as text.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'addSelect', 'from', 'where', 'andWhere', 'orderBy', 'addOrderBy', 'setMaxResults', 'leftJoin', 'innerJoin'] as $method) {
			$this->qb->method($method)->willReturnCallback(
				function (...$arguments) use ($method): IQueryBuilder {
					$this->calls[] = [$method, array_map(fn ($a) => $this->text(value: $a), array_values(array_filter($arguments, fn ($a): bool => $a !== null)))];
					return $this->qb;
				}
			);
		}

		$this->qb->method('createNamedParameter')->willReturnCallback(
			fn ($value, $type = IQueryBuilder::PARAM_STR): string => ':' . $value . ($type === IQueryBuilder::PARAM_INT ? ':int' : '')
		);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(fn ($x, $y): string => $x . ' = ' . $y);
		$expr->method('lt')->willReturnCallback(fn ($x, $y): string => $x . ' < ' . $y);
		$expr->method('andX')->willReturnCallback(
			function (...$parts): ICompositeExpression {
				$composite = $this->createMock(ICompositeExpression::class);
				$this->composites[spl_object_id($composite)] = '(' . implode(' AND ', $parts) . ')';
				return $composite;
			}
		);
		$this->qb->method('expr')->willReturn($expr);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($this->qb);
		$this->queries = new MailTableQueries($db);
	}//end setUp()

	/**
	 * A builder argument as text: a composite expression by what it joined.
	 *
	 * @param mixed $value The argument.
	 *
	 * @return mixed
	 */
	private function text(mixed $value): mixed {
		if ($value instanceof ICompositeExpression) {
			return $this->composites[spl_object_id($value)];
		}

		return $value;
	}//end text()

	/**
	 * The sender join every message query shares.
	 *
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private function senderJoin(): array {
		return ['leftJoin', ['m', 'mail_recipients', 'r', '(r.message_id = m.id AND r.type = :0)']];
	}//end senderJoin()

	/**
	 * Accounts are the user's, by name.
	 *
	 * @return void
	 */
	public function testAccountsQuery(): void {
		$this->assertSame($this->qb, $this->queries->accountsQuery('ann'));
		$this->assertSame(
			[
				['select', ['id', 'name', 'email']],
				['from', ['mail_accounts']],
				['where', ['user_id = :ann']],
				['orderBy', ['name', 'ASC']],
			],
			$this->calls
		);
	}//end testAccountsQuery()

	/**
	 * Mailboxes of an account, by name; the owner of an account; one mailbox by name.
	 *
	 * @return void
	 */
	public function testMailboxAndOwnerQueries(): void {
		$this->queries->mailboxesQuery(4);
		$this->queries->accountOwnerQuery(4);
		$this->queries->mailboxIdQuery(4, 'INBOX');

		$this->assertSame(
			[
				['select', ['id', 'name']],
				['from', ['mail_mailboxes']],
				['where', ['account_id = :4:int']],
				['orderBy', ['name', 'ASC']],
				['select', ['user_id']],
				['from', ['mail_accounts']],
				['where', ['id = :4:int']],
				['setMaxResults', [1]],
				['select', ['id']],
				['from', ['mail_mailboxes']],
				['where', ['account_id = :4:int']],
				['andWhere', ['name = :INBOX']],
				['setMaxResults', [1]],
			],
			$this->calls
		);
	}//end testMailboxAndOwnerQueries()

	/**
	 * The first page reads one row beyond the limit, newest first, without a cursor condition.
	 *
	 * @return void
	 */
	public function testTheFirstMailboxPage(): void {
		$page = (new MailboxPage(accountId: 4, mailbox: 'INBOX'))->startingAfter(afterCursor: '', limit: 20);

		$this->queries->mailboxMessagesQuery(9, $page);

		$this->assertSame(0, $page->cursorId());
		$this->assertSame(20, $page->limit());
		$this->assertSame(
			[
				['select', ['m.id', 'm.uid', 'm.subject', 'm.sent_at']],
				['addSelect', ['r.email AS sender_email']],
				['from', ['mail_messages', 'm']],
				$this->senderJoin(),
				['where', ['m.mailbox_id = :9:int']],
				['orderBy', ['m.sent_at', 'DESC']],
				['addOrderBy', ['m.id', 'DESC']],
				['setMaxResults', [21]],
			],
			$this->calls
		);
	}//end testTheFirstMailboxPage()

	/**
	 * A later page continues below the cursor id.
	 *
	 * @return void
	 */
	public function testALaterMailboxPageStartsBelowTheCursor(): void {
		$page = (new MailboxPage(accountId: 4, mailbox: 'INBOX'))->startingAfter(afterCursor: '120', limit: 5);

		$this->queries->mailboxMessagesQuery(9, $page);

		$this->assertSame(['andWhere', ['m.id < :120:int']], $this->calls[array_key_last($this->calls)]);
		$this->assertSame(['setMaxResults', [6]], $this->calls[7]);
		$this->assertSame(0, (new MailboxPage(accountId: 4, mailbox: 'INBOX'))->startingAfter(afterCursor: null, limit: 5)->cursorId());
	}//end testALaterMailboxPageStartsBelowTheCursor()

	/**
	 * One message, only through a mailbox of the given account.
	 *
	 * @return void
	 */
	public function testOneMessageIsScopedToTheAccount(): void {
		$this->queries->messageQuery(4, 77);

		$this->assertSame(
			[
				['select', ['m.id', 'm.uid', 'm.subject', 'm.sent_at']],
				['addSelect', ['r.email AS sender_email']],
				['from', ['mail_messages', 'm']],
				['addSelect', ['m.message_id']],
				['innerJoin', ['m', 'mail_mailboxes', 'mb', '(mb.id = m.mailbox_id AND mb.account_id = :4:int)']],
				$this->senderJoin(),
				['where', ['m.id = :77:int']],
				['setMaxResults', [1]],
			],
			$this->calls
		);
	}//end testOneMessageIsScopedToTheAccount()

	/**
	 * Rows and the first row are read and the cursor is closed each time.
	 *
	 * @return void
	 */
	public function testResultsAreReadAndClosed(): void {
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn([['id' => 1], ['id' => 2]]);
		$result->method('fetch')->willReturn(false);
		$result->expects($this->exactly(2))->method('closeCursor');
		$this->qb->method('executeQuery')->willReturn($result);

		$this->assertSame([['id' => 1], ['id' => 2]], $this->queries->rowsOf($this->qb));
		$this->assertFalse($this->queries->firstRowOf($this->qb));
	}//end testResultsAreReadAndClosed()
}//end class
