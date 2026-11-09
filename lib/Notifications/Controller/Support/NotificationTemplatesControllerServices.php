<?php

/**
 * The services of the notification templates API.
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

use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Kernel\Contracts\NotificationTemplatesInterface;

/**
 * The services of the notification templates API.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final readonly class NotificationTemplatesControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param NotificationTemplatesInterface $registry The template registry.
	 * @param ControllerSession              $session  The user session and the authority on who is an administrator.
	 */
	public function __construct(
		public NotificationTemplatesInterface $registry,
		public ControllerSession $session,
	) {
	}//end __construct()
}//end class
