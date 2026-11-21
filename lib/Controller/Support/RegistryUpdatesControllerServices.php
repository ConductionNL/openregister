<?php

/**
 * OpenRegister RegistryUpdatesController services
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

use OCA\OpenRegister\Service\Registry\RegistrySubscriptionLifecycleInterface;
use Psr\Log\LoggerInterface;

/**
 * The collaborators of RegistryUpdatesController, wired by DI autowiring.
 */
final class RegistryUpdatesControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param RegistrySubscriptionLifecycleInterface $subscriptions Applies the update.
	 * @param ?LoggerInterface                       $logger        Consumed by HandlesExceptionsTrait for server-side 500 logging.
	 */
	public function __construct(
		public readonly RegistrySubscriptionLifecycleInterface $subscriptions,
		public readonly ?LoggerInterface $logger = null,
	) {
	}//end __construct()
}//end class
