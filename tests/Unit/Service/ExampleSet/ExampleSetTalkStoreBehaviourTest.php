<?php

/**
 * ExampleSetTalkStore over fake Talk services: whether Talk is available,
 * finding or creating the owner's group room, writing a held call as two
 * system messages, and telling whether a room exists and deleting it.
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
use OCA\OpenRegister\Service\ExampleSet\ExampleSetAppReach;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetTalkRoom;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetTalkStore;
use OCA\OpenRegister\Service\ExampleSet\HeldTalkCall;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetTalkStore
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAppReach
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetTalkRoom
 * @uses \OCA\OpenRegister\Service\ExampleSet\HeldTalkCall
 */
class ExampleSetTalkStoreBehaviourTest extends TestCase {

	/**
	 * Every call the fake Talk services received, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * The rooms the fake manager knows, by token.
	 *
	 * @var array<string, object>
	 */
	private array $byToken = [];

	/**
	 * A fake Talk room.
	 */
	private static function room(string $token, int $id, string $name, int $type): object {
		return new class($token, $id, $name, $type) {
			public function __construct(public string $token, private int $id, private string $name, private int $type) {
			}

			public function getToken(): string {
				return $this->token;
			}

			public function getId(): int {
				return $this->id;
			}

			public function getName(): string {
				return $this->name;
			}

			public function getType(): int {
				return $this->type;
			}
		};
	}

	/**
	 * A store over fake Talk services; alice, bob and carol exist, ghost does not.
	 *
	 * @param array<int, object> $owned     The owner's rooms.
	 * @param bool               $installed Whether the app manager says spreed is installed.
	 */
	private function store(array $owned = [], bool $installed = true, ?IAppManager $apps = null): ExampleSetTalkStore {
		$calls   = &$this->calls;
		$byToken = &$this->byToken;
		$manager = new class($owned, $byToken, $calls) {
			public function __construct(private array $owned, private array &$byToken, private array &$calls) {
			}

			public function getRoomsForUser(string $uid): array {
				$this->calls[] = ['roomsFor', $uid];
				return $this->owned;
			}

			public function getRoomByToken(string $token): object {
				$this->calls[] = ['byToken', $token];
				if (isset($this->byToken[$token]) === false) {
					throw new RuntimeException('Room not found');
				}

				return $this->byToken[$token];
			}
		};
		$rooms = new class($calls) {
			public function __construct(private array &$calls) {
			}

			public function createConversation(int $type, string $name, mixed $owner): object {
				$this->calls[] = ['create', $type, $name, $owner?->getUID()];
				return new class {
					public function getToken(): string {
						return 'made';
					}

					public function getId(): int {
						return 77;
					}
				};
			}

			public function deleteRoom(object $room): void {
				$this->calls[] = ['delete', $room->getToken()];
			}
		};
		$participants = new class($calls) {
			public function __construct(private array &$calls) {
			}

			public function addUsers(object $room, array $people): void {
				$this->calls[] = ['add', $room->getToken(), $people];
			}
		};
		$chat = new class($calls) {
			public function __construct(private array &$calls) {
			}

			public function addSystemMessage(mixed $room, mixed $actorTypeOrNull, string $actorType, string $actorId, string $message, DateTime $at, bool $sendNotifications): void {
				$this->calls[] = ['system', $room->getToken(), $actorTypeOrNull, $actorType, $actorId, json_decode($message, true), $at->format('c'), $sendNotifications];
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => match ($id) {
				'OCA\Talk\Manager' => $manager,
				'OCA\Talk\Service\RoomService' => $rooms,
				'OCA\Talk\Service\ParticipantService' => $participants,
				'OCA\Talk\Chat\ChatManager' => $chat,
			}
		);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(
			function (string $uid): ?IUser {
				if ($uid === 'ghost') {
					return null;
				}

				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($uid);
				$user->method('getDisplayName')->willReturn(ucfirst($uid).' Doe');
				return $user;
			}
		);
		if ($apps === null) {
			$apps = $this->createMock(IAppManager::class);
			$apps->method('isInstalled')->willReturnCallback(static fn (string $app): bool => $app === 'spreed' && $installed);
		}

		return new ExampleSetTalkStore(new ExampleSetAppReach(container: $container, apps: $apps, users: $users));
	}

	public function testTalkIsUnavailableWhenSpreedIsNotInstalled(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->expects($this->once())->method('isInstalled')->with('spreed')->willReturn(false);

		$this->assertFalse($this->store(apps: $apps)->available());
	}

	public function testTalkIsUnavailableWhenItsServicesDoNotLoad(): void {
		$this->assertFalse(class_exists('OCA\Talk\Service\RoomService'), 'this test needs an instance without Talk classes');

		$this->assertFalse($this->store(installed: true)->available());
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testTalkIsAvailableWhenInstalledAndItsServicesLoad(): void {
		eval('namespace OCA\Talk\Service; class RoomService {}');
		eval('namespace OCA\Talk\Chat; class ChatManager {}');

		$this->assertTrue($this->store(installed: true)->available());
		$this->assertFalse($this->store(installed: false)->available());
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testTalkIsUnavailableWithoutItsChatManager(): void {
		eval('namespace OCA\Talk\Service; class RoomService {}');

		$this->assertFalse($this->store(installed: true)->available());
	}

	public function testTheOwnersGroupRoomOfThatNameIsReusedAndNothingIsCreated(): void {
		$owned = [self::room('direct', 1, 'Zaak 7', 1), self::room('other', 2, 'Zaak 8', 2), self::room('group', 3, 'Zaak 7', 2)];

		$found = $this->store(owned: $owned)->openRoom(new ExampleSetTalkRoom(owner: 'alice', name: 'Zaak 7', participants: ['bob']));

		$this->assertSame(['token' => 'group', 'id' => 3, 'created' => false], $found);
		$this->assertSame([['roomsFor', 'alice']], $this->calls);
	}

	public function testAMissingRoomIsCreatedForTheOwnerWithTheOtherExistingAccounts(): void {
		$owned = [self::room('direct', 1, 'Zaak 7', 1)];

		$made = $this->store(owned: $owned)->openRoom(new ExampleSetTalkRoom(owner: 'alice', name: 'Zaak 7', participants: ['alice', 'bob', 'ghost', 'carol']));

		$this->assertSame(['token' => 'made', 'id' => 77, 'created' => true], $made);
		$this->assertSame(
			[
				['roomsFor', 'alice'],
				['create', 2, 'Zaak 7', 'alice'],
				[
					'add',
					'made',
					[
						['actorType' => 'users', 'actorId' => 'bob', 'displayName' => 'Bob Doe'],
						['actorType' => 'users', 'actorId' => 'carol', 'displayName' => 'Carol Doe'],
					],
				],
			],
			$this->calls
		);
	}

	public function testARoomWithOnlyTheOwnerGetsNoOtherParticipants(): void {
		$this->store()->openRoom(new ExampleSetTalkRoom(owner: 'alice', name: 'Solo', participants: ['alice']));

		$this->assertSame(['add', 'made', []], $this->calls[2]);
	}

	public function testAHeldCallIsWrittenAsStartedAndEndedSystemMessages(): void {
		$this->byToken = ['tok' => self::room('tok', 5, 'Zaak', 2)];

		$this->store()->heldCall(
			new HeldTalkCall(token: 'tok', owner: 'alice', call: ['start' => '2026-03-01T09:00:00+00:00', 'end' => '2026-03-01T09:45:30+00:00'], joined: ['x' => 'alice', 'y' => 'bob'])
		);

		$this->assertSame(
			[
				['byToken', 'tok'],
				['system', 'tok', null, 'users', 'alice', ['message' => 'call_started', 'parameters' => []], '2026-03-01T09:00:00+00:00', false],
				[
					'system',
					'tok',
					null,
					'users',
					'alice',
					['message' => 'call_ended', 'parameters' => ['users' => ['alice', 'bob'], 'cloudIds' => [], 'guests' => 0, 'duration' => 2730]],
					'2026-03-01T09:45:30+00:00',
					false,
				],
			],
			$this->calls
		);
	}

	public function testACallThatEndsBeforeItStartsLastsNoTime(): void {
		$this->byToken = ['tok' => self::room('tok', 5, 'Zaak', 2)];

		$this->store()->heldCall(
			new HeldTalkCall(token: 'tok', owner: 'bob', call: ['start' => '2026-03-01T10:00:00+00:00', 'end' => '2026-03-01T09:00:00+00:00'], joined: [])
		);

		$this->assertSame(['message' => 'call_ended', 'parameters' => ['users' => [], 'cloudIds' => [], 'guests' => 0, 'duration' => 0]], $this->calls[2][5]);
	}

	public function testARoomExistsWhenTalkFindsItsToken(): void {
		$this->byToken = ['tok' => self::room('tok', 5, 'Zaak', 2)];

		$this->assertTrue($this->store()->roomExists('tok'));
		$this->assertSame([['byToken', 'tok']], $this->calls);
	}

	public function testARoomDoesNotExistWhenTalkThrowsForItsToken(): void {
		$this->assertFalse($this->store()->roomExists('gone'));
	}

	public function testDeletingARoomHandsTheFoundRoomToTheRoomService(): void {
		$this->byToken = ['tok' => self::room('tok', 5, 'Zaak', 2)];

		$this->store()->deleteRoom('tok');

		$this->assertSame([['byToken', 'tok'], ['delete', 'tok']], $this->calls);
	}

	public function testDeletingAnUnknownRoomThrowsAndDeletesNothing(): void {
		$store = $this->store();

		try {
			$store->deleteRoom('gone');
			$this->fail('an unknown room is not deleted');
		} catch (RuntimeException $e) {
			$this->assertSame('Room not found', $e->getMessage());
		}

		$this->assertSame([['byToken', 'gone']], $this->calls);
	}
}
