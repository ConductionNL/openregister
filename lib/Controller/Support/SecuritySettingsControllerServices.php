<?php

/**
 * OpenRegister SecuritySettingsController services
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

use OCA\OpenRegister\Service\RateLimitResetInterface;
use Psr\Log\LoggerInterface;

/**
 * The collaborators of SecuritySettingsController, wired by DI autowiring.
 */
final class SecuritySettingsControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param RateLimitResetInterface $securityService Security service.
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		public readonly RateLimitResetInterface $securityService,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
