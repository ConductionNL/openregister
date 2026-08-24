<?php

/**
 * An entry point: this flow starts on a schedule.
 *
 * The scheduling half of {@see TriggerObjectNode} — see that class for why a
 * trigger is a node at all, and why a flow may carry several.
 *
 * A schedule trigger has no SUBJECT. There is no object that caused it, so a
 * run started here begins with no item to work on and the flow's first real
 * node has to fetch what it needs. That is the difference an author has to see,
 * and it is why the register/schema pair belongs to the object trigger rather
 * than to the flow: on a schedule those two fields have nothing to describe,
 * and offering them invites an author to fill them in and expect a filter.
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
 * @spec openspec/specs/flow-engine/spec.md#requirement-a-trigger-is-a-node-and-a-flow-may-carry-several
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCA\OpenRegister\Service\Flow\IFlowTriggerNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\WorkflowEngine\IManager;

/**
 * Starts the flow on a cron schedule.
 */
class TriggerScheduleNode implements IFlowNode, IFlowNodeConfigKeys, IFlowTriggerNode {
	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n Translations.
	 * @param IURLGenerator $urls For the palette icon.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-trigger-is-a-node-and-a-flow-may-carry-several
	 */
	public function __construct(
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urls,
		private readonly IUserManager $userManager,
	) {

	}//end __construct()

	/**
	 * The node type.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-trigger-is-a-node-and-a-flow-may-carry-several
	 */
	public function getId(): string {
		return 'openregister.trigger-schedule';
	}//end getId()

	/**
	 * Palette name.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-trigger-is-a-node-and-a-flow-may-carry-several
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('On a schedule');
	}//end getDisplayName()

	/**
	 * Palette description.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-trigger-is-a-node-and-a-flow-may-carry-several
	 */
	public function getDescription(): string {
		return $this->l10n->t('Start the flow on a cron schedule. The run begins with no object — the first node has to fetch what it works on.');
	}//end getDescription()

	/**
	 * Palette icon.
	 *
	 * @return string The icon URL.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-trigger-is-a-node-and-a-flow-may-carry-several
	 */
	public function getIcon(): string {
		return $this->urls->imagePath('core', 'actions/history.svg');
	}//end getIcon()

	/**
	 * Starting a flow grants no privilege of its own.
	 *
	 * @param int $scope The scope constant.
	 *
	 * @return boolean Whether it is available.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-trigger-is-a-node-and-a-flow-may-carry-several
	 */
	public function isAvailableForScope(int $scope): bool {
		return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);
	}//end isAvailableForScope()

	/**
	 * The config vocabulary of a schedule trigger.
	 *
	 * @return array<int, string> The accepted config keys.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-trigger-is-a-node-and-a-flow-may-carry-several
	 */
	public function configKeys(): array {
		return ['cron', 'runAs'];
	}//end configKeys()

	/**
	 * The expression is required, and must have five fields.
	 *
	 * The shape is checked, not the semantics: a five-field expression that
	 * never matches a real minute is a scheduling question, and this node is
	 * not the scheduler. What it can catch is the expression that is not a cron
	 * expression at all — which otherwise sits in a saved flow that simply
	 * never runs.
	 *
	 * @param array $config The node configuration.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the cron expression is missing or malformed.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-trigger-is-a-node-and-a-flow-may-carry-several
	 */
	public function validateConfig(array $config): void {
		$cron = trim((string)($config['cron'] ?? ''));
		if ($cron === '') {
			throw new InvalidArgumentException(
				'A schedule trigger must carry a "cron" expression — without one nothing ever starts it, '
				. 'which is indistinguishable from a flow with nothing to do.'
			);
		}

		$fields = preg_split('/\s+/', $cron);
		if (is_array($fields) === false || count($fields) !== 5) {
			$fieldCount = 0;
			if (is_array($fields) === true) {
				$fieldCount = count($fields);
			}

			throw new InvalidArgumentException(
				sprintf(
					'A cron expression has five space-separated fields (minute hour day month weekday); "%s" has %d.',
					$cron,
					$fieldCount
				)
			);
		}

		$this->validateActingIdentity(config: $config);

	}//end validateConfig()

	/**
	 * A schedule trigger must name the user its runs execute as.
	 *
	 * Every other entry point is handed an identity by its caller — a manual run
	 * has the session user, an object event has the user whose action raised it,
	 * a sub-flow has its parent's. A schedule has nobody by construction, which
	 * is exactly why it is the one trigger that has to say.
	 *
	 * 🔴 Refusing at SAVE is a convenience, not the control. The identity is
	 * re-resolved at every firing, so a user disabled after this passed still
	 * fails the run closed. What saving-time refusal buys is that the author
	 * learns now, in the editor, instead of at 03:00 in cron — where the failure
	 * is someone else's pager and reads as a permissions problem.
	 *
	 * The previous behaviour was to resolve `flow.owner` implicitly at fire time.
	 * That turned authoring a flow into standing consent to unattended execution
	 * as the author, under whatever triggers anyone later added (ADR-099).
	 *
	 * @param array $config The node configuration.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When `runAs` is absent or names nobody.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private function validateActingIdentity(array $config): void {
		$runAs = trim((string)($config['runAs'] ?? ''));

		if ($runAs === '') {
			throw new InvalidArgumentException(
				'A schedule trigger must carry a "runAs" naming the user its runs act as. '
				. 'Nobody is present when a schedule fires, so there is no session to take an identity from, '
				. 'and the flow\'s owner is not used as a fallback — authoring a flow is not consent to '
				. 'unattended execution as its author.'
			);
		}

		if ($this->userManager->get($runAs) === null) {
			throw new InvalidArgumentException(
				sprintf(
					'The schedule trigger\'s "runAs" names "%s", which is not an existing user account. '
					. 'A run that cannot resolve its acting identity is refused rather than started.',
					$runAs
				)
			);
		}

	}//end validateActingIdentity()

	/**
	 * A trigger is an entry point, not work.
	 *
	 * @param array $items The input items.
	 * @param array $config The node configuration.
	 * @param array $context Run-level metadata.
	 *
	 * @return array The items, unchanged.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-trigger-is-a-node-and-a-flow-may-carry-several
	 */
	public function execute(array $items, array $config, array $context): array {
		return $items;
	}//end execute()
}//end class
