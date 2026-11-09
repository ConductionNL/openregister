<?php

/**
 * OpenRegister assignee follow services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener\Support
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

namespace OCA\OpenRegister\Listener\Support;

use OCA\OpenRegister\Service\Interaction\WatcherServiceInterface;
use OCA\OpenRegister\Service\Schemas\SemanticRoleHandlerInterface;
use OCP\IUserManager;

/**
 * The semantic roles that name an assignee, the users they resolve to and the watchers an assignee is added to.
 *
 * @spec openspec/specs/object-interactions/spec.md#requirement-being-assigned-an-object-follows-it-with-notifications-on
 */
final readonly class AssigneeFollowServices {
	/**
	 * Bundle the services.
	 *
	 * @param SemanticRoleHandlerInterface $roles       Reads the assignee role.
	 * @param IUserManager                 $userManager Resolves the assignee.
	 * @param WatcherServiceInterface      $watchers    Adds the watcher.
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-being-assigned-an-object-follows-it-with-notifications-on
	 */
	public function __construct(
		public SemanticRoleHandlerInterface $roles,
		public IUserManager $userManager,
		public WatcherServiceInterface $watchers,
	) {
	}//end __construct()
}//end class
