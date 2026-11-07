<?php

/**
 * The messaging steps.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener\FlowNodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener\FlowNodes;

use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\Nodes\SendEmailNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\SendNotificationNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\SendTalkMessageNodeInterface;

/**
 * The messaging steps.
 *
 * Three nodes, not one "send" with a channel picker: the three differ in
 * config shape and failure modes, so three flat forms beat one union form.
 * Deliberately NO send-webhook — outbound HTTP is OpenConnector's job
 * (ADR-094), and `activity`/`web-push` are not channels here either: activity
 * is an audit surface, web-push rides along with send-notification exactly as
 * it does declaratively.
 *
 * Listed in registration order.
 *
 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
 */
final readonly class MessagingFlowNodes {
	/**
	 * Bundle the nodes.
	 *
	 * @param SendNotificationNodeInterface $sendNotification The built-in "Send a notification" node.
	 * @param SendEmailNodeInterface $sendEmail The built-in "Send an email" node.
	 * @param SendTalkMessageNodeInterface $sendTalkMessage The built-in "Send a Talk message" node.
	 */
	public function __construct(
		private SendNotificationNodeInterface $sendNotification,
		private SendEmailNodeInterface $sendEmail,
		private SendTalkMessageNodeInterface $sendTalkMessage,
	) {
	}//end __construct()

	/**
	 * The nodes, in the order they register.
	 *
	 * @return list<IFlowNode> The nodes.
	 */
	public function nodes(): array {
		return [
			$this->sendNotification,
			$this->sendEmail,
			$this->sendTalkMessage,
		];

	}//end nodes()
}//end class
