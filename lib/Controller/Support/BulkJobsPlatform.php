<?php

/**
 * OpenRegister bulk jobs platform
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
 * The platform services of the bulk jobs API.
 *
 * @spec openspec/specs/bulk-action-jobs/spec.md
 */
final class BulkJobsPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param IUserSession  $userSession  Current-user session.
	 * @param IGroupManager $groupManager Group manager (admin check).
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function __construct(
		public readonly IUserSession $userSession,
		public readonly IGroupManager $groupManager,
	) {
	}//end __construct()
}//end class
