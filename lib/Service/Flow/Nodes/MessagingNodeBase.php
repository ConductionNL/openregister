<?php

/**
 * What the three messaging flow nodes have in common.
 *
 * "Send an email", "Send a notification" and "Send a Talk message" differ in
 * their palette text, their config vocabulary and the channel they hand to
 * FlowMessagingService. Everything else — who builds them, which scopes may
 * use them, how a dry run previews them, that a send passes its items through
 * unchanged, and their BPMN kind and palette category — is one behaviour, kept
 * here once so the three cannot drift apart.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\FlowMessagingService;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\MessageSend;
use OCA\OpenRegister\Service\Flow\IFlowNodeTaxonomy;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use UnexpectedValueException;

/**
 * The shared half of a messaging step node.
 *
 * @spec openspec/specs/flow-messaging-nodes/spec.md
 */
abstract class MessagingNodeBase {
	/**
	 * Translations for palette text and refusals.
	 *
	 * @var IL10N
	 */
	protected readonly IL10N $l10n;

	/**
	 * For the palette icon.
	 *
	 * @var IURLGenerator
	 */
	protected readonly IURLGenerator $urls;

	/**
	 * Constructor.
	 *
	 * @param FlowMessagingService $messaging    The shared send pipeline.
	 * @param FlowNodePresentation $presentation Translations and the palette icon.
	 */
	public function __construct(
		protected readonly FlowMessagingService $messaging,
		FlowNodePresentation $presentation,
	) {
		$this->l10n = $presentation->l10n;
		$this->urls = $presentation->urls;
	}//end __construct()

	/**
	 * The palette name, used in the dry-run summary.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md
	 */
	abstract public function getDisplayName(): string;

	/**
	 * The channel FlowMessagingService previews this step on.
	 *
	 * @return string The channel: `email`, `notification` or `talk`.
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md
	 */
	abstract protected function channel(): string;

	/**
	 * Hand the items to FlowMessagingService on this node's channel.
	 *
	 * @param MessageSend $step The step configuration, the items to send for, run-level metadata and the step name.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md
	 */
	abstract protected function send(MessageSend $step): void;

	/**
	 * The node type, which names the step in what it sends.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md
	 */
	abstract public function getId(): string;

	/**
	 * Available in both workflow scopes.
	 *
	 * @param int $scope The scope constant.
	 *
	 * @return bool Whether the node is available.
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md
	 */
	public function isAvailableForScope(int $scope): bool {
		return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);
	}//end isAvailableForScope()

	/**
	 * What a dry run would send, without sending it.
	 *
	 * @param FlowNodeCall $call The input items, the step configuration and the run context.
	 *
	 * @return array{summary: string, messages: array} The preview.
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md
	 */
	public function preview(FlowNodeCall $call): array {
		$items = $call->items;
		$config = $call->config;
		$context = $call->context;
		return $this->previewOf(
			messages: $this->messaging->previewMessages(channel: $this->channel(), config: $config, items: $items, context: $context)
		);
	}//end preview()

	/**
	 * The dry-run summary of the messages a run would send.
	 *
	 * @param array $messages The messages a real run would send.
	 *
	 * @return array{summary: string, messages: array} The preview.
	 */
	private function previewOf(array $messages): array {
		return [
			'summary' => sprintf('%s would send %d message(s)', $this->getDisplayName(), count($messages)),
			'messages' => $messages,
		];
	}//end previewOf()

	/**
	 * Send, then pass the items through unchanged.
	 *
	 * @param FlowNodeCall $call The input items, the step configuration and the run context.
	 *
	 * @return array The same items.
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md
	 */
	public function execute(FlowNodeCall $call): array {
		$items = $call->items;
		$config = $call->config;
		$context = $call->context;
		$this->send(step: new MessageSend(config: $config, items: $items, context: $context, stepName: $this->getId()));

		return $items;
	}//end execute()

	/**
	 * Refuse a config whose `recipients` names nobody.
	 *
	 * A single string counts as one recipient; entries that are not
	 * non-blank strings are ignored.
	 *
	 * @param array  $config  The step configuration.
	 * @param string $refusal The translated refusal to throw.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When no recipient is named.
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md
	 */
	protected function assertRecipients(array $config, string $refusal): void {
		$recipients = ($config['recipients'] ?? []);
		if (is_string($recipients) === true) {
			$recipients = [$recipients];
		}

		$recipients = array_filter(
			(array)$recipients,
			static fn (mixed $entry): bool => is_string($entry) === true && trim($entry) !== ''
		);
		if ($recipients === []) {
			throw new UnexpectedValueException($refusal);
		}
	}//end assertRecipients()

	/**
	 * One optional field of the config form.
	 *
	 * @param string $key   The config key the field writes.
	 * @param string $label The translated label.
	 * @param string $type  The input type: `text` or `textarea`.
	 * @param string $help  The translated help text.
	 *
	 * @return array{key: string, label: string, type: string, help: string} The field.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-type-declares-its-own-form-and-its-own-run-log-actions
	 */
	protected function formField(string $key, string $label, string $type, string $help): array {
		return ['key' => $key, 'label' => $label, 'type' => $type, 'help' => $help];
	}//end formField()

	/**
	 * One required field of the config form.
	 *
	 * @param string $key   The config key the field writes.
	 * @param string $label The translated label.
	 * @param string $type  The input type: `text` or `textarea`.
	 * @param string $help  The translated help text.
	 *
	 * @return array{key: string, label: string, type: string, help: string, required: true} The field.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-type-declares-its-own-form-and-its-own-run-log-actions
	 */
	protected function requiredField(string $key, string $label, string $type, string $help): array {
		return $this->formField(key: $key, label: $label, type: $type, help: $help) + ['required' => true];
	}//end requiredField()

	/**
	 * What kind of step this is: it sends a message.
	 *
	 * @return string The BPMN kind.
	 *
	 * @spec openspec/changes/flow-node-taxonomy/specs/flow-node-taxonomy/spec.md#requirement-a-node-declares-a-semantic-kind-drawn-from-bpmn
	 */
	public function getKind(): string {
		return IFlowNodeTaxonomy::KIND_SEND_TASK;
	}//end getKind()

	/**
	 * Where an author should look for this step.
	 *
	 * @return string The palette category.
	 *
	 * @spec openspec/changes/flow-node-taxonomy/specs/flow-node-taxonomy/spec.md#requirement-a-node-declares-a-palette-category-independent-of-its-kind
	 */
	public function getCategory(): string {
		return IFlowNodeTaxonomy::CATEGORY_MESSAGING;
	}//end getCategory()
}//end class
