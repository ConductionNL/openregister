<?php

/**
 * RoomRefreshRun: the Talk manager and user one linked-rooms read refreshes stale rows with.
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
 * The Talk manager and the current user, shared by the helpers that refresh
 * stale link rows during one TalkLinkService::getLinkedRooms() call.
 *
 * ADR-011 Rule 3 run object; it never leaves TalkLinkService.
 *
 * @internal
 *
 * @spec openspec/specs/integration-talk/spec.md
 */
final class RoomRefreshRun {

	/**
	 * Describe it.
	 *
	 * @param object $manager Talk's Manager.
	 * @param string $userUid The current user's uid.
	 */
	public function __construct(
		public readonly object $manager,
		public readonly string $userUid,
	) {
	}//end __construct()
}//end class
