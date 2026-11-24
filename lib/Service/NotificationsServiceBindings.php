<?php

/**
 * NotificationsServiceBindings: the map of the Notifications area's service interfaces in lib/Service.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Service\Notification\ReferencedAddressNotifier;
use OCA\OpenRegister\Service\Notification\ReferencedAddressNotifierInterface;

/**
 * The Notifications area's service interfaces in lib/Service, bound to their class.
 *
 * @spec exclude Container wiring; no behaviour of its own.
 */
final class NotificationsServiceBindings {

	/**
	 * The interfaces callers type against, bound to their class.
	 *
	 * @var array<class-string, class-string>
	 */
	public const BINDINGS = [
		ReferencedAddressNotifierInterface::class => ReferencedAddressNotifier::class,
	];
}//end class
