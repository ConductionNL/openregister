<?php

/**
 * OpenRegister TimeTrackerLinksController services
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

use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\TimeTrackerLinkServiceInterface;

/**
 * The collaborators of TimeTrackerLinksController, wired by DI autowiring.
 *
 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
 */
final class TimeTrackerLinksControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param TimeTrackerLinkServiceInterface $linkService   Backing service.
	 * @param ObjectOperationsInterface       $objectService OR object resolver.
	 */
	public function __construct(
		public readonly TimeTrackerLinkServiceInterface $linkService,
		public readonly ObjectOperationsInterface $objectService,
	) {
	}//end __construct()
}//end class
