<?php

/**
 * The user session and logger of a calendar or contact link service.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The user session and logger of a calendar or contact link service.
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */
final class LinkSessionRuntime {
	/**
	 * Bundle the collaborators.
	 *
	 * @param IUserSession    $userSession The active session.
	 * @param LoggerInterface $logger      Logger.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function __construct(
		public readonly IUserSession $userSession,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
