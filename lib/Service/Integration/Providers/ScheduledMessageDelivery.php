<?php
/**
 * ScheduledMessageDelivery: sends a claimed scheduled message through the message dispatcher.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

use OCA\OpenRegister\Kernel\Contracts\Notifications\ScheduledMessageDeliveryInterface;

/**
 * Answers the Notifications delivery port with one outbound message.
 *
 * The stored row's source, path, body and headers become the dispatch call
 * the sweep used to make itself; a throw from the dispatcher reaches the
 * sweep unchanged.
 *
 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-the-scheduled-sweep-is-bounded-and-sends-once
 */
final class ScheduledMessageDelivery implements ScheduledMessageDeliveryInterface {

	/**
	 * Constructor.
	 *
	 * @param MessageDispatcherInterface $dispatcher Sends through a messaging source.
	 */
	public function __construct(
		private readonly MessageDispatcherInterface $dispatcher,
	) {
	}//end __construct()

	/**
	 * Send one claimed scheduled message through its source.
	 *
	 * @param array<string, mixed> $message The stored message.
	 *
	 * @return array<string, mixed> The dispatcher's answer.
	 *
	 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-the-scheduled-sweep-is-bounded-and-sends-once
	 */
	public function deliver(array $message): array {
		return $this->dispatcher->dispatch(
			source: (string)($message['source'] ?? ''),
			message: new OutboundMessage(
				path: (string)($message['path'] ?? ''),
				body: (array)($message['body'] ?? []),
				headers: (array)($message['headers'] ?? [])
			)
		);
	}//end deliver()
}//end class
