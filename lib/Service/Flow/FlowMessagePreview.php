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

use OCA\OpenRegister\Service\Notification\NotificationTemplating;

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
	 * @param NotificationTemplating $templating Fills the subject and body.
	 */
	public function __construct(
		private readonly FlowRecipientAddresses $addresses,
		private readonly NotificationTemplating $templating,
	) {
	}//end __construct()

	/**
	 * The messages, one per item.
	 *
	 * For `talk` the recipient is the conversation and there is no subject;
	 * for `email` the subject and body are `subject` and `body`, otherwise
	 * `title` and `message`.
	 *
	 * @param string $channel `email`, `notification` or `talk`.
	 * @param array $config The step configuration.
	 * @param array $items The flow items.
	 * @param array $context The scalar run context.
	 *
	 * @return array<int, array{channel: string, to: array<int, string>, subject: string, body: string}>
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-can-say-what-it-would-do
	 */
	public function messages(string $channel, array $config, array $items, array $context): array {
		$titleKey = 'title';
		$bodyKey = 'message';
		if ($channel === 'email') {
			$titleKey = 'subject';
			$bodyKey = 'body';
		}

		$messages = [];
		foreach ($items as $item) {
			$json = (array)($item[FlowItems::JSON] ?? []);
			if ($channel === 'talk') {
				$messages[] = [
					'channel' => 'talk',
					'to' => [trim($this->fill(template: (string)($config['conversation'] ?? ''), json: $json, context: $context))],
					'subject' => '',
					'body' => $this->fill(template: (string)($config['message'] ?? ''), json: $json, context: $context),
				];
				continue;
			}

			$resolved = $this->addresses->resolveRecipients(
				recipients: ($config['recipients'] ?? []),
				json: $json,
				acceptAddresses: ($channel === 'email')
			);
			$addresses = array_map(static fn (array $address): string => $address['address'], $resolved['addresses']);
			$messages[] = [
				'channel' => $channel,
				'to' => array_values(array_merge($resolved['uids'], $addresses)),
				'subject' => $this->fill(template: (string)($config[$titleKey] ?? ''), json: $json, context: $context),
				'body' => $this->fill(template: (string)($config[$bodyKey] ?? ''), json: $json, context: $context),
			];
		}//end foreach

		return $messages;
	}//end messages()

	/**
	 * A template filled from the item and the run context.
	 *
	 * @param string $template The template.
	 * @param array $json The item's json.
	 * @param array $context The scalar run context.
	 *
	 * @return string The text.
	 */
	private function fill(string $template, array $json, array $context): string {
		return $this->templating->interpolate(template: $template, data: $json, context: $context);
	}//end fill()
}//end class
