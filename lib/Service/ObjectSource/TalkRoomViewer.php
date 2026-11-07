<?php

/**
 * TalkRoomViewer: who a Talk room is normalised for.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

/**
 * The acting user (for room display names) and Talk's participant service
 * (for participant counts; null when it cannot be resolved).
 *
 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
 */
final class TalkRoomViewer {

	/**
	 * Constructor.
	 *
	 * @param string      $userId             The acting user's uid.
	 * @param object|null $participantService Talk's ParticipantService, or null.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $userId,
		public readonly ?object $participantService,
	) {
	}//end __construct()
}//end class
