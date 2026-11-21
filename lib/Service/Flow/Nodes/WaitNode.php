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
 * way back in it sees its OWN resume slot held and lets the items straight
 * through. Its own slot, not `context.resuming` — the run-wide flag is true
 * for every node of a resumed walk, so a SECOND wait node reached later in
 * the same walk would read it as "my wait is over" and pass through without
 * ever having waited. The slot marks exactly the node that suspended.
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
 * @spec openspec/specs/flow-expressions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use DateTime;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCA\OpenRegister\Service\Flow\IFlowNodeTaxonomy;
use UnexpectedValueException;

/**
 * Suspends the run for a duration or until a moment.
 */
class WaitNode extends LogicNodeBase implements IFlowNode, WaitNodeInterface, IFlowNodeConfigKeys, IFlowNodeConfigForm, IFlowNodeTaxonomy {
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
	 * The config vocabulary of a wait step.
	 *
	 * @return array<int, string> The accepted config keys.
	 *
	 * @spec openspec/specs/flow-preflight/spec.md
	 */
	public function configKeys(): array {
		return ['for', 'until'];
	}//end configKeys()

	/**
	 * How long to wait, or until when.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-type-declares-its-own-form-and-its-own-run-log-actions
	 */
	public function configForm(): array {
		return [
			[
				'key' => 'for',
				'label' => $this->l10n->t('Wait for'),
				'type' => 'text',
				'help' => $this->l10n->t('A duration like "30 minutes" or "2 days"; a bare number is seconds. Fill this or "Wait until".'),
			],
			[
				'key' => 'until',
				'label' => $this->l10n->t('Wait until'),
				'type' => 'text',
				'help' => $this->l10n->t('A moment like "2027-01-31 09:00" or "next monday 09:00". When both are set, this one wins.'),
			],
		];
	}//end configForm()

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
	 * AN EMPTY FIRING IS NOT SOMETHING TO WAIT FOR. A transition can fire with
	 * no items — a gate sent every item down another branch, or the branch
	 * simply had no work this pass — and suspending on that pauses the WHOLE
	 * RUN, not just this branch. In a flow whose branches are priorities
	 * rather than alternatives, that is a live defect: hydra's sequencer routes
	 * an in-flight stage to a collect branch and leaves the dispatch branch
	 * empty, and the empty branch's wait suspended the run before the branch
	 * carrying the item could advance. On resume the marking had moved on and
	 * every remaining transition fired empty, so the item was gone and the log
	 * read as a clean pass — 40 transitions, all `completed`, `in=0 out=0`.
	 * Nothing is skipped by passing through: with no items there is no work
	 * to delay, and a later pass that DOES carry items reaches this node again
	 * and suspends then.
	 *
	 * A time that cannot be read at run time (an expression produced
	 * nonsense) also passes through: that beats suspending forever on a time
	 * that will never arrive.
	 *
	 * @param FlowNodeCall $call The input items, the step configuration and the run context.
	 *
	 * @return array The items, unchanged, once the wait is over.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-suspending-is-a-run-level-act-so-an-empty-firing-must-not-suspend
	 *
	 * @throws FlowSuspension On the first pass that carries items, to pause the
	 *                        run. An EMPTY firing never suspends: there is
	 *                        nothing to wait for, and pausing on it would pause
	 *                        every other branch of the run with it.
	 */
	public function execute(FlowNodeCall $call): array {
		$items = $call->items;
		$config = $call->config;
		$context = $call->context;
		if ($items === [] || $this->waitIsOver(context: $context) === true) {
			return $items;
		}

		$this->suspendUntil(context: $context, resumeAt: $this->resolve(config: $config));

		return $items;
	}//end execute()

	/**
	 * Suspend the run until the resolved moment; a step with no moment passes through.
	 *
	 * @param array         $context  The run context.
	 * @param DateTime|null $resumeAt When to resume, or null when the step does not wait.
	 *
	 * @return void
	 *
	 * @throws FlowSuspension When the run must wait.
	 *
	 * @spec openspec/changes/or-flow-wait-node/specs/flow-wait-node/spec.md
	 */
	private function suspendUntil(array $context, ?DateTime $resumeAt): void {
		if ($resumeAt === null) {
			return;
		}

		$this->markWaiting(context: $context, resumeAt: $resumeAt);

		throw new FlowSuspension(resumeAt: $resumeAt, reason: sprintf('waiting until %s', $resumeAt->format('c')));
	}//end suspendUntil()

	/**
	 * Whether this run is re-entering THIS wait after it has ended.
	 *
	 * With a resume slot, only this node's own slot answers: it wrote the slot
	 * when it suspended, and the run was only eligible again once its
	 * `resumeAt` had passed. The dispatcher clears the slot on return, so a
	 * loop back into this node waits again.
	 *
	 * With no slot machinery at all — a context built outside a real run (the
	 * flow tester, a node unit test) — there is only one wait in such a walk,
	 * so the run-wide `resuming` flag is unambiguous there.
	 *
	 * @param array $context Run-level metadata.
	 *
	 * @return boolean True when the wait is over.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-must-be-able-to-resume-from-where-it-stopped
	 */
	private function waitIsOver(array $context): bool {
		$resume = ($context[FlowNodeResumeState::CONTEXT_KEY] ?? null);
		if ($resume instanceof FlowNodeResumeState === true) {
			return $resume->isResuming();
		}

		return (($context['resuming'] ?? false) === true);
	}//end waitIsOver()

	/**
	 * Write the moment this wait ends into the node's resume slot.
	 *
	 * The slot is the addressee mark the re-entry check reads. Without it, a
	 * resumed walk cannot tell the wait that is OVER from a wait it has only
	 * just reached — `context.resuming` is true for both, and reading that
	 * flag made a second wait node pass through in zero seconds.
	 *
	 * @param array    $context  Run-level metadata.
	 * @param DateTime $resumeAt When the wait ends.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-must-be-able-to-resume-from-where-it-stopped
	 */
	private function markWaiting(array $context, DateTime $resumeAt): void {
		$resume = ($context[FlowNodeResumeState::CONTEXT_KEY] ?? null);
		if ($resume instanceof FlowNodeResumeState === true) {
			$resume->set(key: 'waitingUntil', value: $resumeAt->format('c'));
		}

	}//end markWaiting()

	/**
	 * Work out when this wait ends.
	 *
	 * `until` is an absolute moment, `for` a relative one ("15 minutes",
	 * "2 days"). Both go through strtotime, so an author can write either the
	 * way they would say it. A non-blank `until` wins over `for`.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return DateTime|null The resume time, or null when it cannot be read.
	 *
	 * @spec openspec/specs/flow-expressions/spec.md
	 */
	private function resolve(array $config): ?DateTime {
		$until = trim((string)($config['until'] ?? ''));
		if ($until !== '') {
			return $this->momentAt(timestamp: strtotime($until));
		}

		return $this->momentAfter(duration: trim((string)($config['for'] ?? '')));
	}//end resolve()

	/**
	 * The moment a duration from now ends.
	 *
	 * A bare number is read as seconds, which is what an author who typed
	 * "30" almost certainly meant.
	 *
	 * @param string $duration The trimmed `for` value.
	 *
	 * @return DateTime|null The moment, or null when the duration cannot be read.
	 *
	 * @spec openspec/specs/flow-expressions/spec.md
	 */
	private function momentAfter(string $duration): ?DateTime {
		if ($duration === '') {
			return null;
		}

		if (ctype_digit($duration) === true) {
			return $this->momentAt(timestamp: (time() + (int)$duration));
		}

		return $this->momentAt(timestamp: strtotime('+' . ltrim($duration, '+')));
	}//end momentAfter()

	/**
	 * A Unix timestamp as a moment, or null when strtotime could not read one.
	 *
	 * @param int|false $timestamp The timestamp, or false.
	 *
	 * @return DateTime|null The moment.
	 *
	 * @spec openspec/specs/flow-expressions/spec.md
	 */
	private function momentAt(int|false $timestamp): ?DateTime {
		if ($timestamp === false) {
			return null;
		}

		return (new DateTime())->setTimestamp($timestamp);
	}//end momentAt()

	/**
	 * What kind of step this is. A timer the run catches. Not a task: nobody does anything.
	 *
	 * @return string The BPMN kind.
	 *
	 * @spec openspec/changes/flow-node-taxonomy/specs/flow-node-taxonomy/spec.md#requirement-a-node-declares-a-semantic-kind-drawn-from-bpmn
	 */
	public function getKind(): string {
		return IFlowNodeTaxonomy::KIND_EVENT;

	}//end getKind()
}//end class
