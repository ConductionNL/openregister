<?php

/**
 * NotificationAnnotationValidators: builds the notification annotation validator for the schema save path.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Notifications
 * @package  OCA\OpenRegister\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

/**
 * The schema mapper's save-time check constructs this validator directly, outside the
 * container. It does that here, so code outside the Notifications component names only the interface.
 */
final class NotificationAnnotationValidators {

	/**
	 * The validator of the `x-openregister-notifications` annotation, with its default collaborators.
	 *
	 * @return NotificationAnnotationValidatorInterface
	 */
	public static function create(): NotificationAnnotationValidatorInterface {
		return new NotificationAnnotationValidator();
	}//end create()
}//end class
