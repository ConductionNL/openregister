<?php

/**
 * RoomCreation: a Talk room to create and link to an object.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/integration-talk/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\TalkLink;

/**
 * What creating a Talk room for an object asks for.
 *
 * ADR-011 Rule 2 operation object for TalkLinkService::createAndLinkRoom().
 *
 * @spec openspec/specs/integration-talk/spec.md
 */
final class RoomCreation {

	/**
	 * Describe it.
	 *
	 * @param LinkedObject $object      The object the room is linked to.
	 * @param string       $roomName    Display name for the room (the object tag is appended).
	 * @param string|null  $description Optional room description.
	 * @param int          $roomType    2 = group (default), 3 = public.
	 */
	public function __construct(
		public readonly LinkedObject $object,
		public readonly string $roomName,
		public readonly ?string $description,
		public readonly int $roomType = 2,
	) {
	}//end __construct()
}//end class
