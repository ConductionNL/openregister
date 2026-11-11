<?php

/**
 * The caller's session, the app config and the logger of a transition, wired by DI autowiring.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The caller's session, the app config and the logger of a transition, wired by DI autowiring.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class TransitionPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param IUserSession    $userSession Resolves the acting user.
	 * @param IAppConfig      $appConfig   App config, for the slug-contract opt-in.
	 * @param LoggerInterface $logger      Logger for post-commit listener failures.
	 */
	public function __construct(
		public readonly IUserSession $userSession,
		public readonly IAppConfig $appConfig,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
