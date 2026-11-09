<?php

/**
 * MagicMapperIdentity: the user context MagicMapper's access handlers read.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * The session, groups and users the RBAC and organisation handlers of MagicMapper read.
 *
 * One of the constructor bundles of MagicMapper, built by MapperWiring.
 *
 * @spec openspec/specs/magic-table-reads/spec.md
 */
final class MagicMapperIdentity {
	/**
	 * Constructor.
	 *
	 * @param IUserSession  $userSession  User session for authentication context.
	 * @param IGroupManager $groupManager Group manager for RBAC operations.
	 * @param IUserManager  $userManager  User manager for user operations.
	 */
	public function __construct(
		public readonly IUserSession $userSession,
		public readonly IGroupManager $groupManager,
		public readonly IUserManager $userManager,
	) {
	}//end __construct()
}//end class
