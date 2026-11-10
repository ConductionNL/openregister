<?php

/**
 * OpenRegister deferred object event actor
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob\Support
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

namespace OCA\OpenRegister\BackgroundJob\Support;

use OCP\IUserManager;
use OCP\IUserSession;

/**
 * What restores the acting user for a deferred dispatch and clears it afterwards.
 *
 * @spec openspec/changes/object-write-sub-500ms/specs/object-write-performance/spec.md
 */
final class DeferredObjectEventActor {
	/**
	 * Bundle the services.
	 *
	 * @param IUserSession $userSession Restores the acting user for the dispatch.
	 * @param IUserManager $userManager Resolves the acting user by uid.
	 *
	 * @spec openspec/changes/object-write-sub-500ms/specs/object-write-performance/spec.md
	 */
	public function __construct(
		public readonly IUserSession $userSession,
		public readonly IUserManager $userManager,
	) {
	}//end __construct()
}//end class
