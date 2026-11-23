<?php

/**
 * Characterisation test pinning every outcome of TalkSender's attributed
 * (post-as-user) path, including the Talk-app boundary it reaches through
 * the server container.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

use DateTime;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationChannelPolicy;
use OCA\OpenRegister\Notifications\Service\Notification\TalkMessage;
use OCA\OpenRegister\Notifications\Service\Notification\TalkSender;
use OCA\OpenRegister\Notifications\Service\Notification\TalkSendException;
use OCP\Http\Client\IClientService;
use OCP\IServerContainer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Talk's "room not found" exception, by class name only.
 */
class RoomNotFoundException extends \Exception {
}//end class

/**
 * Golden outcomes of TalkSender::postAsUser().
 *
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\TalkSender
 * @uses \OCA\OpenRegister\Notifications\Service\Notification\TalkMessage
 * @uses \OCA\OpenRegister\Notifications\Service\Notification\TalkUserPost
 */
class TalkSenderPostAsUserGoldenTest extends TestCase {

	/**
	 * Calls the fake Talk services received.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * Build a sender over a fake Talk app.
	 *
	 * @param \Throwable|null $roomFailure Thrown by the room lookup, or null.
	 * @param bool            $talkPresent Whether the container has Talk.
	 * @param bool|null       $talkEnabled The kill switch state, null for no policy.
	 *
	 * @return TalkSender
	 */
	private function sender(?\Throwable $roomFailure=null, bool $talkPresent=true, ?bool $talkEnabled=null): TalkSender {
		$test = $this;
		$manager = new class ($test, $roomFailure) {
			public function __construct(private TalkSenderPostAsUserGoldenTest $test, private ?\Throwable $failure) {
			}

			public function getRoomForUserByToken(string $token, string $uid): string {
				$this->test->record(['room', $token, $uid]);
				if ($this->failure !== null) {
					throw $this->failure;
				}

				return 'ROOM';
			}
		};
		$participants = new class ($test) {
			public function __construct(private TalkSenderPostAsUserGoldenTest $test) {
			}

			public function getParticipant(string $room, string $uid, bool $flag): string {
				$this->test->record(['participant', $room, $uid, $flag]);
				return 'PARTICIPANT';
			}
		};
		$chat = new class ($test) {
			public function __construct(private TalkSenderPostAsUserGoldenTest $test) {
			}

			public function sendMessage(string $room, string $participant, string $type, string $uid, string $message, DateTime $at): void {
				$this->test->record(['send', $room, $participant, $type, $uid, $message]);
			}
		};

		$container = $this->createMock(IServerContainer::class);
		$container->method('get')->willReturnCallback(function (string $id) use ($manager, $participants, $chat, $talkPresent): object {
			if ($talkPresent === false) {
				throw new \RuntimeException('no such service');
			}

			return match ($id) {
				'OCA\\Talk\\Manager' => $manager,
				'OCA\\Talk\\Service\\ParticipantService' => $participants,
				'OCA\\Talk\\Chat\\ChatManager' => $chat,
			};
		});

		$policy = null;
		if ($talkEnabled !== null) {
			$policy = $this->createMock(NotificationChannelPolicy::class);
			$policy->method('isChannelEnabled')->willReturnCallback(fn (string $channel): bool => $channel !== 'talk' || $talkEnabled);
		}

		return new TalkSender(
			httpClient: $this->createMock(IClientService::class),
			logger: $this->createMock(LoggerInterface::class),
			config: null,
			channelPolicy: $policy,
			serverContainer: $container
		);
	}//end sender()

	/**
	 * Record a fake Talk call.
	 *
	 * @param array<int,mixed> $call The call.
	 *
	 * @return void
	 */
	public function record(array $call): void {
		$this->calls[] = $call;
	}//end record()

	/**
	 * Assert the attributed post throws with exactly this message.
	 *
	 * @param TalkSender $sender  The sender.
	 * @param string     $token   The conversation token.
	 * @param string     $message The expected failure text.
	 *
	 * @return void
	 */
	private function assertRefused(TalkSender $sender, string $token, string $message): void {
		try {
			$sender->postAsUser(new TalkMessage($token, 'hi'), 'alice');
			$this->fail('expected TalkSendException');
		} catch (TalkSendException $e) {
			$this->assertSame($message, $e->getMessage());
		}
	}//end assertRefused()

	/**
	 * A participant's post goes through room, participant and chat manager.
	 *
	 * @return void
	 */
	public function testParticipantPostIsDispatchedAsTheUser(): void {
		$this->assertSame('dispatched', $this->sender(null, true, true)->postAsUser(new TalkMessage('tok', 'hi'), 'alice'));
		$this->assertSame(
			[
				['room', 'tok', 'alice'],
				['participant', 'ROOM', 'alice', false],
				['send', 'ROOM', 'PARTICIPANT', 'users', 'alice', 'hi'],
			],
			$this->calls
		);
	}//end testParticipantPostIsDispatchedAsTheUser()

	/**
	 * An empty token is refused before anything else, even the kill switch.
	 *
	 * @return void
	 */
	public function testEmptyTokenIsRefused(): void {
		$this->assertRefused($this->sender(null, true, false), '', 'No Talk conversation token was given.');
		$this->assertSame([], $this->calls);
	}//end testEmptyTokenIsRefused()

	/**
	 * The kill switch returns its outcome and touches no Talk service.
	 *
	 * @return void
	 */
	public function testKillSwitchSkipsTalk(): void {
		$this->assertSame('kill-switch', $this->sender(null, true, false)->postAsUser(new TalkMessage('tok', 'hi'), 'alice'));
		$this->assertSame([], $this->calls);
	}//end testKillSwitchSkipsTalk()

	/**
	 * No server container, or no Talk in it, is a named failure.
	 *
	 * @return void
	 */
	public function testMissingTalkIsANamedFailure(): void {
		$bare = new TalkSender(httpClient: $this->createMock(IClientService::class), logger: $this->createMock(LoggerInterface::class));
		$this->assertRefused($bare, 'tok', 'Talk posting is unavailable: no server container to reach the Talk app.');
		$this->assertRefused($this->sender(null, false), 'tok', 'Talk (spreed) is not installed, so no Talk message can be sent.');
	}//end testMissingTalkIsANamedFailure()

	/**
	 * A non-participant is refused and never auto-joined; any other Talk
	 * error is reported with its message.
	 *
	 * @return void
	 */
	public function testTalkErrorsAreNamed(): void {
		$this->assertRefused(
			$this->sender(new RoomNotFoundException('x')),
			'tok',
			'User "alice" is not a participant of Talk conversation "tok"; the message was not sent and the user was not auto-joined.'
		);
		$this->assertRefused($this->sender(new \RuntimeException('boom')), 'tok', 'Talk post to "tok" as "alice" failed: boom');
		$this->assertRefused($this->sender(new TalkSendException('own')), 'tok', 'own');
	}//end testTalkErrorsAreNamed()
}//end class
