<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service\TalkLink;

use OCA\OpenRegister\Service\TalkLink\RoomPickerQuery;
use OCA\OpenRegister\Service\TalkLink\TalkViewer;
use OCA\OpenRegister\Service\TalkLink\TalkRoomFields;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A room whose getters are magic and may throw.
 */
class TalkRoomFieldsTestRoom {

	/**
	 * Constructor.
	 *
	 * @param array $answers Getter => answer; a Throwable is thrown.
	 */
	public function __construct(private array $answers) {
	}

	/**
	 * Answer a getter.
	 *
	 * @param string $name Getter.
	 * @param array  $args Arguments.
	 *
	 * @return mixed
	 */
	public function __call(string $name, array $args): mixed {
		$answer = ($this->answers[$name] ?? new RuntimeException('missing ' . $name));
		if ($answer instanceof \Throwable) {
			throw $answer;
		}

		return $answer;
	}
}

/**
 * @covers \OCA\OpenRegister\Service\TalkLink\TalkRoomFields
 * @uses   \OCA\OpenRegister\Service\TalkLink\RoomPickerQuery
 * @uses   \OCA\OpenRegister\Service\TalkLink\RoomPickerRun
 * @uses   \OCA\OpenRegister\Service\TalkLink\TalkViewer
 */
class TalkRoomFieldsTest extends TestCase {

	/**
	 * @return TalkRoomFields
	 */
	private function fields(): TalkRoomFields {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		return new TalkRoomFields($l10n);
	}

	public function testCacheFieldsDegradeGetterFailuresToNull(): void {
		$room = new TalkRoomFieldsTestRoom(['getId' => new RuntimeException('x'), 'getName' => 'Zaak [or:abc]', 'getType' => '3']);

		$this->assertSame(
			['roomId' => null, 'roomName' => 'Zaak', 'roomType' => 3, 'subtitle' => 'Public', 'participantCount' => null, 'lastMessage' => null, 'lastActivity' => null],
			$this->fields()->cacheFields($room, new TalkViewer('alice', null))
		);
	}

	public function testPickerRowsSkipTokenlessUnmatchedAndSystemRooms(): void {
		$rooms = [
			new TalkRoomFieldsTestRoom(['getToken' => '']),
			new TalkRoomFieldsTestRoom(['getToken' => 'a', 'getName' => 'Bouw', 'getType' => '2']),
			new TalkRoomFieldsTestRoom(['getToken' => 'b', 'getName' => 'Zaak bouw', 'getType' => '6']),
			new TalkRoomFieldsTestRoom(['getToken' => 'c', 'getName' => 'Zaak', 'getType' => '2']),
		];

		$this->assertSame(
			[['token' => 'a', 'name' => 'Bouw', 'type' => 2, 'participantCount' => null]],
			$this->fields()->pickerRows($rooms, new RoomPickerQuery(new TalkViewer('alice', null), ' BOUW '))
		);
	}

	public function testTokenIsEmptyWhenTheGetterThrows(): void {
		$this->assertSame('', $this->fields()->token(new TalkRoomFieldsTestRoom([])));
	}
}
