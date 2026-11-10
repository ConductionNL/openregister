<?php

/**
 * What a notice says.
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
 * What a notice says: the canonical subject key the notifier renders, the
 * pre-interpolated subject and body, and the trigger parameters.
 *
 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
 */
final class NoticeContent {
	/**
	 * Constructor.
	 *
	 * @param string               $subjectKey Canonical subject identifier (object_created/_updated/_transitioned, flow_message).
	 * @param string               $subject Pre-interpolated subject text (title).
	 * @param string               $message Pre-interpolated body text; may be empty.
	 * @param array<string, mixed> $context Trigger context (action, from, to).
	 */
	public function __construct(
		public readonly string $subjectKey,
		public readonly string $subject,
		public readonly string $message = '',
		public readonly array $context = [],
	) {
	}//end __construct()
}//end class
