<?php

/**
 * MailHeaderTest
 *
 * The header row is built from what EmlParser read: ids without brackets,
 * an id that does not fit dropped rather than cut, the sent date kept.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Files\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Files\Db;

use DateTimeImmutable;
use OCA\OpenRegister\Files\Db\MailHeader;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Files\Db\MailHeader
 *
 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md#requirement-collected-mail-files-carry-their-thread-headers-req-rth-010
 */
class MailHeaderTest extends TestCase {
	/**
	 * The parsed headers become the row, references in order.
	 *
	 * @return void
	 */
	public function testTheParsedHeadersBecomeTheRow(): void {
		$row = MailHeader::fromHeaders(
			fileId: 7,
			headers: [
				'messageId' => 'a2@gemeente.nl',
				'inReplyTo' => 'a1@gemeente.nl',
				'references' => ['a0@gemeente.nl', 'a1@gemeente.nl'],
				'date' => new DateTimeImmutable('2026-11-10T09:30:00+01:00'),
				'subject' => 'Re: uw verzoek',
			]
		);

		$this->assertSame(7, $row->getFileId());
		$this->assertSame('a2@gemeente.nl', $row->getMessageId());
		$this->assertSame('a1@gemeente.nl', $row->getInReplyTo());
		$this->assertSame('["a0@gemeente.nl","a1@gemeente.nl"]', $row->getReferenceIds());
		$this->assertSame(['a0@gemeente.nl', 'a1@gemeente.nl'], $row->references());
		$this->assertSame('2026-11-10T09:30:00+01:00', $row->getSentAt()->format(DATE_ATOM));
	}//end testTheParsedHeadersBecomeTheRow()

	/**
	 * Angle brackets and surrounding space are stripped, so every id is stored one way.
	 *
	 * @return void
	 */
	public function testBracketsAreStripped(): void {
		$row = MailHeader::fromHeaders(fileId: 1, headers: ['messageId' => ' <m@x> ', 'inReplyTo' => '<p@x>', 'references' => ['<r@x>']]);

		$this->assertSame('m@x', $row->getMessageId());
		$this->assertSame('p@x', $row->getInReplyTo());
		$this->assertSame(['r@x'], $row->references());
	}//end testBracketsAreStripped()

	/**
	 * A mail without thread headers is a row with empty ids and no date.
	 *
	 * @return void
	 */
	public function testAMailWithoutThreadHeadersHasEmptyIds(): void {
		$row = MailHeader::fromHeaders(fileId: 3, headers: ['messageId' => null, 'inReplyTo' => '', 'references' => [], 'date' => null]);

		$this->assertSame('', $row->getMessageId());
		$this->assertSame('', $row->getInReplyTo());
		$this->assertSame('[]', $row->getReferenceIds());
		$this->assertSame([], $row->references());
		$this->assertNull($row->getSentAt());
	}//end testAMailWithoutThreadHeadersHasEmptyIds()

	/**
	 * An id longer than the column is dropped, never cut into a different id.
	 *
	 * @return void
	 */
	public function testAnIdThatDoesNotFitIsDropped(): void {
		$long = str_repeat('x', MailHeader::MAX_ID_LENGTH) . '@x';
		$row  = MailHeader::fromHeaders(fileId: 1, headers: ['messageId' => $long, 'inReplyTo' => $long, 'references' => [$long, 'ok@x', '', 5]]);

		$this->assertSame('', $row->getMessageId());
		$this->assertSame('', $row->getInReplyTo());
		$this->assertSame(['ok@x'], $row->references());
	}//end testAnIdThatDoesNotFitIsDropped()

	/**
	 * A date that is not a date object is not stored.
	 *
	 * @return void
	 */
	public function testAStringDateIsNotStored(): void {
		$this->assertNull(MailHeader::fromHeaders(fileId: 1, headers: ['date' => 'yesterday'])->getSentAt());
	}//end testAStringDateIsNotStored()

	/**
	 * A stored chain that is not a JSON list reads as no references.
	 *
	 * @return void
	 */
	public function testAnUnreadableChainIsNoReferences(): void {
		$row = new MailHeader();
		$row->setReferenceIds('not json');
		$this->assertSame([], $row->references());

		$row->setReferenceIds('["a@x", 3, ""]');
		$this->assertSame(['a@x'], $row->references());
	}//end testAnUnreadableChainIsNoReferences()
}//end class
