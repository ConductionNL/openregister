<?php

/**
 * Unit test for ExampleSetNotes: which notes it refuses, how it writes a
 * comment through the comments manager, and how it proves and removes one.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
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

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use DateTime;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetNotes;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRow;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\Comments\NotFoundException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetNotes
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetUserObjectRows
 * @uses   \OCA\OpenRegister\Service\ExampleSet\ExampleSetRow
 */
class ExampleSetNotesBehaviourTest extends TestCase {

	/**
	 * The comments manager.
	 *
	 * @var ICommentsManager&MockObject
	 */
	private ICommentsManager&MockObject $comments;

	/**
	 * The class under test.
	 *
	 * @var ExampleSetNotes
	 */
	private ExampleSetNotes $subject;

	/**
	 * A container that hands out the comments manager.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->comments = $this->createMock(ICommentsManager::class);
		$container      = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(ICommentsManager::class)->willReturn($this->comments);
		$this->subject = new ExampleSetNotes(container: $container);
	}//end setUp()

	/**
	 * The kind names its record part and noun.
	 *
	 * @return void
	 */
	public function testTheKindNamesItsPartAndNoun(): void {
		$this->assertSame('notes', $this->subject->part());
		$this->assertSame('note', $this->subject->noun());
	}//end testTheKindNamesItsPartAndNoun()

	/**
	 * Each faulty note is refused with its own reason, and a complete one is not.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$ok    = ['user' => 'anna', 'objectUuid' => 'o-1', 'message' => 'Gebeld', 'createdAt' => '2026-01-02 10:00'];
		$users = ['anna'];
		$this->assertSame('', $this->subject->refusal(data: $ok, users: $users));
		$this->assertSame('', $this->subject->refusal(data: array_diff_key($ok, ['createdAt' => 1]), users: $users));
		$this->assertSame(
			'field "colour", "size" is not a note field a set may write',
			$this->subject->refusal(data: $ok + ['colour' => 'x', 'size' => 1], users: $users)
		);
		$this->assertSame('user "bob" is not an account the set creates or adopts', $this->subject->refusal(data: ['user' => 'bob'] + $ok, users: $users));
		$this->assertSame('user "" is not an account the set creates or adopts', $this->subject->refusal(data: ['message' => 'x'], users: $users));
		$this->assertSame('a note needs the object it is on (objectUuid)', $this->subject->refusal(data: ['objectUuid' => ' '] + $ok, users: $users));
		$this->assertSame('a note needs the object it is on (objectUuid)', $this->subject->refusal(data: ['objectUuid' => 5] + $ok, users: $users));
		$this->assertSame('a note needs a message', $this->subject->refusal(data: ['message' => ''] + $ok, users: $users));
		$this->assertSame('createdAt is a date and time', $this->subject->refusal(data: ['createdAt' => 'gisteren-ish'] + $ok, users: $users));
		$this->assertSame('createdAt is a date and time', $this->subject->refusal(data: ['createdAt' => ''] + $ok, users: $users));
	}//end testRefusals()

	/**
	 * A write creates a user comment on the openregister object with its message, verb and creation moment.
	 *
	 * @return void
	 */
	public function testAWriteSavesACommentWithItsMoment(): void {
		$comment = $this->createMock(IComment::class);
		$this->comments->expects($this->once())->method('create')->with('users', 'anna', 'openregister', 'o-1')->willReturn($comment);
		$comment->expects($this->once())->method('setMessage')->with('Gebeld');
		$comment->expects($this->once())->method('setVerb')->with('comment');
		$comment->expects($this->once())->method('setCreationDateTime')->with(
			$this->callback(fn (DateTime $moment): bool => $moment->format('Y-m-d H:i') === '2026-01-02 10:00')
		);
		$comment->method('getId')->willReturn('42');
		$this->comments->expects($this->once())->method('save')->with($comment);

		$result = $this->subject->write(row: new ExampleSetRow(app: 'alpha', key: 'n', data: ['user' => 'anna', 'objectUuid' => 'o-1', 'message' => 'Gebeld', 'createdAt' => '2026-01-02 10:00']));

		$this->assertSame(['error' => '', 'entry' => ['id' => '42']], $result);
	}//end testAWriteSavesACommentWithItsMoment()

	/**
	 * A note without a moment keeps the comment's own creation time.
	 *
	 * @return void
	 */
	public function testAWriteWithoutAMomentLeavesTheCreationTime(): void {
		$comment = $this->createMock(IComment::class);
		$this->comments->method('create')->willReturn($comment);
		$comment->expects($this->never())->method('setCreationDateTime');
		$comment->method('getId')->willReturn('7');

		$result = $this->subject->write(row: new ExampleSetRow(app: 'alpha', key: 'n', data: ['user' => 'anna', 'objectUuid' => 'o-1', 'message' => 'Hoi']));

		$this->assertSame(['error' => '', 'entry' => ['id' => '7']], $result);
	}//end testAWriteWithoutAMomentLeavesTheCreationTime()

	/**
	 * A save that fails returns its message and no entry.
	 *
	 * @return void
	 */
	public function testAFailedSaveReturnsItsMessage(): void {
		$this->comments->method('create')->willReturn($this->createMock(IComment::class));
		$this->comments->method('save')->willThrowException(new RuntimeException('too long'));

		$result = $this->subject->write(row: new ExampleSetRow(app: 'alpha', key: 'n', data: ['user' => 'anna', 'objectUuid' => 'o-1', 'message' => 'Hoi']));

		$this->assertSame(['error' => 'too long', 'entry' => null], $result);
	}//end testAFailedSaveReturnsItsMessage()

	/**
	 * A note is lost when the manager cannot get it, and held when it can.
	 *
	 * @return void
	 */
	public function testANoteIsLostWhenTheManagerCannotGetIt(): void {
		$this->comments->expects($this->exactly(2))->method('get')->with('42')
			->willReturnOnConsecutiveCalls($this->createMock(IComment::class), $this->throwException(new NotFoundException()));

		$this->assertFalse($this->subject->lost(entry: ['id' => '42']));
		$this->assertTrue($this->subject->lost(entry: ['id' => '42']));
	}//end testANoteIsLostWhenTheManagerCannotGetIt()

	/**
	 * A revert deletes the comment; a comment already gone counts as removed, any other failure as failed.
	 *
	 * @return void
	 */
	public function testARevertDeletesTheComment(): void {
		$this->comments->expects($this->exactly(3))->method('delete')->with('42')
			->willReturnOnConsecutiveCalls(
				true,
				$this->throwException(new NotFoundException()),
				$this->throwException(new RuntimeException('db'))
			);

		$this->assertSame('removed', $this->subject->revert(entry: ['id' => '42']));
		$this->assertSame('removed', $this->subject->revert(entry: ['id' => '42']));
		$this->assertSame('failed', $this->subject->revert(entry: ['id' => '42']));
	}//end testARevertDeletesTheComment()
}//end class
