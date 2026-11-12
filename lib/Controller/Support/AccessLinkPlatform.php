<?php

/**
 * OpenRegister access link platform
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

use OCP\IUserSession;
use OCP\Security\Bruteforce\IThrottler;
use Psr\Log\LoggerInterface;

/**
 * The platform services of the access link API.
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
 */
final class AccessLinkPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param IUserSession    $userSession The current session, on the owner endpoints.
	 * @param IThrottler      $throttler   Brute-force throttler for rejected anchors.
	 * @param LoggerInterface $logger      PSR logger.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
	 */
	public function __construct(
		public readonly IUserSession $userSession,
		public readonly IThrottler $throttler,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
