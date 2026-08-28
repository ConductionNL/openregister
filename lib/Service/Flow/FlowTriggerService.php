<?php

/**
 * Turns a Nextcloud event into queued flow runs.
 *
 * This is the trigger side of the engine. When something happens — an object is
 * created, a file changes, a share is declined — this service asks every
 * resolver which of its flows are wired to that event, and queues a run for
 * each. It does NOT execute them: a trigger fires inside the dispatch of the
 * thing that caused it (often a user's save), and an arbitrary graph must not
 * sit on that critical path. The FlowRunWorker executes the queue off-request.
 *
 * A Nextcloud-native trigger is the differentiator the whole programme rests
 * on: an external automation tool sees Nextcloud over WebDAV and a generic
 * connector; a flow triggered by a *share being declined*, with the sharee's
 * identity and the instance's RBAC already resolved, is not something that tool
 * can reproduce.
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
 * @spec openspec/changes/or-flow-triggers/specs/flow-triggers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use Psr\Log\LoggerInterface;
use stdClass;
use Throwable;

/**
 * Queues a flow run for every flow wired to a fired event.
 */
class FlowTriggerService {

	/**
	 * The execution mode that runs a flow inline rather than queueing it.
	 *
	 * @var string
	 */
	private const MODE_SYNC = 'sync';

	/**
	 * Constructor.
	 *
	 * @param FlowLocator $resolvers Finds flows wired to an event.
	 * @param FlowRunService $runner Queues the runs.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly FlowLocator $resolvers,
		private readonly FlowRunService $runner,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Fire an event: queue a run for every flow wired to it.
	 *
	 * Never throws into the caller. A trigger runs inside the dispatch of a user
	 * action (a save, a share change); a failure to queue must not break that
	 * action, so it is logged and swallowed.
	 *
	 * @param string $event The event id (e.g. `object.created`).
	 * @param array $subject `{uuid, register, schema}` of the object, when there is one.
	 * @param string|null $user The user whose action fired the event.
	 * @param array $context Extra run-level metadata (the event payload, say).
	 *
	 * @return int The number of runs queued.
	 *
	 * @spec openspec/changes/or-flow-triggers/specs/flow-triggers/spec.md
	 */
	public function fire(string $event, array $subject = [], ?string $user = null, array $context = []): int {
		try {
			$register = (string)($subject['register'] ?? '');
			$schema = (string)($subject['schema'] ?? '');
			$flowIds = $this->resolvers->flowsForTrigger($event, $register, $schema);
			if ($flowIds === []) {
				return 0;
			}

			$queued = 0;
			foreach ($flowIds as $flowId) {
				$run = $this->runner->queue(
					flowId: $flowId,
					subject: $subject,
					trigger: $event,
					context: $context,
					user: $user
				);
				$queued++;

				// A `sync` flow runs inside the call that triggered it, so its
				// effects are done before the caller's save returns. It is still
				// queued first and executed second — one persistence path means
				// history, retry and resume treat it exactly like a drained run.
				// A sync flow that suspends is simply left for the worker; the
				// inline call never blocks on a wait.
				$this->runInline(run: $run, flowId: $flowId, subject: $subject);
			}

			$this->logger->debug(
				message: '[FlowTriggerService] Queued flow runs for an event',
				context: ['file' => __FILE__, 'line' => __LINE__, 'event' => $event, 'queued' => $queued]
			);

			return $queued;
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[FlowTriggerService] Failed to queue flow runs for an event: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'event' => $event]
			);

			return 0;
		}//end try

	}//end fire()

	/**
	 * Execute a just-queued run inline when its flow asked to be synchronous.
	 *
	 * Deliberately best-effort. A flow that does not declare `sync`, a flow no
	 * resolver owns, or a subject that cannot be resolved all leave the run
	 * exactly as `queue()` left it — and `FlowRunWorker` picks it up on its next
	 * pass with the full defensive handling it already owns. That is why this
	 * method does not fail runs itself: duplicating the worker's failure
	 * semantics here would give the engine two places that decide what a broken
	 * run means, and they would drift.
	 *
	 * @param FlowRun $run The run just queued.
	 * @param string $flowId The flow's id.
	 * @param array $subject The subject descriptor the trigger fired for.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/openregister-flow-executionmode-and-token/specs/flow-execution-mode/spec.md
	 */
	private function runInline(FlowRun $run, string $flowId, array $subject): void {
		$flow = $this->resolvers->resolveFlow($flowId);
		if ($flow === null) {
			return;
		}

		if (strtolower(trim((string)($flow['executionMode'] ?? ''))) !== self::MODE_SYNC) {
			return;
		}

		// Mirrors the worker's seeding rule: a run with a subject carries the
		// object, a subjectless one (a file, a user) is seeded from the payload
		// its trigger recorded on the context.
		$seed = null;
		$object = null;
		$uuid = trim((string)($subject['uuid'] ?? ''));
		if ($uuid !== '') {
			$object = $this->resolvers->resolveSubject(
				$uuid,
				(string)($subject['register'] ?? ''),
				(string)($subject['schema'] ?? '')
			);

			if ($object === null) {
				return;
			}
		}

		if ($object === null) {
			$object = new stdClass();
			$payload = (array)(($run->getContext() ?? [])['payload'] ?? []);
			if ($payload !== []) {
				$seed = [FlowItems::item(json: $payload)];
			}
		}

		$this->runner->execute(run: $run, flow: $flow, subject: $object, seedItems: $seed);

	}//end runInline()
}//end class
