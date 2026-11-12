<?php

/**
 * NotificationChannelPolicyInterface: the public surface of NotificationChannelPolicy, for callers to type against.
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
 * Every public method of NotificationChannelPolicy. Callers depend on this interface, not on the class.
 */
interface NotificationChannelPolicyInterface {

	public const APP_ID = 'openregister';

	/**
	 * Whether the given channel may send. Defaults to ON.
	 *
	 * @param string $channel The channel name (`nc-notification`, `email`, `talk`).
	 *
	 * @return bool True when sends on this channel may proceed.
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flow-sends-are-attributed-logged-and-bounded
	 */
	public function isChannelEnabled(string $channel): bool;
}//end interface
