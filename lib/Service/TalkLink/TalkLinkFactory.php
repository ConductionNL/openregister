<?php

/**
 * TalkLinkFactory
 *
 * Builds and refreshes TalkLink rows from a room's cached fields.
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
use OCA\OpenRegister\Db\TalkLink;

/**
 * Populates TalkLink entities; persistence stays with the mapper.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\TalkLink
 *
 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
 */
final class TalkLinkFactory {

	/**
	 * A new link row between an object and a room, with the room's cached fields.
	 *
	 * @param array  $object    The object: objectUuid, registerId, schemaId.
	 * @param string $roomToken Talk room token.
	 * @param array  $cache     The room's cached fields, as TalkRoomFields::cacheFields() returns them.
	 * @param string $userUid   The user linking it.
	 *
	 * @return TalkLink The unsaved row.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function newLink(array $object, string $roomToken, array $cache, string $userUid): TalkLink {
		$link = new TalkLink();
		$link->setObjectUuid($object['objectUuid']);
		$link->setRegisterId($object['registerId']);
		$link->setSchemaId($object['schemaId']);
		$link->setRoomToken($roomToken);
		$this->applyCache(link: $link, cache: $cache);
		$link->setLinkedBy($userUid);
		$link->setLinkedAt(new DateTime());

		return $link;
	}//end newLink()

	/**
	 * Copy a room's cached fields onto a link row.
	 *
	 * @param TalkLink $link  The row.
	 * @param array    $cache The room's cached fields, as TalkRoomFields::cacheFields() returns them.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
	 */
	public function applyCache(TalkLink $link, array $cache): void {
		$link->setRoomId($cache['roomId']);
		$link->setRoomName($cache['roomName']);
		$link->setRoomType($cache['roomType']);
		$link->setSubtitle($cache['subtitle']);
		$link->setParticipantCount($cache['participantCount']);
		$lastMessageData = null;
		if ($cache['lastMessage'] !== null) {
			$lastMessageData = json_encode($cache['lastMessage']);
		}

		$link->setLastMessageData($lastMessageData);
		$link->setLastActivity($cache['lastActivity']);
	}//end applyCache()
}//end class
