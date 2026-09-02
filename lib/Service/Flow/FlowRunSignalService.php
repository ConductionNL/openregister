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
 * @spec openspec/changes/flow-engine-consumer-seams/specs/flow-engine-consumer-seams/spec.md#requirement-a-server-side-signal-passes-the-same-guard-as-the-http-resume
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Exception\FlowSignalRefused;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Delivers a signal to a suspended run, on behalf of a named actor, guarded.
 *
 * @spec openspec/changes/flow-engine-consumer-seams/specs/flow-engine-consumer-seams/spec.md#requirement-a-server-side-signal-passes-the-same-guard-as-the-http-resume
 */
class FlowRunSignalService {
	/**
	 * Constructor.
	 *
	 * @param FlowRunMapper $mapper Resolves a run uuid to the run.
	 * @param FlowRunService $runner Owns the unguarded delivery primitive.
	 * @param LoggerInterface|null $logger Audits a refusal. Nullable so the
	 *                                     controller can build this seam on
	 *                                     demand without one — there the
	 *                                     refusal is surfaced to the caller as
	 *                                     a 403, so nothing goes unrecorded;
	 *                                     the container-built instance always
	 *                                     has a logger.
	 * @param IGroupManager|null $groupManager Resolves group assignment. Absent,
	 *                                         a group-assigned step refuses
	 *                                         rather than admits — the
	 *                                         fail-closed direction.
	 * @param FlowRunAssignee|null $assignees The access rule. Injectable so a
	 *                                        consumer's test can drive the real
	 *                                        contract; defaults to the real one.
	 */
	public function __construct(
		private readonly FlowRunMapper $mapper,
		private readonly FlowRunService $runner,
		private readonly ?LoggerInterface $logger = null,
		private readonly ?IGroupManager $groupManager = null,
		private readonly ?FlowRunAssignee $assignees = null,
	) {

	}//end __construct()

	/**
	 * Answer a suspended run by uuid, as a named actor, guarded.
	 *
	 * The entry point for PHP consumers — a listener whose object completes a
	 * task holds exactly a run uuid. Resolution, the guard and delivery are
	 * one call, so there is nothing left for the consumer to remember.
	 *
	 * @param string $runUuid The run to answer.
	 * @param array $payload What the signaller wants the run to know.
	 * @param string|null $actorUid Who is answering; null or '' means anonymous,
	 *                              which an ASSIGNED step refuses.
	 * @param string|null $nodeId The node the answer addresses, when known. The
	 *                            guard then checks THAT node's recorded
	 *                            assignee; addressing can narrow the check but
	 *                            never loosen it — see {@see FlowRunAssignee::recordedFor()}.
	 *
	 * @return FlowRun The parked run, due for the worker's next pass.
	 *
	 * @throws FlowSignalRefused With reason RUN_NOT_FOUND, NOT_ASSIGNEE or NOT_SUSPENDED.
	 *
	 * @spec openspec/changes/flow-engine-consumer-seams/specs/flow-engine-consumer-seams/spec.md#requirement-a-server-side-signal-passes-the-same-guard-as-the-http-resume
	 */
	public function signalAs(string $runUuid, array $payload, ?string $actorUid, ?string $nodeId = null): FlowRun {
		try {
			$run = $this->mapper->findByUuid($runUuid);
		} catch (Throwable $e) {
			throw new FlowSignalRefused(
				reason: FlowSignalRefused::RUN_NOT_FOUND,
				message: 'No run carries uuid "' . $runUuid . '"; the signal was not delivered.',
				runUuid: $runUuid,
				actorUid: $this->normalize(actorUid: $actorUid)
			);
		}

		return $this->signalRunAs(run: $run, payload: $payload, actorUid: $actorUid, nodeId: $nodeId);
	}//end signalAs()

	/**
	 * Answer an already-resolved run, as a named actor, guarded.
	 *
	 * For callers that resolved the run themselves — the HTTP endpoints, whose
	 * 404 wording is their own. The guard and the delivery are identical to
	 * {@see signalAs()}; a refusal touches nothing.
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
	 * @spec openspec/changes/flow-engine-consumer-seams/specs/flow-engine-consumer-seams/spec.md#requirement-a-server-side-signal-passes-the-same-guard-as-the-http-resume
	 */
	public function signalRunAs(FlowRun $run, array $payload, ?string $actorUid, ?string $nodeId = null): FlowRun {
		$actor = $this->normalize(actorUid: $actorUid);
		$rule = ($this->assignees ?? new FlowRunAssignee(groupManager: $this->groupManager));

		if ($rule->mayAnswer(run: $run, uid: $actor, nodeId: $nodeId) === false) {
			$this->auditRefusal(run: $run, actor: $actor, assignee: $rule->recordedFor(run: $run, nodeId: $nodeId), nodeId: $nodeId);

			throw new FlowSignalRefused(
				reason: FlowSignalRefused::NOT_ASSIGNEE,
				message: 'The awaiting step of run "' . (string)$run->getUuid()
					. '" is assigned, and the acting user is not its assignee; the signal was not delivered.',
				runUuid: (string)$run->getUuid(),
				actorUid: $actor
			);
		}

		$signalled = $this->runner->signal(run: $run, payload: $payload);
		if ($signalled === null) {
			throw new FlowSignalRefused(
				reason: FlowSignalRefused::NOT_SUSPENDED,
				message: 'Only a suspended run can be signalled; run "' . (string)$run->getUuid()
					. '" is ' . (string)$run->getStatus() . '.',
				runUuid: (string)$run->getUuid(),
				actorUid: $actor
			);
		}

		return $signalled;
	}//end signalRunAs()

	/**
	 * An empty actor is an anonymous one.
	 *
	 * @param string|null $actorUid The claimed actor.
	 *
	 * @return string|null The uid, or null when there is effectively none.
	 */
	private function normalize(?string $actorUid): ?string {
		if ($actorUid === null || trim($actorUid) === '') {
			return null;
		}

		return trim($actorUid);
	}//end normalize()

	/**
	 * Record who was refused from answering what.
	 *
	 * The refusal ALSO reaches the caller as an exception, but the caller may
	 * be a listener that can only log it locally — dossiq's task listener sees
	 * the refusal after the task is already saved, so the only trace of "the
	 * wrong person tried to advance this decision" is this record. Written by
	 * the engine so it exists whether or not the consumer remembers to.
	 *
	 * @param FlowRun $run The run the signal addressed.
	 * @param string|null $actor Who tried, or null when anonymous.
	 * @param string $assignee Who the step is recorded for.
	 * @param string|null $nodeId The addressed node, when the caller named one.
	 *
	 * @return void
	 */
	private function auditRefusal(FlowRun $run, ?string $actor, string $assignee, ?string $nodeId): void {
		$this->logger?->warning(
			message: '[FlowRunSignalService] Refused a signal: the actor is not the awaiting step\'s assignee',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'run' => (string)$run->getUuid(),
				'flow' => (string)$run->getFlowId(),
				'actor' => ($actor ?? '(anonymous)'),
				'assignee' => $assignee,
				'node' => ($nodeId ?? ''),
			]
		);

	}//end auditRefusal()
}//end class
