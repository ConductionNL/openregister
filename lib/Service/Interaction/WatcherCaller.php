<?php

/**
 * OpenRegister watcher caller
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Interaction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Interaction;

use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Who is asking to watch: the signed-in user and the groups they are in.
 *
 * @spec openspec/specs/object-interactions/spec.md
 */
final class WatcherCaller {
	/**
	 * Bundle the collaborators.
	 *
	 * @param IUserSession  $userSession  Resolves the calling user.
	 * @param IGroupManager $groupManager Resolves the caller's groups for the `manage` posture.
	 */
	public function __construct(
		public readonly IUserSession $userSession,
		public readonly IGroupManager $groupManager,
	) {
	}//end __construct()
}//end class
