<?php

/**
 * The rule a notice is sent under and how it links back.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

/**
 * The rule (or flow step) a notice is sent under: its identity, the app it
 * links into, its action buttons, and whether web-push rides along.
 *
 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
 */
final class NoticeRule {
	/**
	 * Constructor.
	 *
	 * @param string                           $name Rule name or step identity (notification type identifier).
	 * @param string                           $originApp Resolved originApp (declared or register-owning app).
	 * @param array<int, array<string, mixed>> $actions Resolved action buttons (label map + deeplink url + primary).
	 * @param bool                             $webPushActive Whether the send also delivers over web-push (drives duplicate suppression).
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $originApp = 'openregister',
		public readonly array $actions = [],
		public readonly bool $webPushActive = false,
	) {
	}//end __construct()
}//end class
