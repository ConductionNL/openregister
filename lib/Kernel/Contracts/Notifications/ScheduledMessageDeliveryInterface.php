<?php
/**
 * ScheduledMessageDeliveryInterface: hands one claimed scheduled message to its messaging source.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Notifications
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Notifications;

/**
 * The delivery behind the scheduled message sweep.
 *
 * The sweep (Notifications) claims a due message and asks for it to be sent
 * once; Integrations sends it through the message's messaging source and binds
 * its answer in its own wiring.
 *
 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-the-scheduled-sweep-is-bounded-and-sends-once
 */
interface ScheduledMessageDeliveryInterface {

	/**
	 * Send one claimed scheduled message through its source.
	 *
	 * @param array<string, mixed> $message The stored message: its `source`, `path`, `body` and `headers`.
	 *
	 * @return array<string, mixed> `{ status: 'sent', response }` on success, or `{ unavailable: true, cause }`.
	 *
	 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-the-scheduled-sweep-is-bounded-and-sends-once
	 */
	public function deliver(array $message): array;
}//end interface
