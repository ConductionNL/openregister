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
 * @spec openspec/changes/flow-messaging-nodes/specs/flow-messaging-nodes/spec.md#requirement-flow-sends-are-attributed-logged-and-bounded
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\FlowMessagingService;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use UnexpectedValueException;

/**
 * The "Send a Talk message" step.
 */
class SendTalkMessageNode implements IFlowNode, IFlowNodeConfigKeys, IFlowNodeConfigForm {

	/**
	 * The step type this node answers to.
	 */
	public const TYPE = 'openregister.send-talk-message';

	/**
	 * Constructor.
	 *
	 * @param FlowMessagingService $messaging The bridge onto the notification subsystem.
	 * @param IL10N $l10n Translations.
	 * @param IURLGenerator $urls For the palette icon.
	 */
	public function __construct(
		private readonly FlowMessagingService $messaging,
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urls,
	) {

	}//end __construct()

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
	 * Posting requires the acting user to be a participant either way, so
	 * both scopes get it; every guardrail applies regardless.
	 *
	 * @param int $scope The scope constant.
	 *
	 * @return boolean Whether it is available.
	 */
	public function isAvailableForScope(int $scope): bool {
		return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);
	}//end isAvailableForScope()

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
	 * @spec openspec/changes/flow-messaging-nodes/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
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
	 * @spec openspec/changes/flow-node-config-forms/specs/flow-node-config-forms/spec.md
	 */
	public function configForm(): array {
		return [
			[
				'key' => 'conversation',
				'label' => $this->l10n->t('Conversation'),
				'type' => 'text',
				'help' => $this->l10n->t('A conversation token, or a field on the item such as {{ conversationToken }}. The acting user must be a participant.'),
				'required' => true,
			],
			[
				'key' => 'message',
				'label' => $this->l10n->t('Message'),
				'type' => 'textarea',
				'help' => $this->l10n->t('What the message says. Placeholders such as {{ name }} read fields from the item.'),
				'required' => true,
			],
		];
	}//end configForm()

	/**
	 * Post, then pass the items through unchanged.
	 *
	 * Sending is a side effect, not a transformation. Failures — Talk absent,
	 * an unknown conversation, the acting user not a participant, a refused
	 * post — throw and are routed through the step's `onError` policy.
	 *
	 * @param array $items The input items.
	 * @param array $config The step configuration.
	 * @param array $context Run-level metadata.
	 *
	 * @return array The items, unchanged.
	 *
	 * @spec openspec/changes/flow-messaging-nodes/specs/flow-messaging-nodes/spec.md#requirement-flow-sends-are-attributed-logged-and-bounded
	 */
	public function execute(array $items, array $config, array $context): array {
		$this->messaging->sendTalkMessage(
			config: $config,
			items: $items,
			context: $context,
			stepName: self::TYPE
		);

		return $items;
	}//end execute()
}//end class
