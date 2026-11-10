<?php

/**
 * ExampleSetTalkStore::heldCall writes a held call as two system messages:
 * call_started at the start and call_ended (joiners, duration) at the end.
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

use OCA\OpenRegister\Service\ExampleSet\HeldTalkCall;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetTalkStore;
use OCP\App\IAppManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetTalkStore
 */
class ExampleSetTalkStoreHeldCallTest extends TestCase {

	/**
	 * The system messages the fake chat manager received.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $messages = [];

	/**
	 * A store over a container that hands out a fake Talk manager and chat manager.
	 *
	 * @return ExampleSetTalkStore
	 */
	private function store(): ExampleSetTalkStore {
		$room    = new \stdClass();
		$manager = new class($room) {
			public function __construct(private readonly object $room) {
			}

			public function getRoomByToken(string $token): object {
				$this->room->token = $token;
				return $this->room;
			}
		};
		$chat = new class($this->messages) {
			public function __construct(private array &$messages) {
			}

			public function addSystemMessage(mixed ...$arguments): void {
				$this->messages[] = $arguments;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => match ($id) {
				'OCA\Talk\Manager' => $manager,
				'OCA\Talk\Chat\ChatManager' => $chat,
			}
		);

		return new ExampleSetTalkStore($container, $this->createMock(IAppManager::class), $this->createMock(IUserManager::class));
	}

	public function testAHeldCallIsAStartedAndAnEndedSystemMessage(): void {
		$this->store()->heldCall(
			held: new HeldTalkCall(token: 'room1', owner: 'alice', call: ['start' => '2020-01-02T10:00:00+00:00', 'end' => '2020-01-02T10:30:00+00:00'], joined: ['a' => 'alice', 'b' => 'bob'])
		);

		$this->assertCount(2, $this->messages);
		[$room, $null, $actorType, $owner, $started, $at, $silent] = $this->messages[0];
		$this->assertSame(['room1', null, 'users', 'alice', '{"message":"call_started","parameters":[]}', '2020-01-02T10:00:00+00:00', false], [$room->token, $null, $actorType, $owner, $started, $at->format('c'), $silent]);
		[$room, $null, $actorType, $owner, $ended, $at, $silent] = $this->messages[1];
		$this->assertSame(
			['room1', null, 'users', 'alice', '{"message":"call_ended","parameters":{"users":["alice","bob"],"cloudIds":[],"guests":0,"duration":1800}}', '2020-01-02T10:30:00+00:00', false],
			[$room->token, $null, $actorType, $owner, $ended, $at->format('c'), $silent]
		);
	}

	public function testAnEndBeforeTheStartLastsNoTime(): void {
		$this->store()->heldCall(
			held: new HeldTalkCall(token: 'room1', owner: 'alice', call: ['start' => '2020-01-02T10:30:00+00:00', 'end' => '2020-01-02T10:00:00+00:00'], joined: [])
		);

		$this->assertSame('{"message":"call_ended","parameters":{"users":[],"cloudIds":[],"guests":0,"duration":0}}', $this->messages[1][4]);
	}
}
