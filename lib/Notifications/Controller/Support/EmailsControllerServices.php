<?php

/**
 * The service bundles of the object email API.
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

use OCA\OpenRegister\Notifications\Service\ReplyThreadService;

/**
 * The service bundles of the object email API.
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */
final readonly class EmailsControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param EmailsDomain $domain The email services bundle.
	 * @param EmailsObjectAccess $objectAccess The object access bundle.
	 * @param EmailsPlatform $platform The session and logging bundle.
	 * @param ReplyThreadService|null $replyThreads Resolves reply headers onto objects, when available.
	 */
	public function __construct(
		public EmailsDomain $domain,
		public EmailsObjectAccess $objectAccess,
		public EmailsPlatform $platform,
		public ?ReplyThreadService $replyThreads = null,
	) {
	}//end __construct()
}//end class
