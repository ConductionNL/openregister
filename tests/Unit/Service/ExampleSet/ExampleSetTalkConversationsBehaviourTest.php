<?php

/**
 * ExampleSetTalkConversations over a mocked Talk store, link mapper and
 * object finder: what a conversation row is refused for, how it is written
 * (room, held call, link), and how it is proved lost and reverted.
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
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\TalkLink;
use OCA\OpenRegister\Db\TalkLinkMapperInterface;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRow;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetTalkConversations;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetTalkRoom;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetTalkStore;
use OCA\OpenRegister\Service\ExampleSet\HeldTalkCall;
use OCA\OpenRegister\Service\Party\PartyService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetTalkConversations
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\TalkLink
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFieldRules
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRow
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRowShape
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetTalkRoom
 * @uses \OCA\OpenRegister\Service\ExampleSet\HeldTalkCall
 */
class ExampleSetTalkConversationsBehaviourTest extends TestCase {

	private ExampleSetTalkStore&MockObject $talk;

	private TalkLinkMapperInterface&MockObject $links;

	private PartyService&MockObject $parties;

	protected function setUp(): void {
		parent::setUp();
		$this->talk    = $this->createMock(ExampleSetTalkStore::class);
		$this->links   = $this->createMock(TalkLinkMapperInterface::class);
		$this->parties = $this->createMock(PartyService::class);
	}

	private function subject(): ExampleSetTalkConversations {
		$services  = [
			ExampleSetTalkStore::class     => $this->talk,
			TalkLinkMapperInterface::class => $this->links,
			PartyService::class            => $this->parties,
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => $services[$id]);

		return new ExampleSetTalkConversations($container);
	}

	private static function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setRegister('3');
		$object->setSchema('5');
		return $object;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function row(array $extra = []): array {
		return [...['object' => 'obj-1', 'name' => 'Overleg', 'owner' => 'anna', 'participants' => ['bob', 'anna', 'bob']], ...$extra];
	}

	public function testItLivesInTheTalkConversationsPart(): void {
		$this->assertSame('talkConversations', $this->subject()->part());
		$this->assertSame('talk conversation', $this->subject()->noun());
	}

	/**
	 * @dataProvider refusals
	 */
	public function testARowIsRefusedForItsFirstFault(array $data, string $refusal): void {
		$this->assertSame($refusal, $this->subject()->refusal($data, ['anna', 'bob', 'cleo']));
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function refusals(): array {
		$ok = ['object' => 'obj-1', 'name' => 'Overleg', 'owner' => 'anna'];
		return [
			'complete'             => [$ok, ''],
			'with people and call' => [$ok + ['participants' => ['bob', 'cleo'], 'call' => ['start' => '2026-01-01T10:00:00Z', 'end' => '2026-01-01T11:00:00Z']], ''],
			'call null'            => [$ok + ['call' => null], ''],
			'call of no length'    => [$ok + ['call' => ['start' => '2026-01-01T10:00:00Z', 'end' => '2026-01-01T10:00:00Z']], ''],
			'unknown field'        => [$ok + ['topic' => 'x'], 'field "topic" is not a talk conversation field a set may write'],
			'no object'            => [['name' => 'Overleg', 'owner' => 'anna'], 'a talk conversation needs the object it is on (object)'],
			'blank name'           => [['object' => 'obj-1', 'name' => ' ', 'owner' => 'anna'], 'a talk conversation needs a name (name)'],
			'no owner'             => [['object' => 'obj-1', 'name' => 'Overleg'], 'a talk conversation needs an owner (owner)'],
			'stranger owner'       => [['object' => 'obj-1', 'name' => 'Overleg', 'owner' => 'zed'], '"zed" is not an account the set creates or adopts'],
			'stranger participant' => [$ok + ['participants' => ['bob', 'yan']], '"yan" is not an account the set creates or adopts'],
			'scalar participant'   => [$ok + ['participants' => 'xi'], '"xi" is not an account the set creates or adopts'],
			'call as text'         => [$ok + ['call' => 'today'], 'a held call needs a start and an end (call.start, call.end)'],
			'call without end'     => [$ok + ['call' => ['start' => '2026-01-01T10:00:00Z']], 'a held call needs a start and an end (call.start, call.end)'],
			'call not a date'      => [$ok + ['call' => ['start' => 'not a date', 'end' => '2026-01-01T10:00:00Z']], 'call.start or call.end is not a date and time'],
			'call backwards'       => [$ok + ['call' => ['start' => '2026-01-01T11:00:00Z', 'end' => '2026-01-01T10:00:00Z']], 'a held call ends after it starts'],
		];
	}

	public function testWithoutTalkNothingIsLookedUpOrWritten(): void {
		$this->talk->method('available')->willReturn(false);
		$this->parties->expects($this->never())->method('findUnscoped');
		$this->talk->expects($this->never())->method('openRoom');

		$this->assertSame(['error' => 'Talk is not installed', 'entry' => null], $this->subject()->write(new ExampleSetRow('zaak', 'k', self::row())));
	}

	public function testAMissingObjectIsAnErrorAndNoRoomIsOpened(): void {
		$this->talk->method('available')->willReturn(true);
		$this->parties->expects($this->once())->method('findUnscoped')->with('obj-9')->willReturn(null);
		$this->talk->expects($this->never())->method('openRoom');

		$this->assertSame(
			['error' => 'no object "obj-9" to put the conversation on', 'entry' => null],
			$this->subject()->write(new ExampleSetRow('zaak', 'k', self::row(['object' => 'obj-9'])))
		);
	}

	public function testACreatedRoomHoldsTheCallAndIsLinkedToTheObject(): void {
		$this->talk->method('available')->willReturn(true);
		$this->parties->method('findUnscoped')->with('obj-1')->willReturn(self::object());
		$this->talk->expects($this->once())->method('openRoom')
			->with($this->callback(static fn (ExampleSetTalkRoom $room): bool => $room->owner === 'anna' && $room->name === 'Overleg' && $room->participants === ['anna', 'bob']))
			->willReturn(['token' => 'tok-1', 'id' => 41, 'created' => true]);
		$held = null;
		$this->talk->expects($this->once())->method('heldCall')->willReturnCallback(static function (HeldTalkCall $call) use (&$held): void {
			$held = $call;
		});
		$this->links->expects($this->once())->method('findByObjectAndRoom')->with('obj-1', 'tok-1')->willReturn(null);
		$inserted = null;
		$this->links->expects($this->once())->method('insert')->willReturnCallback(static function (TalkLink $link) use (&$inserted): TalkLink {
			$inserted = $link;
			$link->setId(12);
			return $link;
		});
		$call = ['start' => '2026-01-01T10:00:00+01:00', 'end' => '2026-01-01T11:30:00+01:00'];

		$answer = $this->subject()->write(new ExampleSetRow('zaak', 'k', self::row(['call' => $call])));

		$this->assertSame(['error' => '', 'entry' => ['token' => 'tok-1', 'room' => 41, 'createdRoom' => true, 'object' => 'obj-1', 'link' => 12]], $answer);
		$this->assertSame(['tok-1', 'anna', $call, ['anna', 'bob']], [$held->token, $held->owner, $held->call, $held->joined]);
		$this->assertSame('obj-1', $inserted->getObjectUuid());
		$this->assertSame(3, $inserted->getRegisterId());
		$this->assertSame(5, $inserted->getSchemaId());
		$this->assertSame('zaak', $inserted->getLinkedBy());
		$this->assertInstanceOf(DateTime::class, $inserted->getLinkedAt());
		$this->assertSame('tok-1', $inserted->getRoomToken());
		$this->assertSame(41, $inserted->getRoomId());
		$this->assertSame('Overleg', $inserted->getRoomName());
		$this->assertSame(2, $inserted->getRoomType());
		$this->assertSame(2, $inserted->getParticipantCount());
		$this->assertSame('2026-01-01T11:30:00+01:00', $inserted->getLastActivity()->format('c'));
	}

	public function testAKeptRoomHoldsNoCallAgain(): void {
		$this->talk->method('available')->willReturn(true);
		$this->parties->method('findUnscoped')->willReturn(self::object());
		$this->talk->method('openRoom')->willReturn(['token' => 'tok-1', 'id' => 41, 'created' => false]);
		$this->talk->expects($this->never())->method('heldCall');
		$this->links->method('findByObjectAndRoom')->willReturn(null);
		$this->links->method('insert')->willReturnCallback(static function (TalkLink $link): TalkLink {
			$link->setId(13);
			return $link;
		});

		$answer = $this->subject()->write(new ExampleSetRow('zaak', 'k', self::row(['call' => ['start' => '2026-01-01T10:00:00Z', 'end' => '2026-01-01T11:00:00Z']])));

		$this->assertSame(['error' => '', 'entry' => ['token' => 'tok-1', 'room' => 41, 'createdRoom' => false, 'object' => 'obj-1', 'link' => 13]], $answer);
	}

	public function testACreatedRoomWithoutACallHoldsNoneAndIsActiveNow(): void {
		$this->talk->method('available')->willReturn(true);
		$this->parties->method('findUnscoped')->willReturn(self::object());
		$this->talk->method('openRoom')->willReturn(['token' => 'tok-2', 'id' => 42, 'created' => true]);
		$this->talk->expects($this->never())->method('heldCall');
		$this->links->method('findByObjectAndRoom')->willReturn(null);
		$inserted = null;
		$this->links->method('insert')->willReturnCallback(static function (TalkLink $link) use (&$inserted): TalkLink {
			$inserted = $link;
			$link->setId(14);
			return $link;
		});
		$before = time();

		$answer = $this->subject()->write(new ExampleSetRow('zaak', 'k', ['object' => 'obj-1', 'name' => 'Solo', 'owner' => 'cleo']));

		$this->assertSame(14, $answer['entry']['link']);
		$this->assertSame(1, $inserted->getParticipantCount());
		$this->assertGreaterThanOrEqual($before, $inserted->getLastActivity()->getTimestamp());
	}

	public function testAnExistingLinkIsReusedAndNotInsertedAgain(): void {
		$this->talk->method('available')->willReturn(true);
		$this->parties->method('findUnscoped')->willReturn(self::object());
		$this->talk->method('openRoom')->willReturn(['token' => 'tok-1', 'id' => 41, 'created' => false]);
		$existing = new TalkLink();
		$existing->setId(7);
		$this->links->expects($this->once())->method('findByObjectAndRoom')->with('obj-1', 'tok-1')->willReturn($existing);
		$this->links->expects($this->never())->method('insert');

		$answer = $this->subject()->write(new ExampleSetRow('zaak', 'k', self::row()));

		$this->assertSame(7, $answer['entry']['link']);
	}

	public function testAFailingRoomIsTheWriteError(): void {
		$this->talk->method('available')->willReturn(true);
		$this->parties->method('findUnscoped')->willReturn(self::object());
		$this->talk->method('openRoom')->willThrowException(new RuntimeException('Talk refused the room'));
		$this->links->expects($this->never())->method('insert');

		$this->assertSame(['error' => 'Talk refused the room', 'entry' => null], $this->subject()->write(new ExampleSetRow('zaak', 'k', self::row())));
	}

	public function testAConversationIsLostOnlyWhenItsRoomIsGoneOrTalkFails(): void {
		$this->talk->method('roomExists')->willReturnCallback(static fn (string $token): bool => match ($token) {
			'there' => true,
			'gone'  => false,
			'down'  => throw new RuntimeException('Talk is down'),
		});
		$subject = $this->subject();

		$this->assertFalse($subject->lost(['token' => 'there']));
		$this->assertTrue($subject->lost(['token' => 'gone']));
		$this->assertTrue($subject->lost(['token' => 'down']));
	}

	public function testRevertDeletesTheLinkAndTheRoomTheSetCreated(): void {
		$this->links->expects($this->once())->method('deleteByObjectAndRoom')->with('obj-1', 'tok-1')->willReturn(1);
		$this->talk->expects($this->once())->method('roomExists')->with('tok-1')->willReturn(true);
		$this->talk->expects($this->once())->method('deleteRoom')->with('tok-1');

		$this->assertSame('removed', $this->subject()->revert(['token' => 'tok-1', 'object' => 'obj-1', 'createdRoom' => true]));
	}

	public function testRevertLeavesAnAlreadyGoneRoom(): void {
		$this->links->expects($this->once())->method('deleteByObjectAndRoom');
		$this->talk->method('roomExists')->willReturn(false);
		$this->talk->expects($this->never())->method('deleteRoom');

		$this->assertSame('removed', $this->subject()->revert(['token' => 'tok-1', 'object' => 'obj-1', 'createdRoom' => true]));
	}

	/**
	 * @dataProvider keptEntries
	 */
	public function testRevertKeepsARoomTheSetDidNotCreate(array $entry): void {
		$this->links->expects($this->once())->method('deleteByObjectAndRoom')->with('obj-1', 'tok-1');
		$this->talk->expects($this->never())->method('roomExists');
		$this->talk->expects($this->never())->method('deleteRoom');

		$this->assertSame('removed', $this->subject()->revert($entry));
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function keptEntries(): array {
		return [
			'kept'          => [['token' => 'tok-1', 'object' => 'obj-1', 'createdRoom' => false]],
			'no flag'       => [['token' => 'tok-1', 'object' => 'obj-1']],
			'flag not bool' => [['token' => 'tok-1', 'object' => 'obj-1', 'createdRoom' => 'yes']],
		];
	}

	public function testRevertFailsWhenTheLinkCannotBeDeleted(): void {
		$this->links->method('deleteByObjectAndRoom')->willThrowException(new RuntimeException('database is gone'));
		$this->talk->expects($this->never())->method('deleteRoom');

		$this->assertSame('failed', $this->subject()->revert(['token' => 'tok-1', 'object' => 'obj-1', 'createdRoom' => true]));
	}

	public function testRevertFailsWhenTheRoomCannotBeDeleted(): void {
		$this->talk->method('roomExists')->willReturn(true);
		$this->talk->method('deleteRoom')->willThrowException(new RuntimeException('Talk is down'));

		$this->assertSame('failed', $this->subject()->revert(['token' => 'tok-1', 'object' => 'obj-1', 'createdRoom' => true]));
	}
}
