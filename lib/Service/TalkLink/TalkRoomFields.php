<?php

/**
 * TalkRoomFields
 *
 * Reads the cached fields and picker rows of a Talk Room entity for TalkLinkService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\TalkLink
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\TalkLink;

use DateTime;
use DateTimeInterface;
use OCP\IL10N;
use Throwable;

/**
 * Reads a Talk Room entity.
 *
 * Talk's Room uses Entity::__call magic and accessor availability varies
 * across Talk releases, so every getter is guarded: a missing or throwing
 * one degrades to null (or an empty string) rather than crashing.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\TalkLink
 *
 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
 */
final class TalkRoomFields {

	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n Translator for the room-type labels.
	 */
	public function __construct(
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Extract cached fields from a Talk Room entity.
	 *
	 * Returns:
	 *   - `roomId`           — Talk Room::id (legacy numeric id)
	 *   - `roomName`         — display name (or fallback raw name)
	 *   - `roomType`         — Talk Room::TYPE_*
	 *   - `subtitle`         — human-readable type label
	 *   - `participantCount` — ParticipantService::getNumberOfActors
	 *   - `lastMessage`      — {actor:{type,id},text,timestamp} or null
	 *   - `lastActivity`     — DateTime or null
	 *
	 * @param object     $room   Talk Room entity.
	 * @param TalkViewer $viewer The user (for getDisplayName) and Talk's ParticipantService, when resolvable.
	 *
	 * @return array{roomId:?int,roomName:?string,roomType:?int,subtitle:?string,participantCount:?int,lastMessage:?array,lastActivity:?DateTime}
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function cacheFields(object $room, TalkViewer $viewer): array {
		$fields = $this->identityFields(room: $room, userUid: $viewer->userUid);
		$fields['subtitle'] = $this->subtitle(room: $room, type: $fields['roomType']);
		$fields['participantCount'] = $this->participantCount(participantService: $viewer->participantService, room: $room);
		$lastActivity = $this->lastActivity(room: $room);
		$fields['lastMessage'] = $this->lastMessage(room: $room);
		$fields['lastActivity'] = $lastActivity;

		return $fields;
	}//end cacheFields()

	/**
	 * The id, display name and type of a room.
	 *
	 * @param object $room    Talk Room entity.
	 * @param string $userUid User UID (for getDisplayName).
	 *
	 * @return array{roomId:?int,roomName:?string,roomType:?int}
	 */
	private function identityFields(object $room, string $userUid): array {
		$roomId = $this->attempt(read: fn () => (int)$room->getId(), fallback: null);
		$name = $this->displayName(room: $room, userUid: $userUid);

		return [
			'roomId' => $roomId,
			'roomName' => $this->nullIfEmpty(text: $name),
			'roomType' => $this->type(room: $room),
		];
	}//end identityFields()

	/**
	 * The picker rows `{token, name, type, participantCount}` of a user's rooms.
	 *
	 * Rooms without a token, not matching the search, or of the changelog /
	 * note-to-self types are left out.
	 *
	 * @param array           $rooms The user's Talk rooms.
	 * @param RoomPickerQuery $query The viewer and the optional case-insensitive name filter.
	 *
	 * @return array<int,array{token:string,name:string,type:?int,participantCount:?int}>
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function pickerRows(array $rooms, RoomPickerQuery $query): array {
		$needle = null;
		if ($query->search !== null) {
			$needle = mb_strtolower(trim($query->search));
		}

		$viewer = $query->viewer;
		$run = new RoomPickerRun(userUid: $viewer->userUid, needle: $needle, participantService: $viewer->participantService);
		$out = [];
		foreach ($rooms as $room) {
			$row = $this->pickerRow(run: $run, room: $room);
			if ($row !== null) {
				$out[] = $row;
			}
		}

		return $out;
	}//end pickerRows()

	/**
	 * The token of a room, or an empty string when it has none or the getter throws.
	 *
	 * @param object $room Talk Room entity.
	 *
	 * @return string The token.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function token(object $room): string {
		return $this->attempt(read: fn () => (string)$room->getToken(), fallback: '');
	}//end token()

	/**
	 * One picker row, or null when the room is left out.
	 *
	 * @param RoomPickerRun $run  The picker pass: user, search and participant service.
	 * @param object        $room Talk Room entity.
	 *
	 * @return array{token:string,name:string,type:?int,participantCount:?int}|null
	 */
	private function pickerRow(RoomPickerRun $run, object $room): ?array {
		$row = $this->pickerIdentity(run: $run, room: $room);
		if ($row === null) {
			return null;
		}

		// Exclude changelog / note-to-self auto-rooms from the picker — the user can't usefully link those.
		$type = $this->type(room: $room);
		if ($type === 4 || $type === 6) {
			return null;
		}

		$row['type'] = $type;
		$row['participantCount'] = $this->participantCount(participantService: $run->participantService, room: $room);

		return $row;
	}//end pickerRow()

	/**
	 * The token and cleaned name of a picker room, or null when it has no token or misses the search.
	 *
	 * @param RoomPickerRun $run  The picker pass: user and search.
	 * @param object        $room Talk Room entity.
	 *
	 * @return array{token:string,name:string}|null
	 */
	private function pickerIdentity(RoomPickerRun $run, object $room): ?array {
		$token = $this->token(room: $room);
		if ($token === '') {
			return null;
		}

		$name = $this->displayName(room: $room, userUid: $run->userUid);
		if ($this->matchesSearch(name: $name, needle: $run->needle) === false) {
			return null;
		}

		return ['token' => $token, 'name' => $name];
	}//end pickerIdentity()

	/**
	 * Whether a room name passes the picker search.
	 *
	 * @param string      $name   The cleaned room name.
	 * @param string|null $needle Lower-cased, trimmed search, or null.
	 *
	 * @return bool
	 */
	private function matchesSearch(string $name, ?string $needle): bool {
		return $needle === null || $needle === '' || mb_strpos(mb_strtolower($name), $needle) !== false;
	}//end matchesSearch()

	/**
	 * The room's display name for the user, falling back to its raw name, without the OR marker.
	 *
	 * @param object $room    Talk Room entity.
	 * @param string $userUid User UID (for getDisplayName).
	 *
	 * @return string The name; empty when it has none.
	 */
	private function displayName(object $room, string $userUid): string {
		$name = $this->attempt(read: fn () => (string)$room->getName(), fallback: '');
		if (method_exists($room, 'getDisplayName') === true) {
			$displayName = $this->attempt(read: fn () => (string)$room->getDisplayName($userUid), fallback: '');
			if ($displayName !== '') {
				$name = $displayName;
			}
		}

		return $this->stripMarker(name: $name);
	}//end displayName()

	/**
	 * Strip the OR linking marker `[or:{uuid}]` from a room name.
	 *
	 * @param string $name Raw room name.
	 *
	 * @return string
	 */
	private function stripMarker(string $name): string {
		// Match [or:<uuid>] anywhere in the string.
		$stripped = preg_replace('/\s*\[or:[^\]]+\]\s*/', ' ', $name) ?? $name;
		return trim($stripped);
	}//end stripMarker()

	/**
	 * The room's Talk Room::TYPE_*, or null when unreadable.
	 *
	 * @param object $room Talk Room entity.
	 *
	 * @return int|null The type.
	 */
	private function type(object $room): ?int {
		return $this->attempt(read: fn () => (int)$room->getType(), fallback: null);
	}//end type()

	/**
	 * The room's participant count, or null when Talk cannot say.
	 *
	 * @param object|null $participantService Talk's ParticipantService, when resolvable.
	 * @param object      $room               Talk Room entity.
	 *
	 * @return int|null The count.
	 */
	private function participantCount(?object $participantService, object $room): ?int {
		if ($participantService === null || method_exists($participantService, 'getNumberOfActors') === false) {
			return null;
		}

		return $this->attempt(read: fn () => (int)$participantService->getNumberOfActors($room), fallback: null);
	}//end participantCount()

	/**
	 * Build a human-readable subtitle: the room description when set, else the translated room-type label.
	 *
	 * @param object   $room Talk Room.
	 * @param int|null $type Numeric room type.
	 *
	 * @return string|null
	 */
	private function subtitle(object $room, ?int $type): ?string {
		$description = '';
		if (method_exists($room, 'getDescription') === true) {
			$description = $this->attempt(read: fn () => trim((string)$room->getDescription()), fallback: '');
		}

		if ($description !== '') {
			return $description;
		}

		return $this->typeLabel(type: $type);
	}//end subtitle()

	/**
	 * The translated label of a Talk room type, or null for an unknown one.
	 *
	 * @param int|null $type Numeric room type.
	 *
	 * @return string|null
	 */
	private function typeLabel(?int $type): ?string {
		$labels = [
			1 => fn (): string => $this->l10n->t('Direct message'),
			2 => fn (): string => $this->l10n->t('Group'),
			3 => fn (): string => $this->l10n->t('Public'),
			4 => fn (): string => $this->l10n->t('System'),
			6 => fn (): string => $this->l10n->t('Note to self'),
		];
		$label = ($labels[$type ?? 0] ?? null);
		if ($label === null) {
			return null;
		}

		return $label();
	}//end typeLabel()

	/**
	 * The room's last activity, or null when it has none or it is unreadable.
	 *
	 * @param object $room Talk Room entity.
	 *
	 * @return DateTime|null
	 */
	private function lastActivity(object $room): ?DateTime {
		if (method_exists($room, 'getLastActivity') === false) {
			return null;
		}

		$raw = $this->attempt(read: fn () => $room->getLastActivity(), fallback: null);
		if (($raw instanceof DateTimeInterface) === false) {
			return null;
		}

		return $this->attempt(read: fn () => new DateTime($raw->format(DateTime::ATOM)), fallback: null);
	}//end lastActivity()

	/**
	 * Build the `{actor, text, timestamp}` shape from a Talk Room's last comment.
	 *
	 * Returns `null` when the room has no last message yet, or when
	 * the lookup throws (federated rooms return null by design).
	 *
	 * @param object $room Talk Room.
	 *
	 * @return array{actor:array{type:string,id:string},text:string,timestamp:?int}|null
	 */
	private function lastMessage(object $room): ?array {
		$comment = $this->lastComment(room: $room);
		if (is_object($comment) === false) {
			return null;
		}

		$text = $this->commentString(comment: $comment, getter: 'getMessage');
		$actor = [
			'type' => $this->commentString(comment: $comment, getter: 'getActorType'),
			'id' => $this->commentString(comment: $comment, getter: 'getActorId'),
		];

		return ['actor' => $actor, 'text' => $text, 'timestamp' => $this->commentTimestamp(comment: $comment)];
	}//end lastMessage()

	/**
	 * The room's last comment, or null when the room cannot give one.
	 *
	 * @param object $room Talk Room.
	 *
	 * @return mixed The comment.
	 */
	private function lastComment(object $room): mixed {
		if (method_exists($room, 'getLastMessage') === false) {
			return null;
		}

		return $this->attempt(read: fn () => $room->getLastMessage(), fallback: null);
	}//end lastComment()

	/**
	 * A string accessor of a comment, or an empty string when the comment lacks it.
	 *
	 * @param object $comment The last comment.
	 * @param string $getter  The accessor.
	 *
	 * @return string
	 */
	private function commentString(object $comment, string $getter): string {
		if (method_exists($comment, $getter) === false) {
			return '';
		}

		return (string)$comment->$getter();
	}//end commentString()

	/**
	 * The creation time of a comment as a Unix timestamp, or null when unreadable.
	 *
	 * @param object $comment The last comment.
	 *
	 * @return int|null
	 */
	private function commentTimestamp(object $comment): ?int {
		if (method_exists($comment, 'getCreationDateTime') === false) {
			return null;
		}

		$created = $this->attempt(read: fn () => $comment->getCreationDateTime(), fallback: null);
		if ($created instanceof DateTimeInterface) {
			return $created->getTimestamp();
		}

		return null;
	}//end commentTimestamp()

	/**
	 * Null for an empty string.
	 *
	 * @param string $text The text.
	 *
	 * @return string|null
	 */
	private function nullIfEmpty(string $text): ?string {
		if ($text === '') {
			return null;
		}

		return $text;
	}//end nullIfEmpty()

	/**
	 * Run a Talk accessor, answering the fallback when it throws.
	 *
	 * @param callable $read     The accessor.
	 * @param mixed    $fallback The answer on failure.
	 *
	 * @return mixed
	 */
	private function attempt(callable $read, mixed $fallback): mixed {
		try {
			return $read();
		} catch (Throwable $e) {
			return $fallback;
		}
	}//end attempt()
}//end class
