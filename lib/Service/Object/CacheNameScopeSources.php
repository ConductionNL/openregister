<?php

/**
 * The inputs of CacheHandler's name scope
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * The inputs CacheHandler's name scope is resolved from: the current user,
 * the admin-override group check and the multitenancy switches. Only inputs;
 * the scope decision itself stays in CacheHandler.
 */
final class CacheNameScopeSources {

	/**
	 * @param IUserSession|null  $userSession  User session for cache key generation
	 * @param IGroupManager|null $groupManager Group manager for the admin-override arm of the name scope
	 * @param IAppConfig|null    $appConfig    App config for the multitenancy switches read by the name scope
	 */
	public function __construct(
		public readonly ?IUserSession $userSession = null,
		public readonly ?IGroupManager $groupManager = null,
		public readonly ?IAppConfig $appConfig = null,
	) {
	}//end __construct()
}//end class
