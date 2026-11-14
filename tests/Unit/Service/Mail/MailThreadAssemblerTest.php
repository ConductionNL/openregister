<?php

/**
 * MailThreadAssembler groups collected mails into reply threads by their headers.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Mail
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md#requirement-a-message-is-read-with-the-messages-around-it-req-rth-011
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Mail;

use OCA\OpenRegister\Service\Mail\MailThreadAssembler;
use PHPUnit\Framework\TestCase;

/**
 * REQ-RTH-011: threading is by `In-Reply-To` then the last `References`
 * entry, never by subject; an absent parent is a gap; a duplicate
 * Message-ID is one node.
 *
 * @covers \OCA\OpenRegister\Service\Mail\MailThreadAssembler
 */
final class MailThreadAssemblerTest extends TestCase {

	/**
	 * One header row.
	 *
	 * @param array<string, mixed> $over Overrides onto a blank row.
	 *
	 * @return array<string, mixed>
	 */
	private static function mail(array $over): array {
		return array_merge(
			['messageId' => '', 'inReplyTo' => '', 'references' => [], 'sentAt' => null, 'from' => '', 'subject' => '', 'fileId' => null],
			$over
		);
	}//end mail()

	/**
	 * The message ids of a thread, in order.
	 *
	 * @param array<int, array<string, mixed>> $thread The thread nodes.
	 *
	 * @return array<int, string>
	 */
	private static function ids(array $thread): array {
		return array_map(static fn (array $node): string => (string)$node['messageId'], $thread);
	}//end ids()

	/**
	 * A reply chain is one thread in date order, each child under its parent.
	 *
	 * @return void
	 */
	public function testAReplyChainIsOneThread(): void {
		$assembler = new MailThreadAssembler();
		$threads   = $assembler->assemble(
			messages: [
				self::mail(over: ['messageId' => 'c@x', 'inReplyTo' => 'b@x', 'references' => ['a@x', 'b@x'], 'sentAt' => '2026-05-03', 'fileId' => 3]),
				self::mail(over: ['messageId' => 'a@x', 'sentAt' => '2026-05-01', 'fileId' => 1]),
				self::mail(over: ['messageId' => 'b@x', 'inReplyTo' => 'a@x', 'references' => ['a@x'], 'sentAt' => '2026-05-02', 'fileId' => 2]),
			]
		);

		$this->assertCount(expectedCount: 1, haystack: $threads);
		$this->assertSame(expected: ['a@x', 'b@x', 'c@x'], actual: self::ids(thread: $threads[0]));
		$this->assertSame(expected: ['', 'a@x', 'b@x'], actual: array_map(static fn (array $n): string => (string)$n['parent'], $threads[0]));
		$this->assertSame(expected: [1, 2, 3], actual: array_map(static fn (array $n) => $n['fileId'], $threads[0]));
		$this->assertSame(expected: [false, false, false], actual: array_map(static fn (array $n): bool => $n['gap'], $threads[0]));
	}//end testAReplyChainIsOneThread()

	/**
	 * A reply whose subject was edited still threads on its parent header.
	 *
	 * @return void
	 */
	public function testAnEditedSubjectStaysInTheThread(): void {
		$assembler = new MailThreadAssembler();
		$threads   = $assembler->assemble(
			messages: [
				self::mail(over: ['messageId' => 'root@x', 'subject' => 'Vraag', 'sentAt' => '2026-05-01']),
				self::mail(over: ['messageId' => 'child@x', 'inReplyTo' => 'root@x', 'subject' => 'Heel iets anders', 'sentAt' => '2026-05-02']),
			]
		);

		$this->assertCount(expectedCount: 1, haystack: $threads);
		$this->assertSame(expected: ['root@x', 'child@x'], actual: self::ids(thread: $threads[0]));
		$this->assertSame(expected: 'root@x', actual: (string)$threads[0][1]['parent']);
	}//end testAnEditedSubjectStaysInTheThread()

	/**
	 * A parent that was never collected appears as a gap naming its id.
	 *
	 * @return void
	 */
	public function testAParentOutsideTheSetIsAGap(): void {
		$assembler = new MailThreadAssembler();
		$threads   = $assembler->assemble(
			messages: [
				self::mail(over: [
					'messageId' => 'reply@x',
					'inReplyTo' => 'lost@x',
					'references' => ['lost@x'],
					'sentAt' => '2026-05-05',
					'from' => 'a@x',
					'subject' => 'Re',
					'fileId' => 9,
				]),
			]
		);

		$this->assertCount(expectedCount: 1, haystack: $threads);
		$this->assertSame(expected: ['lost@x', 'reply@x'], actual: self::ids(thread: $threads[0]));

		$gap = $threads[0][0];
		$this->assertTrue(condition: $gap['gap']);
		$this->assertSame(expected: 'lost@x', actual: (string)$gap['messageId']);
		$this->assertNull(actual: $gap['fileId']);
		$this->assertSame(expected: '', actual: (string)$gap['from']);
		$this->assertSame(expected: '', actual: (string)$gap['subject']);
		$this->assertNull(actual: $gap['sentAt']);
		$this->assertFalse(condition: $threads[0][1]['gap']);
	}//end testAParentOutsideTheSetIsAGap()

	/**
	 * With no In-Reply-To, a reply threads on the last References entry, not the first.
	 *
	 * @return void
	 */
	public function testReferencesThreadOnTheirLastEntry(): void {
		$assembler = new MailThreadAssembler();
		$threads   = $assembler->assemble(
			messages: [
				self::mail(over: ['messageId' => 'a0@x', 'sentAt' => '2026-05-01']),
				self::mail(over: ['messageId' => 'a1@x', 'inReplyTo' => 'a0@x', 'references' => ['a0@x'], 'sentAt' => '2026-05-02']),
				self::mail(over: ['messageId' => 'reply@x', 'references' => ['a0@x', 'a1@x'], 'sentAt' => '2026-05-03']),
			]
		);

		$this->assertCount(expectedCount: 1, haystack: $threads);
		$this->assertSame(expected: ['a0@x', 'a1@x', 'reply@x'], actual: self::ids(thread: $threads[0]));
		$this->assertSame(expected: 'a1@x', actual: (string)$threads[0][2]['parent']);
	}//end testReferencesThreadOnTheirLastEntry()

	/**
	 * Two mails with the same subject but no shared headers stay in two threads.
	 *
	 * @return void
	 */
	public function testEqualSubjectsWithoutHeadersStayApart(): void {
		$assembler = new MailThreadAssembler();
		$threads   = $assembler->assemble(
			messages: [
				self::mail(over: ['messageId' => 'one@x', 'subject' => 'Re: uw verzoek', 'sentAt' => '2026-05-01']),
				self::mail(over: ['messageId' => 'two@x', 'subject' => 'Re: uw verzoek', 'sentAt' => '2026-05-02']),
			]
		);

		$this->assertCount(expectedCount: 2, haystack: $threads);
		$this->assertSame(
			expected: [['one@x'], ['two@x']],
			actual: [self::ids(thread: $threads[0]), self::ids(thread: $threads[1])]
		);
	}//end testEqualSubjectsWithoutHeadersStayApart()

	/**
	 * Two files carrying the same Message-ID are one node.
	 *
	 * @return void
	 */
	public function testADuplicateMessageIdIsOneNode(): void {
		$assembler = new MailThreadAssembler();
		$threads   = $assembler->assemble(
			messages: [
				self::mail(over: ['messageId' => 'dup@x', 'sentAt' => '2026-05-01', 'fileId' => 1]),
				self::mail(over: ['messageId' => 'dup@x', 'sentAt' => '2026-05-01', 'fileId' => 2]),
			]
		);

		$this->assertCount(expectedCount: 1, haystack: $threads);
		$this->assertCount(expectedCount: 1, haystack: $threads[0]);
		$this->assertSame(expected: 1, actual: $threads[0][0]['fileId']);
	}//end testADuplicateMessageIdIsOneNode()
}//end class
