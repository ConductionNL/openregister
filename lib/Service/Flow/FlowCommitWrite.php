<?php

/**
 * The stream and step writes of one commit, bound to the locked run row.
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
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-the-run-log-must-be-ordered-by-branch-never-by-completion
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use DateTime;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunStep;
use OCA\OpenRegister\Db\FlowStream;

/**
 * One commit's writes: built by FlowRunCommit inside the run-row lock, for
 * that locked run and that moment, and dropped when the transaction ends.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) FlowStream's path helpers are pure functions on a value object.
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-a-run-records-what-each-node-received-returned-and-logged
 */
final class FlowCommitWrite {

	/**
	 * The commit's moment: every row this commit writes carries it.
	 *
	 * @var DateTime
	 */
	public readonly DateTime $now;

	/**
	 * The locked run's uuid.
	 *
	 * @var string
	 */
	private readonly string $runUuid;

	/**
	 * The mappers of the transaction.
	 *
	 * @var FlowRunCommitRows
	 */
	private readonly FlowRunCommitRows $rows;

	/**
	 * The locked run row.
	 *
	 * @var FlowRun
	 */
	private readonly FlowRun $run;

	/**
	 * Bind the writes to the locked run, at this moment.
	 *
	 * @param FlowRunCommitRows $rows The mappers of the transaction.
	 * @param FlowRun           $run  The run row read inside the lock.
	 */
	public function __construct(FlowRunCommitRows $rows, FlowRun $run) {
		$this->rows = $rows;
		$this->run = $run;
		$this->runUuid = (string)$run->getUuid();
		$this->now = new DateTime();
	}//end __construct()

	/**
	 * Mint a stream row the walk has been carrying in memory only.
	 *
	 * The root of a fresh run, or a branch of a pre-stream run, exists in the
	 * walk before it has fired anything; its row is written by the first
	 * commit that names it. `next_sequence` continues the run's history.
	 *
	 * @param FlowStreamRef $ref The stream's id, ordinal path, parent stream id and token place.
	 *
	 * @return FlowStream The inserted stream.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-run-records-what-each-node-received-returned-and-logged
	 */
	public function mintStream(FlowStreamRef $ref): FlowStream {
		$stream = new FlowStream();
		$stream->setRunUuid($this->runUuid);
		$stream->setStreamId($ref->streamId);
		$stream->setOrdinalPath($ref->path);
		$stream->setParentStreamId($ref->parent);
		$stream->setPlace($ref->place);
		$stream->setStatus(FlowRun::STATUS_RUNNING);
		$stream->setNextSequence(($this->rows->steps->highestSequence(runUuid: $this->runUuid) + 1));
		$stream->setCreated($this->now);
		$stream->setUpdated($this->now);

		return $this->rows->streams->insert($stream);
	}//end mintStream()

	/**
	 * The stream that carries the token onward: the firing stream, or — for a
	 * join consuming several streams — the stream at their longest common
	 * prefix, resumed. Every other consumed stream is completed.
	 *
	 * @param array<string, FlowStream> $streams The run's streams by id, the firing stream among them.
	 * @param FlowFiring                $firing  The firing.
	 *
	 * @return FlowStream The carrier.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-the-run-log-must-be-ordered-by-branch-never-by-completion
	 */
	public function foldCarrier(array $streams, FlowFiring $firing): FlowStream {
		$stream = $streams[$firing->streamId];
		$consumed = [];
		foreach ($firing->getConsumedStreamIds() as $id) {
			if (isset($streams[$id]) === true) {
				$consumed[$id] = $streams[$id];
			}
		}

		$consumed[(string)$stream->getStreamId()] = $stream;
		if (count($consumed) <= 1) {
			return $stream;
		}

		$carrier = (self::atPrefix(streams: $streams, consumed: $consumed) ?? $stream);
		unset($consumed[(string)$carrier->getStreamId()]);
		foreach ($consumed as $ended) {
			$ended->setStatus(FlowRun::STATUS_COMPLETED);
			$ended->setUpdated($this->now);
			$this->rows->streams->update($ended);
		}

		return $carrier;
	}//end foldCarrier()

	/**
	 * The stream at the longest common prefix of the consumed streams' paths.
	 *
	 * @param array<string, FlowStream> $streams  The run's streams by id.
	 * @param array<string, FlowStream> $consumed The streams a join consumes.
	 *
	 * @return FlowStream|null The stream at the prefix, null when none is there.
	 */
	private static function atPrefix(array $streams, array $consumed): ?FlowStream {
		$prefix = FlowStream::commonPrefix(paths: array_map(static fn (FlowStream $s): string => (string)$s->getOrdinalPath(), array_values($consumed)));
		foreach ($streams as $candidate) {
			if ((string)$candidate->getOrdinalPath() === $prefix) {
				return $candidate;
			}
		}

		return null;
	}//end atPrefix()

	/**
	 * Insert the firing's step row at the carrier's next position.
	 *
	 * Allocation is the conditional-UPDATE shape, inside this transaction.
	 *
	 * @param FlowStream $carrier The carrier stream.
	 * @param array      $entry   The engine's log entry.
	 *
	 * @return FlowRunStep The row as written.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-run-records-what-each-node-received-returned-and-logged
	 */
	public function appendStep(FlowStream $carrier, array $entry): FlowRunStep {
		$sequence = $this->rows->streams->allocateNextSequence(runUuid: $this->runUuid, streamId: (string)$carrier->getStreamId());
		$step = new FlowRunStep();
		$step->setRunUuid($this->runUuid);
		$step->setFlowId((string)$this->run->getFlowId());
		$step->setNodeId((string)($entry['transition'] ?? ''));
		$step->setNodeType(($entry['type'] ?? null));
		$step->setSequence($sequence);
		$step->setStreamId((string)$carrier->getStreamId());
		$step->setOrdinalPath((string)$carrier->getOrdinalPath());
		$step->setStatus((string)($entry['status'] ?? 'unknown'));
		$step->setDurationMs(($entry['durationMs'] ?? null));
		$step->setCreated($this->now);
		$step->setFinished($this->now);
		FlowStepOutcome::stamp(step: $step, entry: $entry);
		$this->rows->steps->insert($step);

		return $step;
	}//end appendStep()

	/**
	 * Continue or split the carrier stream.
	 *
	 * K taken outputs: K == 1 continues the carrier onto the taken place;
	 * K == 0 completes it (the token was consumed and nothing produced);
	 * K > 1 completes it and mints `parent.0001 … parent.000K` in `getTos()`
	 * declaration order.
	 *
	 * @param FlowFiring $firing  The firing.
	 * @param FlowStream $carrier The carrier stream (already positioned).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-the-run-log-must-be-ordered-by-branch-never-by-completion
	 */
	public function settleStreams(FlowFiring $firing, FlowStream $carrier): void {
		$firing->carrierStreamId = (string)$carrier->getStreamId();
		$firing->childStreamIds = [];

		if (count($firing->taken) <= 1) {
			$this->continueCarrier(firing: $firing, carrier: $carrier);
			return;
		}

		// A split: the carrier completes, K children begin.
		$carrier->setStatus(FlowRun::STATUS_COMPLETED);
		$carrier->setUpdated($this->now);
		$this->rows->streams->update($carrier);
		$index = 1;
		foreach (array_values($firing->taken) as $to) {
			$path = FlowStream::childPath(parentPath: (string)$carrier->getOrdinalPath(), index: $index);
			$child = new FlowStreamRef(
				streamId: FlowRunCommit::streamIdFor(runUuid: $this->runUuid, path: $path),
				path: $path,
				parent: (string)$carrier->getStreamId(),
				place: $to
			);
			$firing->childStreamIds[$to] = $child->streamId;
			$this->beginChild(child: $child);
			$index++;
		}
	}//end settleStreams()

	/**
	 * Continue the carrier onto the one taken place, or end it when none was taken.
	 *
	 * @param FlowFiring $firing  The firing.
	 * @param FlowStream $carrier The carrier stream.
	 *
	 * @return void
	 */
	private function continueCarrier(FlowFiring $firing, FlowStream $carrier): void {
		$taken = array_values($firing->taken);
		$carrier->setStatus($firing->getStreamStatus());
		$carrier->setError($firing->getStreamError());
		$carrier->setResumeAt(null);
		$carrier->setPlace(($taken[0] ?? null));
		if ($taken === []) {
			// Nothing produced: the token is consumed and this branch ends.
			$carrier->setStatus(FlowRun::STATUS_COMPLETED);
		}

		$carrier->setUpdated($this->now);
		$this->rows->streams->update($carrier);
	}//end continueCarrier()

	/**
	 * Queue one child of a split on its place.
	 *
	 * A loop back through the same split re-uses the child row: its history
	 * continues rather than restarting.
	 *
	 * @param FlowStreamRef $child The child's id, path, parent stream id and place.
	 *
	 * @return void
	 */
	private function beginChild(FlowStreamRef $child): void {
		$existing = $this->rows->streams->findByRunAndStream(runUuid: $this->runUuid, streamId: $child->streamId);
		if ($existing !== null) {
			$existing->setStatus(FlowRun::STATUS_QUEUED);
			$existing->setPlace($child->place);
			$existing->setError(null);
			$existing->setResumeAt(null);
			$existing->setUpdated($this->now);
			$this->rows->streams->update($existing);
			return;
		}

		$this->rows->streams->insert($this->childRow(child: $child));
	}//end beginChild()

	/**
	 * A new, queued stream row for one child of a split.
	 *
	 * @param FlowStreamRef $child The child's id, path, parent stream id and place.
	 *
	 * @return FlowStream The row to insert.
	 */
	private function childRow(FlowStreamRef $child): FlowStream {
		$stream = new FlowStream();
		$stream->setRunUuid($this->runUuid);
		$stream->setStreamId($child->streamId);
		$stream->setOrdinalPath($child->path);
		$stream->setParentStreamId($child->parent);
		$stream->setPlace($child->place);
		$stream->setStatus(FlowRun::STATUS_QUEUED);
		$stream->setNextSequence(1);
		$stream->setCreated($this->now);
		$stream->setUpdated($this->now);

		return $stream;
	}//end childRow()
}//end class
