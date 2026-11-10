<?php

/**
 * OpenRegister CaseTokenController services
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

use OCA\OpenRegister\Service\CaseTokenServiceInterface;
use OCP\Security\Bruteforce\IThrottler;
use Psr\Log\LoggerInterface;

/**
 * The collaborators of CaseTokenController, wired by DI autowiring.
 *
 * @spec openspec/specs/integration-leaf-foundation/spec.md
 */
final class CaseTokenControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param CaseTokenServiceInterface $tokenService Case-token mint/resolve/revoke service.
	 * @param IThrottler                $throttler    Brute-force throttler for rejected tokens.
	 * @param LoggerInterface           $logger       PSR logger.
	 */
	public function __construct(
		public readonly CaseTokenServiceInterface $tokenService,
		public readonly IThrottler $throttler,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
