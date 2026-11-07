<?php

/**
 * OpenRegister actor forwarding services
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

use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The services an ActorForwardedJob needs to run as the captured actor and restore the session afterwards.
 *
 * @spec openspec/specs/event-driven-architecture/spec.md
 */
final class ActorForwardingServices {
	/**
	 * Bundle the services.
	 *
	 * @param IUserSession                 $userSession  Session to impersonate on / restore.
	 * @param IUserManager                 $userManager  Resolver for the captured user id.
	 * @param OrganisationServiceInterface $organisation Active-organisation resolver.
	 * @param LoggerInterface              $logger       PSR logger.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function __construct(
		public readonly IUserSession $userSession,
		public readonly IUserManager $userManager,
		public readonly OrganisationServiceInterface $organisation,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
