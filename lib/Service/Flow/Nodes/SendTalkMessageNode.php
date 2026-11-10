<?php

/**
 * Posts a Talk chat message at this point in the process.
 *
 * A thin invoker of the ADR-031 notification subsystem's Talk send unit,
 * through FlowMessagingService. The message is attributed to the flow run's
 * ACTING user — replies have an addressee, audits have an actor — which
 * requires that user to be a participant of the target conversation. "Not a
 * participant" is a step failure with that reason; the node NEVER auto-joins
 * a user into a conversation, which would be a privacy-relevant side effect
 * performed by a messaging convenience.
 *
 * Sending is a side effect, not a transformation: items pass through
 * unchanged.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flow-sends-are-attributed-logged-and-bounded
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\MessageSend;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCA\OpenRegister\Service\Flow\IFlowNodePreview;
use OCA\OpenRegister\Service\Flow\IFlowNodeTaxonomy;
use UnexpectedValueException;

/**
 * The "Send a Talk message" step.
 */
class SendTalkMessageNode extends MessagingNodeBase implements
	IFlowNode,
	SendTalkMessageNodeInterface,
	IFlowNodeConfigKeys,
	IFlowNodeConfigForm,
	IFlowNodeTaxonomy,
	IFlowNodePreview
{

	/**
	 * The step type this node answers to.
	 */
	public const TYPE = 'openregister.send-talk-message';

	/**
	 * The step type.
	 *
	 * @return string The id.
	 */
	public function getId(): string {
		return self::TYPE;
	}//end getId()

	/**
	 * Palette name.
	 *
	 * @return string The display name.
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Send a Talk message');
	}//end getDisplayName()

	/**
	 * Palette description.
	 *
	 * @return string The description.
	 */
	public function getDescription(): string {
		return $this->l10n->t('Post a chat message to a Talk conversation, as the user this flow runs as.');
	}//end getDescription()

	/**
	 * Palette icon.
	 *
	 * @return string The icon URL.
	 */
	public function getIcon(): string {
		return $this->urls->imagePath('core', 'actions/comment.svg');
	}//end getIcon()

	/**
	 * The channel this step previews on (flow dry-run).
	 *
	 * @return string The channel.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-can-say-what-it-would-do
	 */
	protected function channel(): string {
		return 'talk';
	}//end channel()

	/**
	 * The config vocabulary of a send-talk-message step.
	 *
	 * @return array<int, string> The accepted config keys.
	 *
	 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
	 */
	public function configKeys(): array {
		return ['conversation', 'message'];
	}//end configKeys()

	/**
	 * Reject a post with nothing to say or no conversation to say it in.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the message or the conversation is empty.
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
	 */
	public function validateConfig(array $config): void {
		if (trim((string)($config['message'] ?? '')) === '') {
			throw new UnexpectedValueException($this->l10n->t('A Talk message needs a message.'));
		}

		if (trim((string)($config['conversation'] ?? '')) === '') {
			throw new UnexpectedValueException($this->l10n->t('A Talk message needs a conversation token, or a field on the item that holds one.'));
		}
	}//end validateConfig()

	/**
	 * The fields this node's configuration is edited through.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-type-declares-its-own-form-and-its-own-run-log-actions
	 */
	public function configForm(): array {
		return [
			$this->requiredField(field: new NodeFormField(
				key: 'conversation', label: $this->l10n->t('Conversation'), type: 'text', help: $this->l10n->t(
				'A conversation token, or a field on the item such as {{ conversationToken }}. The acting user must be a participant.'
			))),
			$this->requiredField(field: new NodeFormField(key: 'message', label: $this->l10n->t('Message'), type: 'textarea', help: $this->l10n->t(
				'What the message says. Placeholders such as {{ name }} read fields from the item.'
			))),
		];
	}//end configForm()

	/**
	 * Post, then pass the items through unchanged.
	 *
	 * Sending is a side effect, not a transformation. Failures — Talk absent,
	 * an unknown conversation, the acting user not a participant, a refused
	 * post — throw and are routed through the step's `onError` policy.
	 *
	 * @param MessageSend $step The step configuration, the items, run-level metadata and this node's type.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flow-sends-are-attributed-logged-and-bounded
	 */
	protected function send(MessageSend $step): void {
		$this->messaging->talk(request: $step);
	}//end send()
}//end class
