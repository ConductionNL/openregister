<?php

/**
 * An entry point: another system starts this flow with a signed call.
 *
 * Saving the node gives it a random address token (`POST
 * /api/flows/hooks/{token}`) and a signing secret, which
 * {@see \OCA\OpenRegister\Service\Flow\FlowWebhookSecrets} stores encrypted,
 * outside the flow, and the save answer carries once. The node itself names
 * only the account its runs act as.
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
 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#2-incoming-webhook-trigger
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm;
use OCP\IUserManager;

/**
 * The incoming-webhook trigger node.
 *
 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#2-incoming-webhook-trigger
 */
class TriggerWebhookNode extends TriggerNodeBase implements TriggerWebhookNodeInterface, IFlowNodeConfigForm {

	/**
	 * The node type.
	 *
	 * @var string
	 */
	public const TYPE = 'openregister.trigger-webhook';

	/**
	 * Constructor.
	 *
	 * @param FlowNodePresentation $presentation Translations and image paths.
	 * @param IUserManager         $userManager  Checks the acting identity exists.
	 */
	public function __construct(
		FlowNodePresentation $presentation,
		private readonly IUserManager $userManager,
	) {
		parent::__construct(l10n: $presentation->l10n, urls: $presentation->urls);
	}//end __construct()

	/**
	 * The node type.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#2-incoming-webhook-trigger
	 */
	public function getId(): string {
		return self::TYPE;
	}//end getId()

	/**
	 * The name in the palette.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#2-incoming-webhook-trigger
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('When another system calls');
	}//end getDisplayName()

	/**
	 * What the node does.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#2-incoming-webhook-trigger
	 */
	public function getDescription(): string {
		return $this->l10n->t(
			'Start the flow when another system posts a signed call to the flow\'s own address. '
				. 'The call\'s JSON body is the run\'s input.'
		);
	}//end getDescription()

	/**
	 * The palette icon.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#2-incoming-webhook-trigger
	 */
	public function getIcon(): string {
		return $this->urls->imagePath('core', 'actions/share.svg');
	}//end getIcon()

	/**
	 * The config keys this node reads; `token` is server-written.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#2-incoming-webhook-trigger
	 */
	public function configKeys(): array {
		return ['runAs', 'token', 'rotateSecret'];
	}//end configKeys()

	/**
	 * The config form.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#2-incoming-webhook-trigger
	 */
	public function configForm(): array {
		return [
			[
				'key' => 'runAs',
				'label' => $this->l10n->t('Run as user'),
				'type' => 'text',
				'help' => $this->l10n->t('The account the runs act as. The caller has no account here, and the flow\'s author is never used in its place.'),
				'required' => true,
			],
			[
				'key' => 'rotateSecret',
				'label' => $this->l10n->t('Rotate the signing secret on save'),
				'type' => 'boolean',
				'help' => $this->l10n->t('A new secret is shown once; calls signed with the old one are refused straight away.'),
			],
		];
	}//end configForm()

	/**
	 * Refuse a config that could never run as written.
	 *
	 * @param array<string, mixed> $config The node's config.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When it names no existing acting identity.
	 *
	 * @spec openspec/changes/flow-calendar-and-webhook-triggers/specs/flow-engine/spec.md#requirement-an-incoming-webhook-trigger-starts-a-flow-from-a-signed-call-by-another-system
	 */
	public function validateConfig(array $config): void {
		$runAs = trim((string)($config['runAs'] ?? ''));
		if ($runAs === '') {
			throw new InvalidArgumentException(
				'An incoming-webhook trigger must carry a "runAs" naming the user its runs act as. The caller has no account here, '
				. 'and the flow\'s owner is not used as a fallback.'
			);
		}

		if ($this->userManager->get($runAs) === null) {
			throw new InvalidArgumentException(
				sprintf('The incoming-webhook trigger\'s "runAs" names "%s", which is not an existing user account.', $runAs)
			);
		}
	}//end validateConfig()
}//end class
