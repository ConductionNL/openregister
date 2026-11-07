<?php

/**
 * OpenRegister ApprovalChainGateListener
 *
 * Subscribes to ObjectUpdatingEvent and blocks a lifecycle transition named by a
 * schema's `x-openregister-approval-chains` declaration until the object's
 * approval SEQUENCE for that chain is complete with an approving outcome
 * (flow-approval-consolidation). The refusal contract is unchanged from the
 * retired step-scanning implementation: the same two error codes
 * (`approval-chain-pending`, `approval-chain-misconfigured`), the same
 * fail-closed policy on a chain that cannot be provisioned, and the same
 * transition matching, re-derived independently of
 * `Listener\LifecycleValidationListener` exactly as before.
 *
 * One deliberate behavioural difference (design D-5): a rejected cycle is
 * CLOSED and kept, never deleted. The next attempt opens a NEW sequence and
 * the rejected one, its decisions and its comments stay readable.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/approval-workflow/spec.md#req-007
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\TaskSequence;
use OCA\OpenRegister\Db\TaskSequenceMapper;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ApprovalChainAnnotationInstaller;
use OCA\OpenRegister\Service\Task\TaskSequenceServiceInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Gates a declared lifecycle transition on approval-sequence completion.
 *
 * @template-implements IEventListener<ObjectUpdatingEvent>
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ApprovalChainGateListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param LifecycleEditReader              $edits           Reads the edit and the transition it amounts to.
	 * @param TaskSequenceMapper               $sequenceMapper  Reads the anchor's sequences.
	 * @param TaskSequenceServiceInterface     $sequenceService Provisions a sequence on the first attempt.
	 * @param ApprovalChainAnnotationInstaller $installer       Compiles the declared chain into a task template.
	 * @param IUserSession                     $userSession     Current user session (requester identity).
	 * @param LoggerInterface                  $logger          Logger for gate diagnostics.
	 */
	public function __construct(
		private readonly LifecycleEditReader $edits,
		private readonly TaskSequenceMapper $sequenceMapper,
		private readonly TaskSequenceServiceInterface $sequenceService,
		private readonly ApprovalChainAnnotationInstaller $installer,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Gate the attempted lifecycle transition against any declared approval chain.
	 *
	 * The shared resolver decides the transition, so a named call is gated by its OWN
	 * chain and not by an earlier transition that shares its from/to pair. An edit no
	 * transition allows is LifecycleValidationListener's to reject; the gate has
	 * nothing to evaluate.
	 *
	 * @param Event $event Inbound dispatcher event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/approval-workflow/spec.md#req-007
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectUpdatingEvent) === true) {
			$this->gateUpdate(event: $event);
		}
	}//end handle()

	/**
	 * Gate an update that moves the lifecycle field of a schema with approval chains.
	 *
	 * @param ObjectUpdatingEvent $event The update.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/approval-workflow/spec.md#req-007
	 */
	private function gateUpdate(ObjectUpdatingEvent $event): void {
		$edit = $this->edits->editOf(event: $event);
		if ($edit === null || $this->chainsOf(edit: $edit) === [] || $edit->changesStateToText() === false) {
			return;
		}

		$attempt = $this->edits->attemptOf(edit: $edit);
		if ($attempt !== null) {
			$this->gate(attempt: $attempt);
		}
	}//end gateUpdate()

	/**
	 * The approval chains the edited object's schema declares.
	 *
	 * @param LifecycleEdit $edit The edit.
	 *
	 * @return array<mixed, mixed>
	 */
	private function chainsOf(LifecycleEdit $edit): array {
		$config = ($edit->declaration->schema->getConfiguration() ?? []);
		$chains = ($config['x-openregister-approval-chains'] ?? null);
		if (is_array($chains) === true) {
			return $chains;
		}

		return [];
	}//end chainsOf()

	/**
	 * Judge every chain that gates the transition, in order, until one blocks it.
	 *
	 * @param LifecycleAttempt $attempt The matched transition.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/approval-workflow/spec.md#req-007
	 */
	private function gate(LifecycleAttempt $attempt): void {
		foreach ($this->chainsGating(attempt: $attempt) as $chainKey) {
			if ($this->evaluateGate(edit: $attempt->edit, chainKey: $chainKey) === true) {
				return;
			}
		}
	}//end gate()

	/**
	 * The keys of the well-formed chains that gate the matched transition, in declared order.
	 *
	 * @param LifecycleAttempt $attempt The matched transition.
	 *
	 * @return list<string>
	 */
	private function chainsGating(LifecycleAttempt $attempt): array {
		$keys = [];
		foreach ($this->chainsOf(edit: $attempt->edit) as $chainKey => $spec) {
			if (is_string($chainKey) === true && is_array($spec) === true && ($spec['transition'] ?? null) === $attempt->action) {
				$keys[] = $chainKey;
			}
		}

		return $keys;
	}//end chainsGating()

	/**
	 * Evaluate one declared chain against the object attempting its gated transition.
	 *
	 * The template is compiled on demand, so the gate never depends on a save-time
	 * listener having run for the current schema revision. The tier is resolved from
	 * the attempted write ONCE, here, and frozen onto the sequence, so a mid-cycle
	 * amount edit cannot re-route a running approval.
	 *
	 * @param LifecycleEdit $edit     The update attempting the transition.
	 * @param string        $chainKey Declarative chain key.
	 *
	 * @return bool True when the transition was blocked.
	 *
	 * @spec openspec/specs/approval-workflow/spec.md#req-007
	 */
	private function evaluateGate(LifecycleEdit $edit, string $chainKey): bool {
		$schema   = $edit->declaration->schema;
		$template = $this->installer->compile(schema: $schema, chainKey: $chainKey);
		if ($template === null) {
			$this->refuseMisconfigured(edit: $edit, chainKey: $chainKey);
			return true;
		}

		$tierPositions = $this->resolveTierPositions(template: $template, newData: $edit->getNewData());
		if ($tierPositions === []) {
			// Cumulative tiers and an amount below the lowest tier: nothing to approve.
			return false;
		}

		return $this->judgeSequence(
			run: new ChainGateRun(edit: $edit, chainKey: $chainKey, template: $template, tierPositions: $tierPositions)
		);
	}//end evaluateGate()

	/**
	 * Fail closed on a chain that is declared but not compilable (e.g. no valid approvers).
	 *
	 * This mirrors LifecycleGuardRegistry's missing-tag policy.
	 *
	 * @param LifecycleEdit $edit     The update attempting the transition.
	 * @param string        $chainKey Declarative chain key.
	 *
	 * @return void
	 */
	private function refuseMisconfigured(LifecycleEdit $edit, string $chainKey): void {
		$this->logger->error(
			sprintf(
				'[ApprovalChainGateListener] chain "%s" could not be compiled for schema %s',
				$chainKey,
				(string)$edit->declaration->schema->getId()
			)
		);
		$edit->refuse(
			error: [
				'code' => 'approval-chain-misconfigured',
				'message' => sprintf('Approval chain "%s" is declared but not provisioned.', $chainKey),
			]
		);
	}//end refuseMisconfigured()

	/**
	 * Judge the chain by its newest sequence.
	 *
	 * The store consulted is the SEQUENCE, not step rows: complete releases, running
	 * refuses without provisioning a duplicate, and rejected or terminated (or none)
	 * provisions a NEW sequence and refuses. The rejected cycle is KEPT (design D-5): a
	 * fresh attempt opens a sequence beside it rather than deleting the record of who
	 * refused.
	 *
	 * @param ChainGateRun $run The chain being judged.
	 *
	 * @return bool True when the transition was blocked.
	 *
	 * @spec openspec/specs/approval-workflow/spec.md#req-007
	 */
	private function judgeSequence(ChainGateRun $run): bool {
		$status = $this->newestStatus(run: $run);
		if ($status === TaskSequence::STATUS_COMPLETED) {
			return false;
		}

		if ($status === TaskSequence::STATUS_RUNNING) {
			$this->refusePending(run: $run, message: sprintf('Approval chain "%s" is still pending a decision.', $run->chainKey));
			return true;
		}

		$this->provision(run: $run);
		$this->refusePending(run: $run, message: sprintf('Transition requires approval via chain "%s".', $run->chainKey));
		return true;
	}//end judgeSequence()

	/**
	 * The status of the anchor's newest sequence of this chain, or null when there is none.
	 *
	 * @param ChainGateRun $run The chain being judged.
	 *
	 * @return string|null
	 */
	private function newestStatus(ChainGateRun $run): ?string {
		return $this->sequenceMapper->findNewestForAnchor(
			anchorObjectUuid: $run->edit->uuid(),
			templateId: (string)$run->template['templateId']
		)?->getStatus();
	}//end newestStatus()

	/**
	 * Open a new sequence for the chain.
	 *
	 * @param ChainGateRun $run The chain being judged.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/approval-workflow/spec.md#req-007
	 */
	private function provision(ChainGateRun $run): void {
		$register   = (string)$run->edit->object()->getRegister();
		$registerId = null;
		if (is_numeric($register) === true) {
			$registerId = (int)$register;
		}

		$this->sequenceService->provision(
			template: $run->template,
			anchorObjectUuid: $run->edit->uuid(),
			requesterId: $this->userSession->getUser()?->getUID(),
			tierPositions: $run->tierPositions,
			registerId: $registerId
		);
	}//end provision()

	/**
	 * Refuse the transition while its approval is open.
	 *
	 * @param ChainGateRun $run     The chain being judged.
	 * @param string       $message Human-readable message.
	 *
	 * @return void
	 */
	private function refusePending(ChainGateRun $run, string $message): void {
		$run->edit->refuse(error: ['code' => 'approval-chain-pending', 'message' => $message]);
	}//end refusePending()

	/**
	 * Resolve the applicable tier for this object, frozen at provisioning.
	 *
	 * When the declaration carries `amountField`, selects the single
	 * position with the highest `minAmount` that is `<=` the object's value
	 * for that field, re-based at order 1. With `tiers: cumulative` it selects
	 * every such position instead (see cumulativeTiers()). Otherwise
	 * returns `null` so provisioning uses every declared position in order,
	 * unchanged.
	 *
	 * @param array<string, mixed> $template The compiled template.
	 * @param array<string, mixed> $newData  The object's new (attempted) data.
	 *
	 * @return array<int, array<string, mixed>>|null The tier(s), or null for no routing.
	 *
	 * @spec openspec/specs/approval-workflow/spec.md
	 */
	private function resolveTierPositions(array $template, array $newData): ?array {
		$amountField = (string)($template['amountField'] ?? '');
		if ($amountField === '') {
			return null;
		}

		$applicable = $this->tiersUpTo(positions: (array)($template['positions'] ?? []), amount: (float)($newData[$amountField] ?? 0));
		if (($template['tiers'] ?? ApprovalChainAnnotationInstaller::TIERS_HIGHEST) === ApprovalChainAnnotationInstaller::TIERS_CUMULATIVE) {
			return $this->cumulativeTiers(applicable: $applicable);
		}

		$tier = $this->highestTier(applicable: $applicable);
		if ($tier === []) {
			return null;
		}

		return $tier;
	}//end resolveTierPositions()

	/**
	 * The positions at or below an amount.
	 *
	 * @param array<int, mixed> $positions The compiled positions.
	 * @param float             $amount    The object's amount.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function tiersUpTo(array $positions, float $amount): array {
		return array_values(
			array_filter(
				$positions,
				static fn (mixed $position): bool => is_array($position) === true && (float)($position['minAmount'] ?? 0) <= $amount
			)
		);
	}//end tiersUpTo()

	/**
	 * The applicable tier with the highest minAmount, the first of a tie, numbered 1.
	 *
	 * @param array<int, array<string, mixed>> $applicable The positions at or below the amount.
	 *
	 * @return array<int, array<string, mixed>> The tier, empty when none applies.
	 *
	 * @spec openspec/specs/approval-workflow/spec.md
	 */
	private function highestTier(array $applicable): array {
		usort(
			$applicable,
			static fn (array $left, array $right): int => ((float)($right['minAmount'] ?? 0) <=> (float)($left['minAmount'] ?? 0))
		);

		return $this->numbered(positions: array_slice($applicable, 0, 1));
	}//end highestTier()

	/**
	 * Every applicable tier, lowest minAmount first, numbered from 1.
	 *
	 * An amount below the lowest tier yields an empty list: nothing to approve.
	 *
	 * @param array<int, array<string, mixed>> $applicable The positions at or below the amount.
	 *
	 * @return array<int, array<string, mixed>> The positions to provision.
	 *
	 * @spec openspec/specs/approval-workflow/spec.md
	 */
	private function cumulativeTiers(array $applicable): array {
		usort(
			$applicable,
			static fn (array $left, array $right): int => ((float)($left['minAmount'] ?? 0) <=> (float)($right['minAmount'] ?? 0))
		);

		return $this->numbered(positions: $applicable);
	}//end cumulativeTiers()

	/**
	 * Number positions from 1 in their order.
	 *
	 * @param array<int, array<string, mixed>> $positions The positions.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function numbered(array $positions): array {
		foreach (array_keys($positions) as $index) {
			$positions[$index]['order'] = ($index + 1);
		}

		return $positions;
	}//end numbered()
}//end class
