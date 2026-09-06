<?php

/**
 * Sends an email at this point in the process.
 *
 * A thin invoker of the ADR-031 notification subsystem, through
 * FlowMessagingService: the same mail composition/handoff, recipient
 * resolver, templating, rate limiter and kill switches a schema annotation's
 * email channel uses. No second mailer exists on the flow side.
 *
 * Sending is a side effect, not a transformation: items pass through
 * unchanged. A bounced handoff is a step failure routed through `onError` —
 * a COMPLETED run never hides an undelivered message.
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
 * @spec openspec/changes/flow-messaging-nodes/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
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
 * The "Send an email" step.
 */
class SendEmailNode implements IFlowNode, IFlowNodeConfigKeys, IFlowNodeConfigForm {

	/**
	 * The step type this node answers to.
	 */
	public const TYPE = 'openregister.send-email';

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
		return $this->l10n->t('Send an email');
	}//end getDisplayName()

	/**
	 * Palette description.
	 *
	 * @return string The description.
	 */
	public function getDescription(): string {
		return $this->l10n->t('Send an email to people, at this point in the flow.');
	}//end getDescription()

	/**
	 * Palette icon.
	 *
	 * @return string The icon URL.
	 */
	public function getIcon(): string {
		return $this->urls->imagePath('core', 'actions/mail.svg');
	}//end getIcon()

	/**
	 * Messaging people is not privileged beyond the run's own identity, so
	 * both scopes get it; every guardrail applies either way.
	 *
	 * @param int $scope The scope constant.
	 *
	 * @return boolean Whether it is available.
	 */
	public function isAvailableForScope(int $scope): bool {
		return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);
	}//end isAvailableForScope()

	/**
	 * The config vocabulary of a send-email step.
	 *
	 * @return array<int, string> The accepted config keys.
	 *
	 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
	 */
	public function configKeys(): array {
		return ['recipients', 'subject', 'body'];
	}//end configKeys()

	/**
	 * Reject a mail with nothing to say or nobody to send it to.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the body or the recipients are empty.
	 *
	 * @spec openspec/changes/flow-messaging-nodes/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
	 */
	public function validateConfig(array $config): void {
		if (trim((string)($config['body'] ?? '')) === '') {
			throw new UnexpectedValueException($this->l10n->t('An email needs a body.'));
		}

		$recipients = ($config['recipients'] ?? []);
		if (is_string($recipients) === true) {
			$recipients = [$recipients];
		}

		$recipients = array_filter(
			(array)$recipients,
			static fn (mixed $entry): bool => is_string($entry) === true && trim($entry) !== ''
		);
		if ($recipients === []) {
			throw new UnexpectedValueException($this->l10n->t('An email needs at least one recipient.'));
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
			[
				'key' => 'recipients',
				'label' => $this->l10n->t('Who to mail'),
				'type' => 'text',
				'help' => $this->l10n->t('User or group ids, or a field on the item such as {{ assignee }}. Groups are expanded.'),
				'required' => true,
			],
			[
				'key' => 'subject',
				'label' => $this->l10n->t('Subject'),
				'type' => 'text',
				'help' => $this->l10n->t('Placeholders such as {{ name }} read fields from the item, the same syntax a schema notification uses.'),
			],
			[
				'key' => 'body',
				'label' => $this->l10n->t('Body'),
				'type' => 'textarea',
				'help' => $this->l10n->t('What the email says. Placeholders read fields from the item.'),
				'required' => true,
			],
		];
	}//end configForm()

	/**
	 * Send, then pass the items through unchanged.
	 *
	 * Sending is a side effect, not a transformation. Failures throw and are
	 * routed through the step's `onError` policy; every non-delivery lands in
	 * the run log with its reason.
	 *
	 * @param array $items The input items.
	 * @param array $config The step configuration.
	 * @param array $context Run-level metadata.
	 *
	 * @return array The items, unchanged.
	 *
	 * @spec openspec/changes/flow-messaging-nodes/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
	 */
	public function execute(array $items, array $config, array $context): array {
		$this->messaging->sendEmail(
			config: $config,
			items: $items,
			context: $context,
			stepName: self::TYPE
		);

		return $items;
	}//end execute()
}//end class
