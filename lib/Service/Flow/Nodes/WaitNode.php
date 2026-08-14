<?php

/**
 * Pauses the run until a time passes.
 *
 * The node run persistence was built for. It suspends by throwing
 * {@see FlowSuspension}; the engine leaves the marking where it is, the run is
 * stored with its items, and the queue worker wakes it once `resumeAt` is due.
 *
 * Because the marking does not advance, this node runs a SECOND time when the
 * run resumes. That is what makes it correct rather than a one-shot: on the
 * way back in it sees `context.resuming` and lets the items straight through.
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
 * @spec openspec/changes/or-flow-expressions/specs/flow-expressions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use DateTime;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use UnexpectedValueException;

/**
 * Suspends the run for a duration or until a moment.
 */
class WaitNode implements IFlowNode, IFlowNodeConfigKeys {
	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n Translations.
	 * @param IURLGenerator $urls For the palette icon.
	 */
	public function __construct(
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
		return 'openregister.wait';
	}//end getId()

	/**
	 * Palette name.
	 *
	 * @return string The display name.
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Wait');
	}//end getDisplayName()

	/**
	 * Palette description.
	 *
	 * @return string The description.
	 */
	public function getDescription(): string {
		return $this->l10n->t('Pause the flow for a while, then carry on where it left off.');
	}//end getDescription()

	/**
	 * Palette icon.
	 *
	 * @return string The icon URL.
	 */
	public function getIcon(): string {
		return $this->urls->imagePath('core', 'actions/history.svg');
	}//end getIcon()

	/**
	 * Waiting grants no privilege.
	 *
	 * @param int $scope The scope constant.
	 *
	 * @return boolean Whether it is available.
	 */
	public function isAvailableForScope(int $scope): bool {
		return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);
	}//end isAvailableForScope()

	/**
	 * The config vocabulary of a wait step.
	 *
	 * @return array<int, string> The accepted config keys.
	 *
	 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
	 */
	public function configKeys(): array {
		return ['for', 'until'];
	}//end configKeys()

	/**
	 * Reject a wait that names no time.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When neither `for` nor `until` is usable.
	 */
	public function validateConfig(array $config): void {
		$for = trim((string)($config['for'] ?? ''));
		$until = trim((string)($config['until'] ?? ''));

		if ($for === '' && $until === '') {
			throw new UnexpectedValueException($this->l10n->t('A wait needs a duration or a moment to wait until.'));
		}

		if ($this->resolve(config: $config) === null) {
			throw new UnexpectedValueException($this->l10n->t('That is not a time this flow can wait until.'));
		}

	}//end validateConfig()

	/**
	 * Suspend on the way in; pass through on the way back.
	 *
	 * @param array $items The input items.
	 * @param array $config The step configuration.
	 * @param array $context Run-level metadata.
	 *
	 * @return array The items, unchanged, once the wait is over.
	 *
	 * @throws FlowSuspension On the first pass that carries items, to pause the
	 *                        run. An EMPTY firing never suspends: there is
	 *                        nothing to wait for, and pausing on it would pause
	 *                        every other branch of the run with it.
	 */
	public function execute(array $items, array $config, array $context): array {
		if ($items === []) {
			// AN EMPTY FIRING IS NOT SOMETHING TO WAIT FOR.
			//
			// A transition can fire with no items — a gate sent every item down
			// another branch, or the branch simply had no work this pass — and
			// suspending on that pauses the WHOLE RUN, not just this branch.
			// In a flow whose branches are priorities rather than alternatives,
			// that is a live defect: hydra's sequencer routes an in-flight
			// stage to a collect branch and leaves the dispatch branch empty,
			// and the empty branch's wait suspended the run before the branch
			// carrying the item could advance. On resume the marking had moved
			// on and every remaining transition fired empty, so the item was
			// gone and the log read as a clean pass — 40 transitions, all
			// `completed`, `in=0 out=0`.
			//
			// Nothing is skipped by returning here: with no items there is no
			// work to delay, and a later pass that DOES carry items reaches
			// this node again and suspends then.
			return $items;
		}

		if (($context['resuming'] ?? false) === true) {
			// Woken by the worker: the wait is over by construction, because
			// the run was only eligible once `resumeAt` had passed.
			return $items;
		}

		$resumeAt = $this->resolve(config: $config);
		if ($resumeAt === null) {
			// Unparseable at run time (an expression produced nonsense).
			// Passing through beats suspending forever on a time that will
			// never arrive.
			return $items;
		}

		throw new FlowSuspension(
			resumeAt: $resumeAt,
			reason: sprintf('waiting until %s', $resumeAt->format('c'))
		);

	}//end execute()

	/**
	 * Work out when this wait ends.
	 *
	 * `until` is an absolute moment, `for` a relative one ("15 minutes",
	 * "2 days"). Both go through strtotime, so an author can write either the
	 * way they would say it.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return DateTime|null The resume time, or null when it cannot be read.
	 *
	 * @spec openspec/changes/or-flow-expressions/specs/flow-expressions/spec.md
	 */
	private function resolve(array $config): ?DateTime {
		$until = trim((string)($config['until'] ?? ''));
		if ($until !== '') {
			$time = strtotime($until);
			if ($time === false) {
				return null;
			}

			return (new DateTime())->setTimestamp($time);
		}

		$for = trim((string)($config['for'] ?? ''));
		if ($for === '') {
			return null;
		}

		// A bare number is read as seconds, which is what an author who typed
		// "30" almost certainly meant.
		if (ctype_digit($for) === true) {
			return (new DateTime())->setTimestamp((time() + (int)$for));
		}

		$time = strtotime('+' . ltrim($for, '+'));
		if ($time === false) {
			return null;
		}

		return (new DateTime())->setTimestamp($time);
	}//end resolve()
}//end class
