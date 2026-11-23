<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\TalkLink
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\TalkLink;

/**
 * The user a Talk room is read for, with Talk's ParticipantService for the participant count.
 *
 * @spec openspec/specs/integration-talk/spec.md
 */
final class TalkViewer {
	/**
	 * Build the viewer.
	 *
	 * @param string      $userUid            User UID (for getDisplayName).
	 * @param object|null $participantService Talk's ParticipantService, when resolvable.
	 */
	public function __construct(
		public readonly string $userUid,
		public readonly ?object $participantService,
	) {
	}//end __construct()
}//end class
