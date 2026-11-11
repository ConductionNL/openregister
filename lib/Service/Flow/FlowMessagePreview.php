<?php

/**
 * Composes the messages a send step would send, without sending them.
 *
 * Used by a flow dry-run: per item, the recipients the step resolves and the
 * subject and body its templates give, through the same recipient resolution
 * and templating a real send uses. It spends no rate budget, delivers nothing
 * and writes no report.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-can-say-what-it-would-do
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Notifications\Service\Notification\NotificationTemplatingInterface;
use OCA\OpenRegister\Notifications\Service\Notification\PlaceholderValues;

/**
 * Composes a send step's messages for a preview.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
class FlowMessagePreview {
	/**
	 * Constructor.
	 *
	 * @param FlowRecipientAddresses $addresses  Resolves the recipients.
	 * @param NotificationTemplatingInterface $templating Fills the subject and body.
	 */
	public function __construct(
		private readonly FlowRecipientAddresses $addresses,
		private readonly NotificationTemplatingInterface $templating,
	) {
	}//end __construct()

	/**
	 * The messages, one per item.
	 *
	 * For `talk` the recipient is the conversation and there is no subject;
	 * for `email` the subject and body are `subject` and `body`, otherwise
	 * `title` and `message`.
	 *
	 * @param string      $channel `email`, `notification` or `talk`.
	 * @param MessageSend $step    The step configuration, the flow items and the scalar run context.
	 *
	 * @return array<int, array{channel: string, to: array<int, string>, subject: string, body: string}>
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-can-say-what-it-would-do
	 */
	public function messages(string $channel, MessageSend $step): array {
		$messages = [];
		foreach ($step->items as $item) {
			$json = (array)($item[FlowItems::JSON] ?? []);
			if ($channel === 'talk') {
				$messages[] = $this->talkMessage(step: $step, json: $json);
				continue;
			}

			$messages[] = $this->sentMessage(channel: $channel, one: new MessageSend(config: $step->config, items: [$json], context: $step->context));
		}

		return $messages;
	}//end messages()

	/**
	 * One item's Talk post: the conversation is the recipient, there is no subject.
	 *
	 * @param MessageSend $step The step.
	 * @param array       $json The item's json.
	 *
	 * @return array{channel: string, to: array<int, string>, subject: string, body: string} The message.
	 */
	private function talkMessage(MessageSend $step, array $json): array {
		$values       = new PlaceholderValues(data: $json, context: $step->context);
		$conversation = $this->templating->render(template: (string)($step->config['conversation'] ?? ''), values: $values);

		return [
			'channel' => 'talk',
			'to' => [trim($conversation)],
			'subject' => '',
			'body' => $this->templating->render(template: (string)($step->config['message'] ?? ''), values: $values),
		];
	}//end talkMessage()

	/**
	 * One item's notification or email: its resolved recipients, subject and body.
	 *
	 * @param string      $channel `email` or `notification`.
	 * @param MessageSend $one     The step, narrowed to the one item's json.
	 *
	 * @return array{channel: string, to: array<int, string>, subject: string, body: string} The message.
	 */
	private function sentMessage(string $channel, MessageSend $one): array {
		$json = (array)$one->items[0];
		$keys = ['title', 'message'];
		if ($channel === 'email') {
			$keys = ['subject', 'body'];
		}

		$resolved = $this->addresses->resolveAll(
			recipients: ($one->config['recipients'] ?? []),
			lookup: new RecipientLookup(json: $json, acceptAddresses: ($channel === 'email'))
		);
		$addresses = array_map(static fn (array $address): string => $address['address'], $resolved['addresses']);
		$values    = new PlaceholderValues(data: $json, context: $one->context);

		return [
			'channel' => $channel,
			'to' => array_values(array_merge($resolved['uids'], $addresses)),
			'subject' => $this->templating->render(template: (string)($one->config[$keys[0]] ?? ''), values: $values),
			'body' => $this->templating->render(template: (string)($one->config[$keys[1]] ?? ''), values: $values),
		];
	}//end sentMessage()
}//end class
