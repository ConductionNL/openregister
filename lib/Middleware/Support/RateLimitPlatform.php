<?php

/**
 * OpenRegister rate limit platform
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Middleware
 * @package  OCA\OpenRegister\Middleware\Support
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

namespace OCA\OpenRegister\Middleware\Support;

use OCP\AppFramework\Utility\IControllerMethodReflector;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The session, the method reflector and the logger the rate-limit middleware reads its caller and route from.
 *
 * @spec openspec/specs/auth-system/spec.md#requirement-rate-limiting-must-protect-against-brute-force-attacks-and-api-abuse
 */
final readonly class RateLimitPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param IUserSession               $userSession The session.
	 * @param IControllerMethodReflector $reflector   Reads the route's annotations.
	 * @param LoggerInterface            $logger      Logger.
	 *
	 * @spec openspec/specs/auth-system/spec.md#requirement-rate-limiting-must-protect-against-brute-force-attacks-and-api-abuse
	 */
	public function __construct(
		public IUserSession $userSession,
		public IControllerMethodReflector $reflector,
		public LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
