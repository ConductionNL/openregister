<?php

/**
 * OpenRegister Example Set Talk Conversations
 *
 * The Talk conversations an example set puts on its objects: a fragment's
 * `talkConversations` part names the object, the conversation's name, its
 * owner and participants, and optionally a call already held (`call`, with
 * `start` and `end`). The conversation is found by name among the owner's
 * rooms or created, the call is written into its history, and the room is
 * linked to the object as a linked conversation is. Remove takes away the
 * link, and the conversation when the set created it.
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
 * @spec openspec/changes/example-set-talk-conversations/specs/example-sets/spec.md#requirement-a-fragment-may-put-talk-conversations-on-its-objects
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\TalkLink;
use OCA\OpenRegister\Db\TalkLinkMapper;
use OCA\OpenRegister\Service\Party\PartyService;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Checks, writes, proves and deletes the Talk conversations of an example set.
 *
 * @spec openspec/changes/example-set-talk-conversations/specs/example-sets/spec.md#requirement-a-fragment-may-put-talk-conversations-on-its-objects
 */
class ExampleSetTalkConversations extends ExampleSetEngineRows {

	/**
	 * The conversation fields a fragment may set.
	 */
	public const FIELDS = ['object', 'name', 'owner', 'participants', 'call'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Hands out the Talk store, the link mapper and the object finder on first use.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * The fragment and record part conversations live in.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-set-talk-conversations/specs/example-sets/spec.md#requirement-a-fragment-may-put-talk-conversations-on-its-objects
	 */
	public function part(): string {
		return 'talkConversations';
	}//end part()

	/**
	 * What one row is called in messages.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-set-talk-conversations/specs/example-sets/spec.md#requirement-a-fragment-may-put-talk-conversations-on-its-objects
	 */
	public function noun(): string {
		return 'talk conversation';
	}//end noun()

	/**
	 * Why a row cannot be written, '' when it can.
	 *
	 * @param array<string, mixed> $data  The row's fields, placeholders filled.
	 * @param array<int, string>   $users The accounts the set creates or adopts.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-set-talk-conversations/specs/example-sets/spec.md#requirement-a-fragment-may-put-talk-conversations-on-its-objects
	 */
	public function refusal(array $data, array $users): string {
		$unknown = array_diff(array_keys($data), self::FIELDS);
		if ($unknown !== []) {
			return 'field "'.implode('", "', $unknown).'" is not a talk conversation field a set may write';
		}

		foreach (['object' => 'the object it is on', 'name' => 'a name', 'owner' => 'an owner'] as $field => $what) {
			if (is_string($data[$field] ?? null) === false || trim($data[$field]) === '') {
				return 'a talk conversation needs '.$what.' ('.$field.')';
			}
		}

		$people = array_merge([(string) $data['owner']], array_map('strval', (array) ($data['participants'] ?? [])));
		foreach ($people as $uid) {
			if (in_array($uid, $users, true) === false) {
				return '"'.$uid.'" is not an account the set creates or adopts';
			}
		}

		return $this->callRefusal(call: ($data['call'] ?? null));
	}//end refusal()

	/**
	 * Write one conversation, its held call and its link to the object.
	 *
	 * @param string               $app  The fragment's app.
	 * @param string               $key  The row key.
	 * @param array<string, mixed> $data The row's fields, placeholders filled.
	 *
	 * @return array{
	 *     error: string,
	 *     entry: array{token: string, room: int, createdRoom: bool, object: string, link: int}|null
	 * } The record entry, or why the write failed.
	 *
	 * @spec openspec/changes/example-set-talk-conversations/specs/example-sets/spec.md#requirement-a-fragment-may-put-talk-conversations-on-its-objects
	 */
	public function write(string $app, string $key, array $data): array {
		$store = $this->store();
		if ($store->available() === false) {
			return ['error' => 'Talk is not installed', 'entry' => null];
		}

		$object = $this->container->get(PartyService::class)->findUnscoped(uuid: (string) $data['object']);
		if ($object === null) {
			return ['error' => 'no object "'.(string) $data['object'].'" to put the conversation on', 'entry' => null];
		}

		$owner  = (string) $data['owner'];
		$people = array_values(array_unique(array_merge([$owner], array_map('strval', (array) ($data['participants'] ?? [])))));
		try {
			$room = $store->room(owner: $owner, name: (string) $data['name'], participants: $people);
			if ($room['created'] === true && is_array($data['call'] ?? null) === true) {
				$store->heldCall(
					token: $room['token'],
					owner: $owner,
					start: new DateTime((string) $data['call']['start']),
					end: new DateTime((string) $data['call']['end']),
					joined: $people
				);
			}

			$link = $this->link(app: $app, object: $object, room: $room, data: $data, people: $people);
		} catch (Throwable $e) {
			return ['error' => $e->getMessage(), 'entry' => null];
		}

		return [
			'error' => '',
			'entry' => [
				'token'       => $room['token'],
				'room'        => $room['id'],
				'createdRoom' => $room['created'],
				'object'      => (string) $data['object'],
				'link'        => $link,
			],
		];
	}//end write()

	/**
	 * Whether a written conversation is gone.
	 *
	 * @param array<string, mixed> $entry The record entry.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/example-set-talk-conversations/specs/example-sets/spec.md#requirement-a-fragment-may-put-talk-conversations-on-its-objects
	 */
	public function lost(array $entry): bool {
		try {
			return $this->store()->roomExists(token: (string) $entry['token']) === false;
		} catch (Throwable) {
			return true;
		}
	}//end lost()

	/**
	 * Take away the link, and the conversation when the set created it.
	 *
	 * @param array<string, mixed> $entry The record entry.
	 *
	 * @return string `removed` or `failed`.
	 *
	 * @spec openspec/changes/example-set-talk-conversations/specs/example-sets/spec.md#requirement-a-fragment-may-put-talk-conversations-on-its-objects
	 */
	public function revert(array $entry): string {
		try {
			$this->container->get(TalkLinkMapper::class)->deleteByObjectAndRoom(objectUuid: (string) $entry['object'], roomToken: (string) $entry['token']);
			if (($entry['createdRoom'] ?? false) === true && $this->store()->roomExists(token: (string) $entry['token']) === true) {
				$this->store()->deleteRoom(token: (string) $entry['token']);
			}
		} catch (Throwable) {
			return 'failed';
		}

		return 'removed';
	}//end revert()

	/**
	 * Link the conversation to the object, as a linked conversation is.
	 *
	 * @param string                                       $app    The fragment's app.
	 * @param ObjectEntity                                 $object The object.
	 * @param array{token: string, id: int, created: bool} $room   The conversation.
	 * @param array<string, mixed>                         $data   The row's fields.
	 * @param array<int, string>                           $people Everybody in the conversation.
	 *
	 * @return int The link id.
	 */
	private function link(string $app, ObjectEntity $object, array $room, array $data, array $people): int {
		$mapper   = $this->container->get(TalkLinkMapper::class);
		$existing = $mapper->findByObjectAndRoom((string) $data['object'], $room['token']);
		if ($existing !== null) {
			return (int) $existing->getId();
		}

		$link = new TalkLink();
		$link->setObjectUuid((string) $data['object']);
		$link->setRegisterId((int) $object->getRegister());
		$link->setSchemaId((int) $object->getSchema());
		$link->setRoomToken($room['token']);
		$link->setRoomId($room['id']);
		$link->setRoomName((string) $data['name']);
		$link->setRoomType(2);
		$link->setParticipantCount(count($people));
		$link->setLastActivity($this->lastActivity(data: $data));
		$link->setLinkedBy($app);
		$link->setLinkedAt(new DateTime());

		return (int) $mapper->insert($link)->getId();
	}//end link()

	/**
	 * When the conversation was last active: the end of its held call, or now.
	 *
	 * @param array<string, mixed> $data The row's fields.
	 *
	 * @return DateTime
	 */
	private function lastActivity(array $data): DateTime {
		if (is_array($data['call'] ?? null) === true) {
			return new DateTime((string) $data['call']['end']);
		}

		return new DateTime();
	}//end lastActivity()

	/**
	 * Why a held call cannot be written, '' when it can or there is none.
	 *
	 * @param mixed $call The declared call.
	 *
	 * @return string
	 */
	private function callRefusal(mixed $call): string {
		if ($call === null) {
			return '';
		}

		if (is_array($call) === false || isset($call['start'], $call['end']) === false) {
			return 'a held call needs a start and an end (call.start, call.end)';
		}

		try {
			$start = new DateTime((string) $call['start']);
			$end   = new DateTime((string) $call['end']);
		} catch (Throwable) {
			return 'call.start or call.end is not a date and time';
		}

		if ($end < $start) {
			return 'a held call ends after it starts';
		}

		return '';
	}//end callRefusal()

	/**
	 * The Talk store.
	 *
	 * @return ExampleSetTalkStore
	 */
	private function store(): ExampleSetTalkStore {
		return $this->container->get(ExampleSetTalkStore::class);
	}//end store()
}//end class
