<?php

/**
 * Advance one queued run to wherever it can get to.
 *
 * Extracted from FlowRunWorker so the SAME code path serves both ways a run is
 * driven: the background worker draining the queue, and a synchronous run that
 * executes inline and answers with the finished run.
 *
 * That sharing is the point. A separate inline implementation would be a second
 * engine entry point that drifts — it would resolve the flow, the subject and
 * the payload seed slightly differently, and a flow would behave one way when a
 * human pressed Run and another way when cron picked it up.
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
 * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use Psr\Log\LoggerInterface;
use stdClass;
use Throwable;

/**
 * Resolves what a run needs and hands it to the engine.
 *
 * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
 */
class FlowRunAdvancer {
	/**
	 * Constructor.
	 *
	 * @param FlowRunMapper $mapper Persists a run that cannot proceed.
	 * @param FlowRunService $runner Executes the run.
	 * @param FlowLocator $resolvers Resolves the flow and its subject.
	 * @param LoggerInterface $logger Records failures.
	 */
	public function __construct(
		private readonly FlowRunMapper $mapper,
		private readonly FlowRunService $runner,
		private readonly FlowLocator $resolvers,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Advance one run, never letting its failure stop a batch.
	 *
	 * @param FlowRun $run The run to advance.
	 * @param boolean $rethrow Whether to rethrow instead of swallowing. The
	 *                         worker swallows so one poisoned run cannot stop
	 *                         the queue; a synchronous caller wants the error,
	 *                         because it is answering a request about THIS run.
	 *
	 * @return FlowRun The run, in whatever state it reached.
	 *
	 * @throws Throwable When $rethrow is true and the run could not be advanced.
	 *
	 * FlowItems::item is a pure value constructor with no state to inject; a
	 * factory collaborator would add a dependency to say the same thing.
	 *
	 * $rethrow selects who handles a failure, not what the method does. The cron
	 * worker must swallow and record it so one bad run cannot stop the queue; a
	 * synchronous run must let it out so the caller's HTTP response can carry it.
	 * Splitting that into two methods would duplicate the whole advance path to
	 * vary its final catch block.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 *
	 * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
	 */
	public function advance(FlowRun $run, bool $rethrow = false): FlowRun {
		try {
			$flow = $this->resolvers->resolveFlow((string)$run->getFlowId());
			if ($flow === null) {
				// No installed app owns this flow — it was deleted, or the app
				// that stored it was removed. The run cannot proceed; fail it
				// with a clear reason rather than leaving it queued forever.
				$run->setStatus(FlowRun::STATUS_FAILED);
				$run->setError(sprintf('No app provides flow "%s" (deleted, or its app removed?).', $run->getFlowId()));
				$this->mapper->update($run);
				return $run;
			}

			// A run may legitimately have no subject (a manual or webhook run
			// seeded from its payload). Only resolve one when the run names it.
			$subject = null;
			if (trim((string)$run->getSubjectUuid()) !== '') {
				$subject = $this->resolvers->resolveSubject(
					(string)$run->getSubjectUuid(),
					(string)$run->getSubjectRegister(),
					(string)$run->getSubjectSchema()
				);

				if ($subject === null) {
					$run->setStatus(FlowRun::STATUS_FAILED);
					$run->setError(sprintf('Subject "%s" no longer exists.', $run->getSubjectUuid()));
					$this->mapper->update($run);
					return $run;
				}
			}

			// A subjectless run still needs an object to carry the marking; a
			// bare holder does, since the marking store keeps the marking on the
			// run itself ({@see FlowRunMarkingStore}). Such a run is seeded from
			// its payload instead of an object: a non-object trigger (a file, a
			// user) puts what it is about under `payload` on the run context, and
			// that becomes the first item, so the flow reads a file's path or a
			// user's id exactly as it would an object's fields.
			$seed = null;
			if ($subject === null) {
				$subject = new stdClass();
				$payload = (array)(($run->getContext() ?? [])['payload'] ?? []);
				if ($payload !== []) {
					$seed = [FlowItems::item(json: $payload)];
				}
			}

			return $this->runner->execute(run: $run, flow: $flow, subject: $subject, seedItems: $seed);
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[FlowRunAdvancer] Failed to advance a run',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'run' => $run->getUuid(),
					'error' => $e->getMessage(),
				]
			);

			if ($rethrow === true) {
				throw $e;
			}

			return $run;
		}//end try

	}//end advance()
}//end class
