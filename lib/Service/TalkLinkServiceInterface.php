<?php

/**
 * TalkLinkServiceInterface: the part of TalkLinkService that the Talk links and relations controllers use.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use Exception;
use OCA\OpenRegister\Db\TalkLink;
use OCA\OpenRegister\Service\TalkLink\LinkedObject;
use OCA\OpenRegister\Service\TalkLink\RoomCreation;

/**
 * The methods of TalkLinkService that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface TalkLinkServiceInterface {

	/**
	 * Whether NC Talk (spreed) is installed + enabled for the current
	 * user.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function isTalkAvailable(): bool;

	/**
	 * Link an existing Talk room to an OR object.
	 *
	 * The link row carries room_id/room_name/room_type/subtitle/
	 * participantCount/lastMessageData/lastActivity harvested from the
	 * Talk room so subsequent reads don't need to hit Talk. Idempotent:
	 * a duplicate link raises a 409 Exception.
	 *
	 * @param LinkedObject $object    The object to link the room to.
	 * @param string       $roomToken Talk room token.
	 *
	 * @return TalkLink The persisted link row.
	 *
	 * @throws Exception On missing user, missing room (404), duplicate (409),
	 *                   Talk unavailable (503).
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function linkRoom(LinkedObject $object, string $roomToken): TalkLink;

	/**
	 * Unlink a Talk room from an object. Does NOT destroy the room
	 * itself — it remains in Talk for other linked objects (or the
	 * users who joined it directly).
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 * @param string $roomToken Talk room token.
	 *
	 * @return void
	 *
	 * @throws Exception When no matching link is found (404).
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function unlinkRoom(string $objectUuid, string $roomToken): void;

	/**
	 * Return the linked rooms for an object, refreshing cached fields
	 * for rows older than {@see TalkLinkService::STALE_AFTER}.
	 *
	 * Always succeeds: when Talk's `Manager` is missing or `getRoom`
	 * throws for a stale link, the stored row is returned with its
	 * cached fields as-is.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function getLinkedRooms(string $objectUuid): array;

	/**
	 * Create a new Talk room and link it to an object in one
	 * transaction.
	 *
	 * Uses Talk's `Manager::createRoom` for the create then invites
	 * the current user as participant (so the room is visible in
	 * Talk's UI). The link row carries the new room's token + cached
	 * metadata.
	 *
	 * Room type maps to Talk Room::TYPE_*:
	 *   1 → one2one  (rejected — pickers exclude it)
	 *   2 → group    (default)
	 *   3 → public
	 *
	 * @param RoomCreation $creation The object, room name (required), description (optional) and room type (default 2 = group).
	 *
	 * @return TalkLink The persisted link row.
	 *
	 * @throws Exception On missing user, Talk unavailable, invalid
	 *                   type, or create failure.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function createAndLinkRoom(RoomCreation $creation): TalkLink;

	/**
	 * Return Talk rooms available to the current user for the picker.
	 *
	 * Each row is `{token, name, type, participantCount}`. The optional
	 * `$search` filter does a case-insensitive substring match on the
	 * room name client-side (Talk's getRoomsForUser doesn't accept a
	 * search param across all versions).
	 *
	 * Returns an empty array when Talk is unavailable or the manager
	 * throws.
	 *
	 * @param string|null $search Optional case-insensitive name filter.
	 *
	 * @return array<int,array{token:string,name:string,type:?int,participantCount:?int}>
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function getAvailableRoomsForUser(?string $search = null): array;
}//end interface
