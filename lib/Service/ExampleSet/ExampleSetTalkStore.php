<?php

/**
 * OpenRegister Example Set Talk Store
 *
 * The Talk side of an example set's `talkConversations` part: a group
 * conversation found by name among the owner's rooms or created, its
 * participants added, and a call already held written into its history as
 * Talk writes one (a `call_started` and a `call_ended` system message, the
 * second with who joined and how long it lasted). Talk is reached through
 * its own services by name, so an instance without Talk loads this class
 * and reports it unavailable.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-talk-conversations-on-its-objects
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use DateTime;
use OCP\App\IAppManager;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Finds, writes and deletes the Talk conversations of an example set.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-talk-conversations-on-its-objects
 */
class ExampleSetTalkStore {

	/**
	 * Talk's room manager.
	 */
	private const MANAGER = 'OCA\Talk\Manager';

	/**
	 * Talk's room service.
	 */
	private const ROOMS = 'OCA\Talk\Service\RoomService';

	/**
	 * Talk's participant service.
	 */
	private const PARTICIPANTS = 'OCA\Talk\Service\ParticipantService';

	/**
	 * Talk's chat manager, which writes the system messages of a call.
	 */
	private const CHAT = 'OCA\Talk\Chat\ChatManager';

	/**
	 * Talk's group conversation type.
	 */
	private const GROUP_ROOM = 2;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container   Hands out Talk's services.
	 * @param IAppManager        $appManager  Tells whether Talk is installed.
	 * @param IUserManager       $userManager Finds the owner and the participants.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
		private readonly IUserManager $userManager,
	) {
	}//end __construct()

	/**
	 * Whether Talk is installed and its services load.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-talk-conversations-on-its-objects
	 */
	public function available(): bool {
		return $this->appManager->isInstalled('spreed') === true && class_exists(self::ROOMS) === true && class_exists(self::CHAT) === true;
	}//end available()

	/**
	 * Find the owner's group room by name, or make it with the participants.
	 *
	 * @param ExampleSetTalkRoom $room The owner, name and participants.
	 *
	 * @return array{token: string, id: int, created: bool}
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-talk-conversations-on-its-objects
	 */
	public function openRoom(ExampleSetTalkRoom $room): array {
		$found = $this->groupRoom(owner: $room->owner, name: $room->name);
		if ($found !== null) {
			return ['token' => (string) $found->getToken(), 'id' => (int) $found->getId(), 'created' => false];
		}

		$made = $this->container->get(self::ROOMS)->createConversation(self::GROUP_ROOM, $room->name, $this->userManager->get($room->owner));
		$this->container->get(self::PARTICIPANTS)->addUsers($made, $this->people(owner: $room->owner, participants: $room->participants));

		return ['token' => (string) $made->getToken(), 'id' => (int) $made->getId(), 'created' => true];
	}//end openRoom()

	/**
	 * The owner's group conversation with this name, or null.
	 *
	 * @param string $owner The owner.
	 * @param string $name  The conversation name.
	 *
	 * @return mixed The Talk room, or null.
	 */
	private function groupRoom(string $owner, string $name): mixed {
		foreach ($this->container->get(self::MANAGER)->getRoomsForUser($owner) as $room) {
			if ($room->getName() === $name && (int) $room->getType() === self::GROUP_ROOM) {
				return $room;
			}
		}

		return null;
	}//end groupRoom()

	/**
	 * The participants to add to a new conversation: every existing account but the owner, who is in it already.
	 *
	 * @param string             $owner        The owner.
	 * @param array<int, string> $participants The participants' accounts.
	 *
	 * @return array<int, array{actorType: string, actorId: string, displayName: string}>
	 */
	private function people(string $owner, array $participants): array {
		$people = [];
		foreach ($participants as $uid) {
			if ($uid !== $owner && $this->userManager->get($uid) !== null) {
				$people[] = ['actorType' => 'users', 'actorId' => $uid, 'displayName' => (string) $this->userManager->get($uid)->getDisplayName()];
			}
		}

		return $people;
	}//end people()

	/**
	 * Write a call that was held into the conversation's history.
	 *
	 * @param HeldTalkCall $held The conversation token, the account that started the call, when it started and
	 *                           ended, and the accounts that joined.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-talk-conversations-on-its-objects
	 */
	public function heldCall(HeldTalkCall $held): void {
		$start = new DateTime((string) $held->call['start']);
		$end   = new DateTime((string) $held->call['end']);
		$room  = $this->container->get(self::MANAGER)->getRoomByToken($held->token);
		$chat  = $this->container->get(self::CHAT);
		$chat->addSystemMessage($room, null, 'users', $held->owner, (string) json_encode(['message' => 'call_started', 'parameters' => []]), $start, false);
		$ended = self::callEnded(joined: $held->joined, seconds: max(0, $end->getTimestamp() - $start->getTimestamp()));
		$chat->addSystemMessage($room, null, 'users', $held->owner, (string) json_encode($ended), $end, false);
	}//end heldCall()

	/**
	 * The system message that ends a held call.
	 *
	 * @param array<int, string> $joined  Who joined it.
	 * @param int                $seconds How long it lasted.
	 *
	 * @return array<string, mixed>
	 */
	private static function callEnded(array $joined, int $seconds): array {
		return [
			'message'    => 'call_ended',
			'parameters' => [
				'users'    => array_values($joined),
				'cloudIds' => [],
				'guests'   => 0,
				'duration' => $seconds,
			],
		];
	}//end callEnded()

	/**
	 * Whether the conversation still exists.
	 *
	 * @param string $token The conversation token.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-talk-conversations-on-its-objects
	 */
	public function roomExists(string $token): bool {
		try {
			$this->container->get(self::MANAGER)->getRoomByToken($token);
		} catch (Throwable) {
			return false;
		}

		return true;
	}//end roomExists()

	/**
	 * Delete a conversation the set created, with its history.
	 *
	 * @param string $token The conversation token.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-talk-conversations-on-its-objects
	 */
	public function deleteRoom(string $token): void {
		$room = $this->container->get(self::MANAGER)->getRoomByToken($token);
		$this->container->get(self::ROOMS)->deleteRoom($room);
	}//end deleteRoom()
}//end class
