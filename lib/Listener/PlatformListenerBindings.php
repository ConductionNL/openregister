<?php

/**
 * PlatformListenerBindings: the map of the Platform area's listener contracts.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
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

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Kernel\Contracts\Processes\ObjectWritePushInterface;

/**
 * Listener contract => Platform listener, behind PlatformServiceRegistrar.
 *
 * The ordered listener list registers the contract; the alias resolves to the
 * listener's own shared instance, so it runs at the same place in the order.
 *
 * @spec exclude Container wiring; no behaviour of its own.
 */
final class PlatformListenerBindings {

	/**
	 * The listener contracts the ordered list registers, bound to their listener.
	 *
	 * @var array<class-string, class-string>
	 */
	public const BINDINGS = [
		ObjectWritePushInterface::class => NotifyPushListener::class,
	];
}//end class
