<?php

/**
 * Unit tests for TalkUserPost::failure().
 *
 * Pins the named failure for each way an attributed Talk post can fail.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Service\Notification;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses -- local stand-ins for Talk's exceptions.

use OCA\OpenRegister\Notifications\Service\Notification\TalkMessage;
use OCA\OpenRegister\Notifications\Service\Notification\TalkSendException;
use OCA\OpenRegister\Notifications\Service\Notification\TalkUserPost;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Stand-in for Talk's participant-not-found exception, matched by class name.
 */
final class ParticipantNotFoundException extends RuntimeException {
}

/**
 * Stand-in for Talk's room-not-found exception, matched by class name.
 */
final class RoomNotFoundException extends RuntimeException {
}

/**
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\TalkUserPost
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\TalkMessage
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\TalkSendException
 */
final class TalkUserPostTest extends TestCase {

	private function post(): TalkUserPost {
		return new TalkUserPost(talkMessage: new TalkMessage(token: 'abc123', message: 'Hello'), actorUid: 'alice');
	}

	public function testAMissingParticipantSaysTheUserWasNotAutoJoined(): void {
		$cause = new ParticipantNotFoundException('no participant');

		$failure = $this->post()->failure(error: $cause);

		$this->assertInstanceOf(TalkSendException::class, $failure);
		$this->assertSame(
			'User "alice" is not a participant of Talk conversation "abc123"; the message was not sent and the user was not auto-joined.',
			$failure->getMessage()
		);
	}

	public function testAMissingRoomIsReportedTheSameWayAsAMissingParticipant(): void {
		$failure = $this->post()->failure(error: new RoomNotFoundException('no room'));

		$this->assertSame(
			'User "alice" is not a participant of Talk conversation "abc123"; the message was not sent and the user was not auto-joined.',
			$failure->getMessage()
		);
	}

	public function testAnyOtherErrorNamesTheConversationActorAndCause(): void {
		$failure = $this->post()->failure(error: new RuntimeException('rate limited'));

		$this->assertInstanceOf(TalkSendException::class, $failure);
		$this->assertSame('Talk post to "abc123" as "alice" failed: rate limited', $failure->getMessage());
	}
}
