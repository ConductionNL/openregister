<?php

/**
 * Unit tests for the mailbox page window: cursor, limit and the look-ahead fetch size.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\MailboxPage;
use PHPUnit\Framework\TestCase;

/**
 * A page starts at the first message, a cursor moves it, and one extra row is fetched to tell whether more follow.
 *
 * @covers \OCA\OpenRegister\Service\MailboxPage
 */
class MailboxPageTest extends TestCase {

	/**
	 * A fresh page starts at cursor 0, one message, fetching two.
	 *
	 * @return void
	 */
	public function testAFreshPageStartsAtTheBeginning(): void {
		$page = new MailboxPage(3, 'INBOX');

		$this->assertSame(0, $page->cursorId());
		$this->assertSame(1, $page->limit());
		$this->assertSame(2, $page->fetchSize());

	}//end testAFreshPageStartsAtTheBeginning()

	/**
	 * A cursor moves the page, the limit sizes it, and the original page is left as it was.
	 *
	 * @return void
	 */
	public function testStartingAfterACursorIsACopy(): void {
		$page  = new MailboxPage(3, 'INBOX');
		$moved = $page->startingAfter('120', 25);

		$this->assertSame(120, $moved->cursorId());
		$this->assertSame(25, $moved->limit());
		$this->assertSame(26, $moved->fetchSize());
		$this->assertSame(3, $moved->accountId);
		$this->assertSame('INBOX', $moved->mailbox);
		$this->assertSame(0, $page->cursorId(), 'the original page did not move');
		$this->assertSame(1, $page->limit());

	}//end testStartingAfterACursorIsACopy()

	/**
	 * No cursor, or an empty one, starts at the beginning.
	 *
	 * @return void
	 */
	public function testAnEmptyCursorStartsAtTheBeginning(): void {
		$page = new MailboxPage(1, 'Sent');

		$this->assertSame(0, $page->startingAfter(null, 10)->cursorId());
		$this->assertSame(0, $page->startingAfter('', 10)->cursorId());

	}//end testAnEmptyCursorStartsAtTheBeginning()
}//end class
