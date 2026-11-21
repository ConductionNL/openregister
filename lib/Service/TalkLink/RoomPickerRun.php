<?php

/**
 * OpenRegister RoomPickerRun
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\TalkLink
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\TalkLink;

/**
 * One pass of TalkRoomFields over a user's rooms for the link picker: the user, the search and Talk's participant service.
 *
 * Never leaves TalkRoomFields.
 *
 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-2/tasks.md#task-2
 */
final class RoomPickerRun {

	/**
	 * Constructor.
	 *
	 * @param string      $userUid            User UID (for getDisplayName).
	 * @param string|null $needle             Lower-cased, trimmed search, or null.
	 * @param object|null $participantService Talk's ParticipantService, when resolvable.
	 */
	public function __construct(
		public readonly string $userUid,
		public readonly ?string $needle,
		public readonly ?object $participantService,
	) {

	}//end __construct()
}//end class
