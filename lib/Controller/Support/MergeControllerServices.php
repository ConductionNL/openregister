<?php

/**
 * OpenRegister MergeController services
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

use OCA\OpenRegister\Kernel\Contracts\ObjectMergerInterface;
use OCP\IUserSession;

/**
 * The collaborators of MergeController, wired by DI autowiring.
 */
final class MergeControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectMergerInterface $mergeService MDM merge engine.
	 * @param IUserSession          $userSession  Current user session, for actor attribution.
	 */
	public function __construct(
		public readonly ObjectMergerInterface $mergeService,
		public readonly IUserSession $userSession,
	) {
	}//end __construct()
}//end class
