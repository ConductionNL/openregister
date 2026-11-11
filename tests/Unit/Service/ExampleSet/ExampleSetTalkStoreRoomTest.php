<?php

/**
 * ExampleSetTalkStore::room finds the owner's group room by name, or makes
 * it and adds the other existing participants.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetTalkRoom;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetTalkStore;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetTalkStore
 */
class ExampleSetTalkStoreRoomTest extends TestCase {

	/**
	 * What the fake Talk services were asked to do.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * A fake Talk room.
	 *
	 * @param string $token The token.
	 * @param int    $id    The id.
	 * @param string $name  The name.
	 * @param int    $type  The room type.
	 *
	 * @return object
	 */
	private static function room(string $token, int $id, string $name, int $type): object {
		return new class($token, $id, $name, $type) {
			public function __construct(private string $token, private int $id, private string $name, private int $type) {
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
	 * A store over fake Talk services; alice and bob exist, ghost does not.
	 *
	 * @param array<int, object> $existing The owner's rooms.
	 *
	 * @return ExampleSetTalkStore
	 */
	private function store(array $existing): ExampleSetTalkStore {
		$calls   = &$this->calls;
		$manager = new class($existing) {
			public function __construct(private array $rooms) {
			}

			public function getRoomsForUser(string $uid): array {
				return $this->rooms;
			}
		};
		$rooms = new class($calls) {
			public function __construct(private array &$calls) {
			}

			public function createConversation(int $type, string $name, mixed $owner): object {
				$this->calls[] = ['create', $type, $name, $owner?->getUID()];
				return new class {
					public function getToken(): string {
						return 'new-token';
					}

					public function getId(): int {
						return 9;
					}
				};
			}
		};
		$participants = new class($calls) {
			public function __construct(private array &$calls) {
			}

			public function addUsers(object $room, array $people): void {
				$this->calls[] = ['add', $room->getToken(), $people];
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => match ($id) {
				'OCA\Talk\Manager' => $manager,
				'OCA\Talk\Service\RoomService' => $rooms,
				'OCA\Talk\Service\ParticipantService' => $participants,
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
				$user->method('getDisplayName')->willReturn(ucfirst($uid));
				return $user;
			}
		);

		return new ExampleSetTalkStore($container, $this->createMock(IAppManager::class), $users);
	}

	public function testAnExistingGroupRoomOfTheSameNameIsFound(): void {
		$rooms = [self::room('one-to-one', 1, 'Case 1', 1), self::room('group', 2, 'Case 1', 2)];

		$this->assertSame(['token' => 'group', 'id' => 2, 'created' => false], $this->store($rooms)->openRoom(room: new ExampleSetTalkRoom(owner: 'alice', name: 'Case 1', participants: ['bob'])));
		$this->assertSame([], $this->calls);
	}

	public function testAMissingRoomIsCreatedWithTheOtherExistingParticipants(): void {
		$made = $this->store([self::room('other', 3, 'Case 2', 2)])->openRoom(room: new ExampleSetTalkRoom(owner: 'alice', name: 'Case 1', participants: ['alice', 'bob', 'ghost']));

		$this->assertSame(['token' => 'new-token', 'id' => 9, 'created' => true], $made);
		$this->assertSame(
			[
				['create', 2, 'Case 1', 'alice'],
				['add', 'new-token', [['actorType' => 'users', 'actorId' => 'bob', 'displayName' => 'Bob']]],
			],
			$this->calls
		);
	}
}
