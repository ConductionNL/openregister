<?php

/**
 * The guarded way to answer a suspended run on somebody's behalf.
 *
 * WHY THIS EXISTS. The assignee rule ({@see FlowRunAssignee}) has one
 * implementation, but until now the engine left CALLING it to the consumer:
 * the HTTP resume endpoint consulted it, while an app resuming a run from PHP
 * called `FlowRunService::signal()` directly — which delivers unconditionally.
 * dossiq's two resume listeners each remembered to consult the rule first, and
 * "each caller must remember" is the failure mode: the next app forgets, and a
 * forgotten check does not throw — it lets the wrong person answer somebody
 * else's step, correctly formatted, HTTP 200. This service is the verb the
 * rule was missing: resolve, guard, audit, deliver.
 *
 * ONE GUARD. The HTTP resume path delegates here too, so a change to who may
 * answer is a change to exactly one place. `FlowRunService::signal()` remains
 * the unguarded engine-internal primitive underneath.
 *
 * THE GUARD, STATED HONESTLY. It is the recorded-assignee rule and nothing
 * more: an assigned step admits its assignee (group resolution included) and
 * refuses everyone else, an unassigned step admits anyone — webhook and
 * child-run signals are not human decisions. It does not decide whether the
 * actor may SEE the flow; an HTTP caller gets that from the controller's
 * flow-level check, and a PHP caller is already inside the trust boundary
 * where the object it reacted to was resolved.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine-consumer-seams/spec.md#requirement-a-server-side-signal-passes-the-same-guard-as-the-http-resume
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapperInterface;
use OCA\OpenRegister\Exception\FlowSignalRefused;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Delivers a signal to a suspended run, on behalf of a named actor, guarded.
 *
 * @spec openspec/specs/flow-engine-consumer-seams/spec.md#requirement-a-server-side-signal-passes-the-same-guard-as-the-http-resume
 */
class FlowRunSignalService implements FlowRunSignalInterface {
	/**
	 * Constructor.
	 *
	 * @param FlowRunMapperInterface  $mapper    Resolves a run uuid to the run.
	 * @param FlowRunServiceInterface $runner    Owns the unguarded delivery primitive.
	 * @param FlowRunAssignee         $assignees The access rule. The caller builds it with
	 *                                           the group manager and the principal
	 *                                           registry it has; absent, a group-assigned
	 *                                           or typed step refuses rather than admits.
	 * @param LoggerInterface|null    $logger    Audits a refusal. Nullable so the
	 *                                           controller can build this seam on
	 *                                           demand without one — there the
	 *                                           refusal is surfaced to the caller as
	 *                                           a 403, so nothing goes unrecorded;
	 *                                           the container-built instance always
	 *                                           has a logger.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
	 */
	public function __construct(
		private readonly FlowRunMapperInterface $mapper,
		private readonly FlowRunServiceInterface $runner,
		private readonly FlowRunAssignee $assignees,
		private readonly ?LoggerInterface $logger = null,
	) {
	}//end __construct()

	/**
	 * Answer a suspended run by uuid, guarded.
	 *
	 * The entry point for PHP consumers: a listener whose object completes a
	 * task holds exactly a run uuid. Resolution, the guard and delivery are
	 * one call, so there is nothing left for the consumer to remember.
	 *
	 * @param string    $runUuid The run to answer.
	 * @param RunSignal $signal  The payload, the actor and the addressed node.
	 *
	 * @return FlowRun The parked run, due for the worker's next pass.
	 *
	 * @throws FlowSignalRefused With reason RUN_NOT_FOUND, NOT_ASSIGNEE or NOT_SUSPENDED.
	 *
	 * @spec openspec/specs/flow-engine-consumer-seams/spec.md#requirement-a-server-side-signal-passes-the-same-guard-as-the-http-resume
	 */
	public function answerByUuid(string $runUuid, RunSignal $signal): FlowRun {
		try {
			$run = $this->mapper->findByUuid($runUuid);
		} catch (Throwable $e) {
			throw new FlowSignalRefused(
				reason: FlowSignalRefused::RUN_NOT_FOUND,
				message: 'No run carries uuid "' . $runUuid . '"; the signal was not delivered.',
				runUuid: $runUuid,
				actorUid: $signal->actor()
			);
		}

		return $this->answer(run: $run, signal: $signal);
	}//end answerByUuid()

	/**
	 * Answer an already-resolved run, as a named actor, guarded.
	 *
	 * For callers that resolved the run themselves — the HTTP endpoints, whose
	 * 404 wording is their own. The guard and the delivery are identical to
	 * {@see answerByUuid()}; a refusal touches nothing.
	 *
	 * @param FlowRun $run The run to answer.
	 * @param array $payload What the signaller wants the run to know.
	 * @param string|null $actorUid Who is answering; null or '' means anonymous.
	 * @param string|null $nodeId The node the answer addresses, when known.
	 *
	 * @return FlowRun The parked run, due for the worker's next pass.
	 *
	 * @throws FlowSignalRefused With reason NOT_ASSIGNEE or NOT_SUSPENDED.
	 *
	 * @spec openspec/specs/flow-engine-consumer-seams/spec.md#requirement-a-server-side-signal-passes-the-same-guard-as-the-http-resume
	 */
	public function signalRunAs(FlowRun $run, array $payload, ?string $actorUid, ?string $nodeId = null): FlowRun {
		return $this->answer(run: $run, signal: new RunSignal(payload: $payload, actorUid: $actorUid, nodeId: $nodeId));
	}//end signalRunAs()

	/**
	 * Answer an already-resolved run, guarded; a refusal touches nothing.
	 *
	 * @param FlowRun   $run    The run to answer.
	 * @param RunSignal $signal The payload, the actor and the addressed node.
	 *
	 * @return FlowRun The parked run, due for the worker's next pass.
	 *
	 * @throws FlowSignalRefused With reason NOT_ASSIGNEE or NOT_SUSPENDED.
	 *
	 * @spec openspec/specs/flow-engine-consumer-seams/spec.md#requirement-a-server-side-signal-passes-the-same-guard-as-the-http-resume
	 */
	public function answer(FlowRun $run, RunSignal $signal): FlowRun {
		$actor = $signal->actor();
		if ($this->assignees->mayAnswer(run: $run, uid: $actor, nodeId: $signal->nodeId) === false) {
			$this->auditRefusal(run: $run, signal: $signal);

			throw new FlowSignalRefused(
				reason: FlowSignalRefused::NOT_ASSIGNEE,
				message: 'The awaiting step of run "' . (string)$run->getUuid()
					. '" is assigned, and the acting user is not its assignee; the signal was not delivered.',
				runUuid: (string)$run->getUuid(),
				actorUid: $actor
			);
		}

		return $this->deliver(run: $run, signal: $signal);
	}//end answer()

	/**
	 * Deliver an admitted signal; only a suspended run takes one.
	 *
	 * @param FlowRun   $run    The run to answer.
	 * @param RunSignal $signal The admitted signal.
	 *
	 * @return FlowRun The parked run.
	 *
	 * @throws FlowSignalRefused With reason NOT_SUSPENDED.
	 */
	private function deliver(FlowRun $run, RunSignal $signal): FlowRun {
		$signalled = $this->runner->signal(run: $run, payload: $signal->payload);
		if ($signalled === null) {
			throw new FlowSignalRefused(
				reason: FlowSignalRefused::NOT_SUSPENDED,
				message: 'Only a suspended run can be signalled; run "' . (string)$run->getUuid()
					. '" is ' . (string)$run->getStatus() . '.',
				runUuid: (string)$run->getUuid(),
				actorUid: $signal->actor()
			);
		}

		return $signalled;
	}//end signalRunAs()

	/**
	 * Record who was refused from answering what.
	 *
	 * The refusal ALSO reaches the caller as an exception, but the caller may
	 * be a listener that can only log it locally — dossiq's task listener sees
	 * the refusal after the task is already saved, so the only trace of "the
	 * wrong person tried to advance this decision" is this record. Written by
	 * the engine so it exists whether or not the consumer remembers to.
	 *
	 * @param FlowRun   $run    The run the signal addressed.
	 * @param RunSignal $signal The refused signal: who tried, and which node it named.
	 *
	 * @return void
	 */
	private function auditRefusal(FlowRun $run, RunSignal $signal): void {
		$this->logger?->warning(
			message: '[FlowRunSignalService] Refused a signal: the actor is not the awaiting step\'s assignee',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'run' => (string)$run->getUuid(),
				'flow' => (string)$run->getFlowId(),
				'actor' => ($signal->actor() ?? '(anonymous)'),
				'assignee' => $this->assignees->recordedFor(run: $run, nodeId: $signal->nodeId),
				'node' => ($signal->nodeId ?? ''),
			]
		);

	}//end auditRefusal()
}//end class
