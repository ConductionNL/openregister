<?php

/**
 * OpenRegister bulk platform
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

use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * The platform services of the bulk API.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class BulkPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param IUserSession  $userSession  User session for admin/manage checks
	 * @param IGroupManager $groupManager Group manager for admin/manage checks
	 * @param IDBConnection $db           The connection an atomic batch runs its transaction on
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function __construct(
		public readonly IUserSession $userSession,
		public readonly IGroupManager $groupManager,
		public readonly IDBConnection $db,
	) {
	}//end __construct()
}//end class
