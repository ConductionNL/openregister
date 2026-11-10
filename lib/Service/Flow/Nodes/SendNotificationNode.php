<?php

/**
 * Sends an in-app Nextcloud notification at this point in the process.
 *
 * A thin invoker of the ADR-031 notification subsystem, through
 * FlowMessagingService: the same channel sender, recipient resolver,
 * templating, rate limiter and kill switches a schema annotation uses. The
 * boundary stands — notifications notify (whenever X happens), flows
 * orchestrate (at this point in the process) — so this node accepts no
 * schema or event subscription: a node that fires on events is a trigger,
 * and triggering is already spec'd.
 *
 * Sending is a side effect, not a transformation: items pass through
 * unchanged. Web-push rides along with the nc-notification channel under the
 * dispatcher's existing rules, with no flow-side configuration.
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
 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
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
 * The "Send a notification" step.
 */
class SendNotificationNode extends MessagingNodeBase implements
	IFlowNode,
	SendNotificationNodeInterface,
	IFlowNodeConfigKeys,
	IFlowNodeConfigForm,
	IFlowNodeTaxonomy,
	IFlowNodePreview
{

	/**
	 * The step type this node answers to.
	 */
	public const TYPE = 'openregister.send-notification';

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
		return $this->l10n->t('Send a notification');
	}//end getDisplayName()

	/**
	 * Palette description.
	 *
	 * @return string The description.
	 */
	public function getDescription(): string {
		return $this->l10n->t('Send an in-app notification to people, at this point in the flow.');
	}//end getDescription()

	/**
	 * Palette icon.
	 *
	 * @return string The icon URL.
	 */
	public function getIcon(): string {
		return $this->urls->imagePath('core', 'actions/sound.svg');
	}//end getIcon()

	/**
	 * The channel this step previews on (flow dry-run).
	 *
	 * @return string The channel.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-can-say-what-it-would-do
	 */
	protected function channel(): string {
		return 'notification';
	}//end channel()

	/**
	 * The config vocabulary of a send-notification step.
	 *
	 * @return array<int, string> The accepted config keys.
	 *
	 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
	 */
	public function configKeys(): array {
		return ['recipients', 'title', 'message'];
	}//end configKeys()

	/**
	 * Reject a send with nothing to say or nobody to say it to.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the message or the recipients are empty.
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
	 */
	public function validateConfig(array $config): void {
		if (trim((string)($config['message'] ?? '')) === '') {
			throw new UnexpectedValueException($this->l10n->t('A notification needs a message.'));
		}

		$this->assertRecipients(config: $config, refusal: $this->l10n->t('A notification needs at least one recipient.'));
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
			$this->requiredField(key: 'recipients', label: $this->l10n->t('Who to tell'), type: 'text', help: $this->l10n->t(
				'User or group ids, or a field on the item such as {{ assignee }}. Groups are expanded.'
			)),
			$this->formField(key: 'title', label: $this->l10n->t('Title'), type: 'text', help: $this->l10n->t(
				'Placeholders such as {{ name }} read fields from the item, the same syntax a schema notification uses.'
			)),
			$this->requiredField(key: 'message', label: $this->l10n->t('Message'), type: 'textarea', help: $this->l10n->t(
				'What the notification says. Placeholders read fields from the item.'
			)),
		];
	}//end configForm()

	/**
	 * Send through the notification subsystem; the base passes the items through unchanged.
	 *
	 * Sending is a side effect, not a transformation. Failures throw and are
	 * routed through the step's `onError` policy; every non-delivery lands in
	 * the run log with its reason.
	 *
	 * @param MessageSend $step The step configuration, the items, run-level metadata and this node's type.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
	 */
	protected function send(MessageSend $step): void {
		$this->messaging->notify(request: $step);
	}//end send()
}//end class
