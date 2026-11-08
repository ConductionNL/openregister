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
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-talk-conversations-on-its-objects
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\TalkLink;
use OCA\OpenRegister\Db\TalkLinkMapperInterface;
use OCA\OpenRegister\Service\Party\PartyService;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Checks, writes, proves and deletes the Talk conversations of an example set.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-talk-conversations-on-its-objects
 */
class ExampleSetTalkConversations extends ExampleSetEngineRows {

	/**
	 * The conversation fields a fragment may set.
	 */
	public const FIELDS = ['object', 'name', 'owner', 'participants', 'call'];

	/**
	 * The fields a conversation needs, with what each holds.
	 */
	private const REQUIRED = ['object' => 'the object it is on', 'name' => 'a name', 'owner' => 'an owner'];

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
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-talk-conversations-on-its-objects
	 */
	public function part(): string {
		return 'talkConversations';
	}//end part()

	/**
	 * What one row is called in messages.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-talk-conversations-on-its-objects
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
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-talk-conversations-on-its-objects
	 */
	public function refusal(array $data, array $users): string {
		$rules = new ExampleSetFieldRules();
		return $rules->firstRefusal(
			checks: [
				fn (): string => $rules->unknownRefusal(data: $data, fields: self::FIELDS, row: 'a talk conversation'),
				fn (): string => $rules->missingRefusal(data: $data, required: self::REQUIRED, row: 'a talk conversation'),
				fn (): string => $rules->strangerRefusal(people: $this->people(data: $data), users: $users),
				fn (): string => $this->callRefusal(call: ($data['call'] ?? null)),
			]
		);
	}//end refusal()

	/**
	 * The owner and the participants a row names, the owner first.
	 *
	 * @param array<string, mixed> $data The row's fields.
	 *
	 * @return array<int, string>
	 */
	private function people(array $data): array {
		return array_merge([(string) $data['owner']], array_map('strval', (array) ($data['participants'] ?? [])));
	}//end people()

	/**
	 * Write one conversation, its held call and its link to the object.
	 *
	 * @param ExampleSetRow $row The row: its app, its key in the fragment and its filled fields.
	 *
	 * @return array{
	 *     error: string,
	 *     entry: array{token: string, room: int, createdRoom: bool, object: string, link: int}|null
	 * } The record entry, or why the write failed.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-talk-conversations-on-its-objects
	 */
	public function write(ExampleSetRow $row): array {
		$app = $row->app;
		$data = $row->data;
		$found = $this->target(data: $data);
		if ($found['error'] !== '') {
			return ['error' => $found['error'], 'entry' => null];
		}

		try {
			$entry = $this->writeOn(object: $found['object'], conversation: ['app' => $app, 'data' => $data]);
		} catch (Throwable $e) {
			return ['error' => $e->getMessage(), 'entry' => null];
		}

		return ['error' => '', 'entry' => $entry];
	}//end write()

	/**
	 * The object a row puts its conversation on, or why there is none to write on.
	 *
	 * @param array<string, mixed> $data The row's fields, placeholders filled.
	 *
	 * @return array{error: string, object: ObjectEntity|null}
	 */
	private function target(array $data): array {
		if ($this->store()->available() === false) {
			return ['error' => 'Talk is not installed', 'object' => null];
		}

		$object = $this->container->get(PartyService::class)->findUnscoped(uuid: (string) $data['object']);
		if ($object === null) {
			return ['error' => 'no object "'.(string) $data['object'].'" to put the conversation on', 'object' => null];
		}

		return ['error' => '', 'object' => $object];
	}//end target()

	/**
	 * Write the conversation, its held call and its link on the object.
	 *
	 * @param ObjectEntity                                    $object       The object.
	 * @param array{app: string, data: array<string, mixed>} $conversation The fragment's app and the row's fields.
	 *
	 * @return array{token: string, room: int, createdRoom: bool, object: string, link: int} The record entry.
	 */
	private function writeOn(ObjectEntity $object, array $conversation): array {
		$data                   = $conversation['data'];
		$conversation['people'] = array_values(array_unique($this->people(data: $data)));
		$conversation['room']   = $this->store()->room(owner: (string) $data['owner'], name: (string) $data['name'], participants: $conversation['people']);
		$this->holdCall(conversation: $conversation);
		$link = $this->link(object: $object, conversation: $conversation);

		return [
			'token'       => $conversation['room']['token'],
			'room'        => $conversation['room']['id'],
			'createdRoom' => $conversation['room']['created'],
			'object'      => (string) $data['object'],
			'link'        => $link,
		];
	}//end writeOn()

	/**
	 * Hold the declared call in a conversation the set created.
	 *
	 * @param array<string, mixed> $conversation The row's `data`, the `room` and the `people` in it.
	 *
	 * @return void
	 */
	private function holdCall(array $conversation): void {
		$data = $conversation['data'];
		if ($conversation['room']['created'] !== true || is_array($data['call'] ?? null) === false) {
			return;
		}

		$this->store()->heldCall(
			token: $conversation['room']['token'],
			owner: (string) $data['owner'],
			start: new DateTime((string) $data['call']['start']),
			end: new DateTime((string) $data['call']['end']),
			joined: $conversation['people']
		);
	}//end holdCall()

	/**
	 * Whether a written conversation is gone.
	 *
	 * @param array<string, mixed> $entry The record entry.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-talk-conversations-on-its-objects
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
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-talk-conversations-on-its-objects
	 */
	public function revert(array $entry): string {
		try {
			$this->container->get(TalkLinkMapperInterface::class)->deleteByObjectAndRoom(
				objectUuid: (string) $entry['object'],
				roomToken: (string) $entry['token']
			);
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
	 * @param ObjectEntity         $object       The object.
	 * @param array<string, mixed> $conversation The fragment's `app`, the row's `data`, the `room` and the `people` in it.
	 *
	 * @return int The link id.
	 */
	private function link(ObjectEntity $object, array $conversation): int {
		$mapper   = $this->container->get(TalkLinkMapperInterface::class);
		$existing = $mapper->findByObjectAndRoom((string) $conversation['data']['object'], $conversation['room']['token']);
		if ($existing !== null) {
			return (int) $existing->getId();
		}

		$link = $this->withRoom(link: $this->newLink(object: $object, conversation: $conversation), conversation: $conversation);

		return (int) $mapper->insert($link)->getId();
	}//end link()

	/**
	 * A new link on the object, by the fragment's app, made now.
	 *
	 * @param ObjectEntity         $object       The object.
	 * @param array<string, mixed> $conversation The fragment's `app` and the row's `data`.
	 *
	 * @return TalkLink
	 */
	private function newLink(ObjectEntity $object, array $conversation): TalkLink {
		$link = new TalkLink();
		$link->setObjectUuid((string) $conversation['data']['object']);
		$link->setRegisterId((int) $object->getRegister());
		$link->setSchemaId((int) $object->getSchema());
		$link->setLinkedBy($conversation['app']);
		$link->setLinkedAt(new DateTime());

		return $link;
	}//end newLink()

	/**
	 * A link with the conversation's room on it.
	 *
	 * @param TalkLink             $link         The link.
	 * @param array<string, mixed> $conversation The row's `data`, the `room` and the `people` in it.
	 *
	 * @return TalkLink
	 */
	private function withRoom(TalkLink $link, array $conversation): TalkLink {
		$link->setRoomToken($conversation['room']['token']);
		$link->setRoomId($conversation['room']['id']);
		$link->setRoomName((string) $conversation['data']['name']);
		$link->setRoomType(2);
		$link->setParticipantCount(count($conversation['people']));
		$link->setLastActivity($this->lastActivity(data: $conversation['data']));

		return $link;
	}//end withRoom()

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

		return $this->callTimesRefusal(call: $call);
	}//end callRefusal()

	/**
	 * Why a held call's start and end cannot be written, '' when they can.
	 *
	 * @param array<string, mixed> $call The declared call, with a start and an end.
	 *
	 * @return string
	 */
	private function callTimesRefusal(array $call): string {
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
	}//end callTimesRefusal()

	/**
	 * The Talk store.
	 *
	 * @return ExampleSetTalkStore
	 */
	private function store(): ExampleSetTalkStore {
		return $this->container->get(ExampleSetTalkStore::class);
	}//end store()
}//end class
