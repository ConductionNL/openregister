<?php

/**
 * A run's step history: what number each step gets, and the rows themselves.
 *
 * Attribution has to PREDICT the step number rather than read it. Audit rows are
 * written DURING the walk, while the step rows are recorded only after it — so
 * by the time `recordSteps()` knows a step's sequence, every row that step
 * caused has already been sealed.
 *
 * This mirrors the arithmetic in {@see FlowRunService::recordSteps()} on
 * purpose: that method numbers a segment from `highestSequence + 1`, so a hop's
 * number is that base plus its index within the segment's log. Both sides must
 * arrive at the same value or an attributed audit row and its `FlowRunStep` row
 * describe different steps — which is worse than no attribution, because it
 * looks correct.
 *
 * Split out of FlowRunService when adding it pushed that class past its length
 * and complexity ceilings. The better reason is that this is a rule worth
 * testing directly, and it was not reachable from a test while it lived as a
 * private method behind a full run.
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
 * @spec openspec/changes/flow-object-attribution/specs/flow-object-attribution/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use DateTime;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunStep;
use OCA\OpenRegister\Db\FlowRunStepMapper;
use OCA\OpenRegister\Db\FlowStream;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Computes the step-sequence base for a walk about to start.
 *
 * @spec openspec/changes/flow-object-attribution/specs/flow-object-attribution/spec.md
 */
class FlowStepHistory {
	/**
	 * Constructor.
	 *
	 * @param FlowRunStepMapper|null $steps  Reads the highest sequence already recorded.
	 *                                       Null where history is not being recorded at
	 *                                       all, in which case numbering starts at zero.
	 * @param LoggerInterface|null   $logger Records a failed read.
	 */
	public function __construct(
		private readonly ?FlowRunStepMapper $steps = null,
		private readonly ?LoggerInterface $logger = null,
	) {
	}//end __construct()

	/**
	 * The sequence the first step of this segment will carry.
	 *
	 * Continues from the highest already recorded, so a run that suspended and
	 * resumed days later reads as ONE ordered history rather than two
	 * interleaved ones starting at zero.
	 *
	 * A failure to read is not a reason to fail the run: the work is the run,
	 * and the numbering is the account of it. Attribution degrades to numbering
	 * from zero — wrong only in its offset, and still correctly ordering the
	 * run's writes among themselves.
	 *
	 * @param string $runUuid The run about to be walked.
	 *
	 * @return integer The base sequence.
	 *
	 * @spec openspec/changes/flow-object-attribution/specs/flow-object-attribution/spec.md
	 */
	public function baseFor(string $runUuid): int {
		if ($this->steps === null || trim($runUuid) === '') {
			return 0;
		}

		try {
			return ($this->steps->highestSequence(runUuid: $runUuid) + 1);
		} catch (Throwable $e) {
			$this->logger?->warning(
				message: '[FlowStepHistory] Could not read the step sequence base for attribution: '
					. $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);

			return 0;
		}
	}//end baseFor()

	/**
	 * Write one step row per node execution in this segment.
	 *
	 * The aggregate `log` column answers "what happened in this run" and
	 * nothing else — "which node type fails", "every failed step for this
	 * flow", "what did node X output" all require loading and walking every
	 * run's blob. One row per hop makes those queryable, and gives retention
	 * something it can prune per flow.
	 *
	 * Sequence CONTINUES from the highest already recorded rather than
	 * restarting at zero, so a run that suspends on a wait node and resumes
	 * later reads as one ordered history instead of two interleaved ones.
	 *
	 * Failing to record history must never fail the run itself: the run is the
	 * work, the rows are the account of it.
	 *
	 * @param FlowRun $run The run these steps belong to.
	 * @param array<int, mixed> $entries The engine log entries for this segment.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
	 */
	public function record(FlowRun $run, array $entries): void {
		if ($this->steps === null || empty($entries) === true) {
			return;
		}

		$runUuid = (string)$run->getUuid();

		// The SAME computation the attribution used before the walk. One method,
		// so the number an audit row was stamped with and the number its step row
		// is given cannot drift apart.
		$sequence = $this->baseFor(runUuid: $runUuid);

		foreach ($entries as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			// A firing committed by FlowRunCommit already has its step row,
			// written inside the firing's own transaction at the stream's
			// position. Writing it again here would double every firing.
			if (($entry['recorded'] ?? false) === true) {
				continue;
			}

			$step = new FlowRunStep();
			$step->setRunUuid($runUuid);
			$step->setFlowId((string)$run->getFlowId());
			$step->setNodeId((string)($entry['transition'] ?? ''));
			$step->setNodeType(($entry['type'] ?? null));
			$step->setSequence($sequence);
			// Branch identity when the walk knew it (a suspension, a stop, a
			// terminal failure on a stream); a row from a pre-stream walk
			// carries the root path, the single implicit stream.
			$step->setStreamId(($entry['streamId'] ?? null));
			$step->setOrdinalPath((string)($entry['ordinalPath'] ?? FlowStream::ROOT_PATH));
			$step->setStatus((string)($entry['status'] ?? 'unknown'));
			$step->setDurationMs(($entry['durationMs'] ?? null));
			$step->setCreated(new DateTime());
			$step->setFinished(new DateTime());

			// `error` and `reason` are distinct outcomes that both belong in
			// the error column: a thrown step and a deliberately stopped one
			// are each something a person needs to read back.
			$step->setError(($entry['error'] ?? ($entry['reason'] ?? null)));

			// What the node produced, minus the items themselves — a step row
			// is an index into the run, not a second copy of its data.
			$step->setOutput(
				array_filter(
					[
						'itemsIn' => ($entry['itemsIn'] ?? null),
						'itemsOut' => ($entry['itemsOut'] ?? null),
						'checkId' => ($entry['checkId'] ?? null),
					],
					static fn ($v): bool => $v !== null
				)
			);

			try {
				$this->steps->insert($step);
			} catch (Throwable $e) {
				$this->logger->warning(
					message: '[FlowStepHistory] Could not record a step row for run ' . $runUuid . ': ' . $e->getMessage(),
					context: ['file' => __FILE__, 'line' => __LINE__]
				);
			}

			$sequence++;
		}//end foreach

	}//end record()
}//end class
