<?php

/**
 * The session and logging of the object email API.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\OpenRegister\Notifications\Controller\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Controller\Support;

use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The session and logging of the object email API.
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */
final readonly class EmailsPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param IUserSession $userSession User session.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		public IUserSession $userSession,
		public LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
