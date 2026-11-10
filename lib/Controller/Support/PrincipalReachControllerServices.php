<?php

/**
 * OpenRegister PrincipalReachController services
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

use OCA\OpenRegister\Service\Rbac\PrincipalReachListerInterface;
use OCA\OpenRegister\Service\Rbac\ReachRevocationServiceInterface;

/**
 * The collaborators of PrincipalReachController, wired by DI autowiring.
 *
 * @spec openspec/specs/authorization-rbac/spec.md
 */
final class PrincipalReachControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param PrincipalReachListerInterface   $reach      The listing.
	 * @param ReachRevocationServiceInterface $revocation The one act.
	 */
	public function __construct(
		public readonly PrincipalReachListerInterface $reach,
		public readonly ReachRevocationServiceInterface $revocation,
	) {
	}//end __construct()
}//end class
