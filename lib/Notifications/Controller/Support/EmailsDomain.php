<?php

/**
 * The email services of the object email API.
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

use OCA\OpenRegister\Notifications\Service\EmailService;
use OCA\OpenRegister\Service\EmailLinkServiceInterface;

/**
 * The email services of the object email API.
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */
final readonly class EmailsDomain {
	/**
	 * Bundle the services.
	 *
	 * @param EmailService $emailService Email service.
	 * @param EmailLinkServiceInterface $emailLinkService Object-scoped email link reads and writes.
	 */
	public function __construct(
		public EmailService $emailService,
		public EmailLinkServiceInterface $emailLinkService,
	) {
	}//end __construct()
}//end class
