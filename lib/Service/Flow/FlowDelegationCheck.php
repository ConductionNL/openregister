<?php

/**
 * Re-resolves a run's stored delegation at the moment it would fire.
 *
 * WHY THE FIRE PATH ASKS AGAIN
 *
 * {@see FlowTriggerValidator} answered "may this be saved", against the grants
 * that existed at save time. A grant can be revoked, denied or expire between
 * that save and the schedule's first firing — and stopping the next firing is
 * the entire content of a revocation. Treating a stored trigger as standing
 * authorization would make revoking cosmetic for exactly the runs nobody is
 * watching, which is the opposite of what revoking is for.
 *
 * WHAT IT CHECKS AGAINST
 *
 * `runAsDeclaredBy` — server-written at save time by the validator, never read
 * from a request body — names the principal who asserted the delegation. At
 * 03:00 nobody is present, so without that record the only candidate left would
 * be `flow.owner`, which answers a different question and is the fallback
 * ADR-099 removed.
 *
 * Split out of {@see FlowRunService} because that class was already at its
 * complexity ceiling — the same reason the other `Flow*` collaborators in this
 * directory exist.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/delegation-grants/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Service\Delegation\DelegationRefused;
use OCA\OpenRegister\Service\Delegation\DelegationService;
use OCA\OpenRegister\Service\Delegation\DelegationVerdict;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stops a run whose delegation is no longer live.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) DelegationVerdict is a value object with
 *   NAMED CONSTRUCTORS and a private constructor, so `permitted` and `reason`
 *   can never be built disagreeing. Injecting a factory to satisfy the rule
 *   would add a collaborator whose only job is to call the constructor that
 *   pattern exists to hide.
 *
 * @spec openspec/specs/delegation-grants/spec.md
 */
class FlowDelegationCheck {

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the delegation service and flow mapper.
	 * @param LoggerInterface    $logger    Records refusals and recording failures.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Refuse a run whose stored delegation no longer holds.
	 *
	 * A no-op unless the trigger actually asserted a delegation — a schedule
	 * naming its own author delegates nothing and has nothing to re-resolve.
	 *
	 * 🔴 THE SCHEDULE IS LEFT ENABLED, unlike the unattributed case, and that
	 * asymmetry is the whole difference between the two faults. A flow that names
	 * nobody cannot start working again without someone editing it, so leaving it
	 * "on" would be a switch that lies. A revoked delegation is the opposite: it
	 * becomes valid again the moment the grant does, with no edit at all, and
	 * disabling would silently convert a temporary revocation into a permanent
	 * one that only a human re-enabling could undo — with nothing telling them to.
	 *
	 * @param string      $flowId     The flow being queued.
	 * @param string      $trigger    What started the run.
	 * @param string|null $declaredBy The principal who asserted the delegation.
	 * @param string      $runAs      The identity the run would execute as.
	 *
	 * @return void
	 *
	 * @throws DelegationRefused When the delegation no longer holds.
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function refuseIfRevoked(
		string $flowId,
		string $trigger,
		?string $declaredBy,
		string $runAs,
	): void {
		if ($declaredBy === null || $declaredBy === $runAs) {
			return;
		}

		$delegation = $this->delegationService(flowId: $flowId, declaredBy: $declaredBy, runAs: $runAs);

		$verdict = $delegation->verdictFor(principal: $declaredBy, actingAs: $runAs);
		if ($verdict->permitted === true) {
			return;
		}

		$refusal = new DelegationRefused(principal: $declaredBy, actingAs: $runAs, verdict: $verdict);

		$this->logger->warning(
			message: '[FlowDelegationCheck] Refused a run whose delegation is no longer live',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'flow' => $flowId,
				'trigger' => $trigger,
				'declaredBy' => $declaredBy,
				'runAs' => $runAs,
				'reason' => $verdict->reason,
			]
		);

		$this->record(flowId: $flowId, refusal: $refusal);

		throw $refusal;
	}//end refuseIfRevoked()

	/**
	 * The delegation service, or a refusal when it cannot be reached.
	 *
	 * Fail CLOSED, and only here. This is reached solely for a run that IS
	 * asserting a delegation, so refusing costs exactly the runs whose
	 * authorization cannot be established — never the ordinary ones.
	 *
	 * @param string $flowId     The flow being queued.
	 * @param string $declaredBy The principal who asserted the delegation.
	 * @param string $runAs      The identity the run would execute as.
	 *
	 * @return DelegationService The service.
	 *
	 * @throws DelegationRefused When the service cannot be resolved.
	 */
	private function delegationService(string $flowId, string $declaredBy, string $runAs): DelegationService {
		try {
			$resolved = $this->container->get(DelegationService::class);
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[FlowDelegationCheck] Could not resolve the delegation service; refusing the run: '
					. $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'flow' => $flowId, 'runAs' => $runAs]
			);

			throw new DelegationRefused(
				principal: $declaredBy,
				actingAs: $runAs,
				verdict: DelegationVerdict::refused(
					reason: DelegationVerdict::REASON_UNREADABLE,
					detail: 'The delegation store could not be reached, so the delegation is refused.'
				)
			);
		}//end try

		if (($resolved instanceof DelegationService) === false) {
			throw new DelegationRefused(
				principal: $declaredBy,
				actingAs: $runAs,
				verdict: DelegationVerdict::refused(
					reason: DelegationVerdict::REASON_UNREADABLE,
					detail: 'The delegation store did not answer, so the delegation is refused.'
				)
			);
		}

		return $resolved;
	}//end delegationService()

	/**
	 * Make the refusal visible on the flow itself.
	 *
	 * Best-effort by construction: a failure to record the refusal must not
	 * replace the refusal, which is the part that actually prevents the run.
	 *
	 * @param string            $flowId  The flow that was refused.
	 * @param DelegationRefused $refusal The refusal, whose message is stored.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	private function record(string $flowId, DelegationRefused $refusal): void {
		try {
			$mapper = $this->container->get('OCA\OpenRegister\Db\FlowMapper');
			$flow = $mapper->findByUuid($flowId);

			$flow->setStatus(Flow::STATUS_ERROR);
			$flow->setStatusMessage($refusal->getMessage());

			$mapper->update($flow);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[FlowDelegationCheck] Could not record the delegation refusal on the flow: '
					. $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'flow' => $flowId]
			);
		}

	}//end record()
}//end class
