<?php

/**
 * OpenRegister tasks platform
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * The platform services of the tasks API.
 *
 * @spec openspec/specs/object-interactions/spec.md
 */
final class TasksPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param ?IUserSession  $userSession  Names the caller for the aggregate
	 * @param ?IGroupManager $groupManager Resolves the caller's groups and admin
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function __construct(
		public readonly ?IUserSession $userSession = null,
		public readonly ?IGroupManager $groupManager = null,
	) {
	}//end __construct()
}//end class
