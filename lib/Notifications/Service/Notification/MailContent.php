<?php

/**
 * MailContent
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

/**
 * What one transactional email says: its subject, its plain body and, for an
 * external recipient, the unsubscribe material its headers carry.
 *
 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
 */
final class MailContent {

	/**
	 * Constructor.
	 *
	 * @param string                    $subject     Email subject line.
	 * @param string                    $body        Email body text.
	 * @param array<string, mixed>|null $unsubscribe Unsubscribe material for the List-Unsubscribe headers; null for none.
	 */
	public function __construct(
		public readonly string $subject,
		public readonly string $body,
		public readonly ?array $unsubscribe = null,
	) {
	}//end __construct()
}//end class
