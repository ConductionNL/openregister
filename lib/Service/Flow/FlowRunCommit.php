<?php

/**
 * The commit path: one firing's effect, written as a DELTA under the run-row
 * lock, with the step row, the stream rows and the firing count in the same
 * transaction.
 *
 * Two mechanisms, doing two different jobs. The run-row lock gives ATOMICITY:
 * every value written is computed from the row read INSIDE the lock, and the
 * delta itself — "remove `a`, add `c`" — mentions no other place, so committing
 * it cannot disturb one. The claim (FlowPlaceClaims) gives EXCLUSION for the
 * side effect, which a transaction cannot roll back; that is why dispatch
 * happens entirely OUTSIDE this class and the critical section holds no I/O
 * and no user code.
 *
 * Only a writer holding the run-row lock may declare a run parked or terminal,
 * and only from the marking it has just written — `finalize()` is that writer.
 * Its own claims are released INSIDE the same transaction, so whichever pass
 * locks last sees the truth and no run is ever left `running` with no claim.
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
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use DateTime;
use OCA\OpenRegister\Db\FlowClaimMapper;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\FlowRunStep;
use OCA\OpenRegister\Db\FlowRunStepMapper;
use OCA\OpenRegister\Db\FlowStream;
use OCA\OpenRegister\Db\FlowStreamMapper;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Commits firings and derives run status, under the run-row lock.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The commit joins the run, its
 * steps, its streams and its claims in ONE transaction; each mapper is one of
 * the rows that transaction must write together.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Continue, split, join, park, end and
 * finalize are each one branch of a token's life, and each must be in the same lock.
 * @SuppressWarnings(PHPMD.StaticAccess) FlowStream's path helpers are pure functions on a value object.
 */
class FlowRunCommit {

	/**
	 * Severity order for the terminal projection: the most severe wins.
	 */
	private const SEVERITY = [
		FlowRun::STATUS_FAILED => 4,
		FlowRun::STATUS_DEAD_LETTER => 3,
		FlowRun::STATUS_STOPPED => 2,
		FlowRun::STATUS_COMPLETED => 1,
	];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The connection whose transaction is the critical section.
	 * @param FlowRunMapper $runs The run rows (locked for update).
	 * @param FlowStreamMapper $streams The stream rows.
	 * @param FlowClaimMapper $claims The claim rows.
	 * @param FlowRunStepMapper $steps The step rows.
	 * @param LoggerInterface $logger Logger for rollbacks.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly FlowRunMapper $runs,
		private readonly FlowStreamMapper $streams,
		private readonly FlowClaimMapper $claims,
		private readonly FlowRunStepMapper $steps,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Commit one firing.
	 *
	 * Inside one transaction: lock the run row, re-read the marking, apply the
	 * delta (one token off each `from`, one onto each TAKEN `to`), write the
	 * per-place items for the same places, insert the step row at the stream's
	 * next position, update the stream rows (continue, split into children, or
	 * fold a join back onto the common-prefix stream), increment `firings`, and
	 * write the run's derived status. The claims the firing held are released
	 * in the same transaction.
	 *
	 * The entity handed in is refreshed from the committed row afterwards and
	 * its updated-field tracking reset, so a later whole-entity update by the
	 * run service cannot carry a stale marking over what was just committed.
	 *
	 * @param FlowRun $run The run (refreshed in place).
	 * @param FlowFiring $firing What fired, on which stream, with what effect.
	 * @param string $owner The pass token whose claims on the firing's places are released.
	 *
	 * @return FlowFiringResult The committed marking, place items, and the streams now live.
	 *
	 * @throws Throwable Rolls back and rethrows on any failure.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
	 */
	public function commitFiring(FlowRun $run, FlowFiring $firing, string $owner): FlowFiringResult {
		$uuid = (string)$run->getUuid();
		$now = new DateTime();

		$this->db->beginTransaction();
		try {
			$locked = $this->runs->lockByUuid(uuid: $uuid);

			// THE DELTA, against the value read inside the lock.
			$marking = self::normaliseMarking(places: ($locked->getMarking() ?? []));
			foreach ($firing->froms as $from) {
				$marking[$from] = (($marking[$from] ?? 0) - 1);
				if ($marking[$from] <= 0) {
					unset($marking[$from]);
				}
			}

			foreach ($firing->taken as $to) {
				$marking[$to] = (($marking[$to] ?? 0) + 1);
			}

			// The per-place items move with the tokens, in the same write.
			$placeItems = (array)($locked->getPlaceItems() ?? []);
			foreach ($firing->froms as $from) {
				unset($placeItems[$from]);
			}

			foreach ($firing->taken as $to) {
				$placeItems[$to] = ($firing->itemsByPlace[$to] ?? []);
			}

			// STREAMS. The firing stream continues, splits, or is folded.
			$streams = $this->indexByStreamId(streams: $this->streams->findByRun(runUuid: $uuid));
			$stream = ($streams[$firing->streamId] ?? null);
			if ($stream === null) {
				$stream = $this->mintStream(
					runUuid: $uuid,
					streamId: $firing->streamId,
					path: $firing->streamPath,
					parent: $firing->streamParent,
					place: ($firing->froms[0] ?? null),
					now: $now
				);
				$streams[$firing->streamId] = $stream;
			}

			// The step row, positioned WITHIN the stream. Allocation is the
			// conditional-UPDATE shape, inside this transaction.
			$sequence = $this->streams->allocateNextSequence(runUuid: $uuid, streamId: (string)$stream->getStreamId());
			$stream = $this->streams->findByRunAndStream(runUuid: $uuid, streamId: (string)$stream->getStreamId()) ?? $stream;
			$streams[$firing->streamId] = $stream;
			$this->insertStep(run: $locked, stream: $stream, sequence: $sequence, entry: $firing->logEntry, now: $now);

			$this->settleStreams(
				uuid: $uuid,
				streams: $streams,
				firing: $firing,
				stream: $stream,
				now: $now
			);

			// The run row: marking, place items, firings + 1, derived status.
			$locked->setMarking($marking);
			$locked->setPlaceItems($placeItems);
			$locked->setFirings(((int)($locked->getFirings() ?? 0) + 1));
			$locked->setUpdated($now);

			// Release this firing's claims inside the lock, then derive.
			$this->claims->release(runUuid: $uuid, places: $firing->claimedPlaces);
			$live = $this->streams->findByRun(runUuid: $uuid);
			$this->applyDerivedStatus(run: $locked, streams: $live, enabled: $firing->enabledAfter, owner: $owner);

			$this->runs->update($locked);
			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollBack();
			$this->logger->error(
				message: '[FlowRunCommit] Firing commit rolled back',
				context: ['file' => __FILE__, 'line' => __LINE__, 'run' => $uuid, 'transition' => $firing->transition, 'error' => $e->getMessage()]
			);
			throw $e;
		}//end try

		$this->refresh(run: $run, from: $locked);

		return new FlowFiringResult(
			marking: $marking,
			placeItems: $placeItems,
			streams: $live,
			firings: (int)$locked->getFirings()
		);
	}//end commitFiring()

	/**
	 * Park one stream: suspended with its wake time, claims released, run
	 * status re-derived. The marking is untouched — the stream resumes ON the
	 * transition that suspended it.
	 *
	 * @param FlowRun $run The run (refreshed in place).
	 * @param string $streamId The stream that raised the suspension.
	 * @param DateTime|null $resumeAt Its wake time; null while waiting on a signal.
	 * @param string $reason Why it parked.
	 * @param array<int, string> $claimedPlaces The claims to release.
	 * @param string $owner The pass token.
	 * @param bool $enabled Whether any transition is enabled after this park.
	 * @param string $path The stream's ordinal path, to mint its row when none exists yet.
	 * @param string|null $parent The stream's parent id, for the same minting.
	 * @param string|null $place The place holding the stream's token, for the same minting.
	 *
	 * @return array<int, FlowStream> The streams now live.
	 *
	 * @throws Throwable Rolls back and rethrows on any failure.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) The stream's descriptor rides along so the row can be minted in the same lock.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function park(
		FlowRun $run,
		string $streamId,
		?DateTime $resumeAt,
		string $reason,
		array $claimedPlaces,
		string $owner,
		bool $enabled,
		string $path = FlowStream::ROOT_PATH,
		?string $parent = null,
		?string $place = null,
	): array {
		$uuid = (string)$run->getUuid();
		$now = new DateTime();

		$this->db->beginTransaction();
		try {
			$locked = $this->runs->lockByUuid(uuid: $uuid);
			$stream = $this->streams->findByRunAndStream(runUuid: $uuid, streamId: $streamId)
				?? $this->mintStream(runUuid: $uuid, streamId: $streamId, path: $path, parent: $parent, place: $place, now: $now);
			$stream->setStatus(FlowRun::STATUS_SUSPENDED);
			$stream->setResumeAt($resumeAt);
			$stream->setError($reason);
			$stream->setUpdated($now);
			$this->streams->update($stream);

			$this->claims->release(runUuid: $uuid, places: $claimedPlaces);
			$live = $this->streams->findByRun(runUuid: $uuid);
			$this->applyDerivedStatus(run: $locked, streams: $live, enabled: $enabled, owner: $owner);
			$locked->setUpdated($now);
			$this->runs->update($locked);
			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}//end try

		$this->refresh(run: $run, from: $locked);

		return $live;
	}//end park()

	/**
	 * End a stream terminally (failed, stopped, dead-lettered) and re-derive.
	 *
	 * @param FlowRun $run The run (refreshed in place).
	 * @param string $streamId The stream.
	 * @param string $status One of FlowRun::TERMINAL.
	 * @param string|null $error The reason, when any.
	 * @param array<int, string> $claimedPlaces The claims to release.
	 * @param string $owner The pass token.
	 * @param bool $enabled Whether any transition is enabled afterwards.
	 * @param string $path The stream's ordinal path, to mint its row when none exists yet.
	 * @param string|null $parent The stream's parent id, for the same minting.
	 * @param string|null $place The place holding the stream's token, for the same minting.
	 *
	 * @return array<int, FlowStream> The streams now live.
	 *
	 * @throws Throwable Rolls back and rethrows on any failure.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) The stream's descriptor rides along so the row can be minted in the same lock.
	 */
	public function endStream(
		FlowRun $run,
		string $streamId,
		string $status,
		?string $error,
		array $claimedPlaces,
		string $owner,
		bool $enabled,
		string $path = FlowStream::ROOT_PATH,
		?string $parent = null,
		?string $place = null,
	): array {
		$uuid = (string)$run->getUuid();
		$now = new DateTime();

		$this->db->beginTransaction();
		try {
			$locked = $this->runs->lockByUuid(uuid: $uuid);
			$stream = $this->streams->findByRunAndStream(runUuid: $uuid, streamId: $streamId)
				?? $this->mintStream(runUuid: $uuid, streamId: $streamId, path: $path, parent: $parent, place: $place, now: $now);
			$stream->setStatus($status);
			$stream->setError($error);
			$stream->setUpdated($now);
			$this->streams->update($stream);

			$this->claims->release(runUuid: $uuid, places: $claimedPlaces);
			$live = $this->streams->findByRun(runUuid: $uuid);
			$this->applyDerivedStatus(run: $locked, streams: $live, enabled: $enabled, owner: $owner);
			$locked->setUpdated($now);
			$this->runs->update($locked);
			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}//end try

		$this->refresh(run: $run, from: $locked);

		return $live;
	}//end endStream()

	/**
	 * The pass's last word on a run: release everything this pass still
	 * holds, then derive the run's status from its streams and the marking —
	 * all under the lock, so whichever pass locks last sees the truth.
	 *
	 * Terminality is decided HERE, never by a worker's own loop running dry:
	 * an enabled-but-unclaimed transition leaves the run `queued`, which the
	 * next pass drains — a missed pickup is latency, never a lost wake-up.
	 *
	 * @param FlowRun $run The run (refreshed in place).
	 * @param string $owner The pass token whose claims are released.
	 * @param bool $enabled Whether any transition is enabled on the committed marking.
	 * @param string|null $forcedTerminal A run-level terminal outcome (stop, dead-letter, failure) that overrides the projection.
	 *
	 * @return string The derived status.
	 *
	 * @throws Throwable Rolls back and rethrows on any failure.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-runs-status-must-stay-derivable-from-its-streams-with-no-new-value
	 */
	public function finalize(FlowRun $run, string $owner, bool $enabled, ?string $forcedTerminal = null): string {
		$uuid = (string)$run->getUuid();
		$now = new DateTime();

		$this->db->beginTransaction();
		try {
			$locked = $this->runs->lockByUuid(uuid: $uuid);
			$this->claims->releaseByOwner(runUuid: $uuid, owner: $owner);
			$live = $this->streams->findByRun(runUuid: $uuid);

			if ($forcedTerminal !== null) {
				// A run-level end: every non-terminal stream ends with it, so no
				// branch begins a firing after the run has been told to stop.
				foreach ($live as $stream) {
					if ($stream->isTerminal() === false) {
						$stream->setStatus($forcedTerminal);
						$stream->setUpdated($now);
						$this->streams->update($stream);
					}
				}

				$live = $this->streams->findByRun(runUuid: $uuid);
			}

			$this->applyDerivedStatus(run: $locked, streams: $live, enabled: $enabled, owner: $owner);
			$locked->setUpdated($now);
			$this->runs->update($locked);
			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}//end try

		$this->refresh(run: $run, from: $locked);

		return (string)$locked->getStatus();
	}//end finalize()

	/**
	 * The status projection of Decision 7, applied to the locked run row.
	 *
	 * - `running` while any stream holds a live claim (another pass is inside a firing)
	 * - else `queued` while any transition is enabled
	 * - else `suspended` when any stream is parked, with `resume_at` the MIN
	 *   over NON-NULL wake times (null only when every parked stream waits on a signal)
	 * - else the most severe terminal among the streams
	 *
	 * No eighth value is added; `awaiting_consent` is left to the consent gate
	 * that owns it and is never produced here.
	 *
	 * @param FlowRun $run The locked run row.
	 * @param array<int, FlowStream> $streams The run's streams.
	 * @param bool $enabled Whether any transition is enabled.
	 * @param string $owner The pass whose claims do not count as foreign.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) The projection table, one branch per row.
	 * @SuppressWarnings(PHPMD.NPathComplexity) The same table.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-runs-status-must-stay-derivable-from-its-streams-with-no-new-value
	 */
	private function applyDerivedStatus(FlowRun $run, array $streams, bool $enabled, string $owner): void {
		$foreign = 0;
		foreach ($this->claims->findByRun(runUuid: (string)$run->getUuid()) as $claim) {
			if ($claim->getOwner() !== $owner) {
				$foreign++;
			}
		}

		if ($foreign > 0) {
			$run->setStatus(FlowRun::STATUS_RUNNING);
			$run->setResumeAt(null);
			return;
		}

		if ($enabled === true) {
			$run->setStatus(FlowRun::STATUS_QUEUED);
			$run->setResumeAt(null);
			return;
		}

		$parked = false;
		$earliest = null;
		$severest = null;
		foreach ($streams as $stream) {
			if ($stream->getStatus() === FlowRun::STATUS_SUSPENDED) {
				$parked = true;
				$wake = $stream->getResumeAt();
				if ($wake !== null && ($earliest === null || $wake < $earliest)) {
					$earliest = $wake;
				}

				continue;
			}

			if ($stream->isTerminal() === true) {
				$rank = (self::SEVERITY[(string)$stream->getStatus()] ?? 0);
				if ($severest === null || $rank > (self::SEVERITY[$severest] ?? 0)) {
					$severest = (string)$stream->getStatus();
				}
			}
		}

		if ($parked === true) {
			$run->setStatus(FlowRun::STATUS_SUSPENDED);
			$run->setResumeAt($earliest);
			return;
		}

		$run->setStatus($severest ?? FlowRun::STATUS_COMPLETED);
		$run->setResumeAt(null);
	}//end applyDerivedStatus()

	/**
	 * Continue, split or fold the firing's stream rows.
	 *
	 * K taken outputs: K == 1 continues the stream; K > 1 completes it and
	 * mints `parent.0001 … parent.000K` in `getTos()` declaration order. A
	 * join (several input streams) folds them onto their longest common
	 * prefix — the stream row with that path, resumed — and completes the
	 * others.
	 *
	 * @param string $uuid The run.
	 * @param array<string, FlowStream> $streams The run's streams by id.
	 * @param FlowFiring $firing The firing.
	 * @param FlowStream $stream The firing stream (already positioned).
	 * @param DateTime $now Now.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Continue, split and join are three shapes of one settlement.
	 * @SuppressWarnings(PHPMD.NPathComplexity) The same three shapes.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-the-run-log-must-be-ordered-by-branch-never-by-completion
	 */
	private function settleStreams(string $uuid, array $streams, FlowFiring $firing, FlowStream $stream, DateTime $now): void {
		// A join: fold every consumed stream onto the common prefix.
		$consumed = [];
		foreach ($firing->consumedStreamIds as $id) {
			if (isset($streams[$id]) === true) {
				$consumed[$id] = $streams[$id];
			}
		}

		$consumed[(string)$stream->getStreamId()] = $stream;
		$carrier = $stream;
		if (count($consumed) > 1) {
			$prefix = FlowStream::commonPrefix(array_map(static fn (FlowStream $s): string => (string)$s->getOrdinalPath(), array_values($consumed)));
			$carrier = null;
			foreach ($streams as $candidate) {
				if ((string)$candidate->getOrdinalPath() === $prefix) {
					$carrier = $candidate;
					break;
				}
			}

			$carrier ??= $stream;
			foreach ($consumed as $id => $ended) {
				if ($id === (string)$carrier->getStreamId()) {
					continue;
				}

				$ended->setStatus(FlowRun::STATUS_COMPLETED);
				$ended->setUpdated($now);
				$this->streams->update($ended);
			}
		}

		$taken = array_values($firing->taken);
		if (count($taken) <= 1) {
			$carrier->setStatus($firing->streamStatus);
			$carrier->setError($firing->streamError);
			$carrier->setResumeAt(null);
			$carrier->setPlace(($taken[0] ?? null));
			if ($taken === []) {
				// Nothing produced: the token is consumed and this branch ends.
				$carrier->setStatus(FlowRun::STATUS_COMPLETED);
			}
			$carrier->setUpdated($now);
			$this->streams->update($carrier);
			$firing->carrierStreamId = (string)$carrier->getStreamId();
			$firing->childStreamIds = [];
			return;
		}

		// A split: the carrier completes, K children begin.
		$carrier->setStatus(FlowRun::STATUS_COMPLETED);
		$carrier->setUpdated($now);
		$this->streams->update($carrier);
		$firing->carrierStreamId = (string)$carrier->getStreamId();
		$firing->childStreamIds = [];
		$index = 1;
		foreach ($taken as $to) {
			$path = FlowStream::childPath(parentPath: (string)$carrier->getOrdinalPath(), index: $index);
			$childId = self::streamIdFor(runUuid: $uuid, path: $path);
			$firing->childStreamIds[$to] = $childId;
			$index++;

			// A loop back through the same split re-uses the child row: its
			// history continues rather than restarting.
			$existing = $this->streams->findByRunAndStream(runUuid: $uuid, streamId: $childId);
			if ($existing !== null) {
				$existing->setStatus(FlowRun::STATUS_QUEUED);
				$existing->setPlace($to);
				$existing->setError(null);
				$existing->setResumeAt(null);
				$existing->setUpdated($now);
				$this->streams->update($existing);
				continue;
			}

			$child = new FlowStream();
			$child->setRunUuid($uuid);
			$child->setStreamId($childId);
			$child->setOrdinalPath($path);
			$child->setParentStreamId((string)$carrier->getStreamId());
			$child->setPlace($to);
			$child->setStatus(FlowRun::STATUS_QUEUED);
			$child->setNextSequence(1);
			$child->setCreated($now);
			$child->setUpdated($now);
			$this->streams->insert($child);
		}//end foreach
	}//end settleStreams()

	/**
	 * A deterministic stream id for a path within a run.
	 *
	 * @param string $runUuid The run.
	 * @param string $path The ordinal path.
	 *
	 * @return string The stream id.
	 */
	public static function streamIdFor(string $runUuid, string $path): string {
		return substr(sha1($runUuid . '|' . $path), 0, 32);
	}//end streamIdFor()

	/**
	 * Mint a stream row the walk has been carrying in memory only.
	 *
	 * The root of a fresh run, or a branch of a pre-stream run, exists in the
	 * walk before it has fired anything; its row is written by the first
	 * commit that names it. `next_sequence` continues the run's history.
	 *
	 * @param string $runUuid The run.
	 * @param string $streamId The stream's id.
	 * @param string $path Its ordinal path.
	 * @param string|null $parent Its parent stream id.
	 * @param string|null $place The place holding its token.
	 * @param DateTime $now Now.
	 *
	 * @return FlowStream The inserted stream.
	 */
	private function mintStream(string $runUuid, string $streamId, string $path, ?string $parent, ?string $place, DateTime $now): FlowStream {
		$stream = new FlowStream();
		$stream->setRunUuid($runUuid);
		$stream->setStreamId($streamId);
		$stream->setOrdinalPath($path);
		$stream->setParentStreamId($parent);
		$stream->setPlace($place);
		$stream->setStatus(FlowRun::STATUS_RUNNING);
		$stream->setNextSequence(($this->steps->highestSequence(runUuid: $runUuid) + 1));
		$stream->setCreated($now);
		$stream->setUpdated($now);

		return $this->streams->insert($stream);
	}//end mintStream()

	/**
	 * Insert the firing's step row at its stream position.
	 *
	 * @param FlowRun $run The locked run.
	 * @param FlowStream $stream The stream.
	 * @param int $sequence The position within the stream.
	 * @param array $entry The engine's log entry.
	 * @param DateTime $now Now.
	 *
	 * @return void
	 */
	private function insertStep(FlowRun $run, FlowStream $stream, int $sequence, array $entry, DateTime $now): void {
		$step = new FlowRunStep();
		$step->setRunUuid((string)$run->getUuid());
		$step->setFlowId((string)$run->getFlowId());
		$step->setNodeId((string)($entry['transition'] ?? ''));
		$step->setNodeType(($entry['type'] ?? null));
		$step->setSequence($sequence);
		$step->setStreamId((string)$stream->getStreamId());
		$step->setOrdinalPath((string)$stream->getOrdinalPath());
		$step->setStatus((string)($entry['status'] ?? 'unknown'));
		$step->setDurationMs(($entry['durationMs'] ?? null));
		$step->setCreated($now);
		$step->setFinished($now);
		$step->setError(($entry['error'] ?? ($entry['reason'] ?? null)));
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
		$this->steps->insert($step);
	}//end insertStep()

	/**
	 * Copy the committed row's values onto the caller's entity and reset its
	 * change tracking, so a later whole-entity update writes none of them.
	 *
	 * @param FlowRun $run The caller's entity.
	 * @param FlowRun $from The committed row.
	 *
	 * @return void
	 */
	private function refresh(FlowRun $run, FlowRun $from): void {
		$run->setMarking($from->getMarking());
		$run->setPlaceItems($from->getPlaceItems());
		$run->setFirings($from->getFirings());
		$run->setStatus($from->getStatus());
		$run->setResumeAt($from->getResumeAt());
		$run->resetUpdatedFields();
	}//end refresh()

	/**
	 * Streams keyed by id.
	 *
	 * @param array<int, FlowStream> $streams The streams.
	 *
	 * @return array<string, FlowStream> By id.
	 */
	private function indexByStreamId(array $streams): array {
		$byId = [];
		foreach ($streams as $stream) {
			$byId[(string)$stream->getStreamId()] = $stream;
		}

		return $byId;
	}//end indexByStreamId()

	/**
	 * A stored marking as `place => tokens`.
	 *
	 * @param mixed $places The raw value.
	 *
	 * @return array<string, int> The normalised marking.
	 */
	public static function normaliseMarking(mixed $places): array {
		if (is_array($places) === false) {
			return [];
		}

		$normalised = [];
		foreach ($places as $key => $value) {
			if (is_int($key) === true) {
				$normalised[(string)$value] = 1;
				continue;
			}

			$normalised[(string)$key] = max(1, (int)$value);
		}

		return $normalised;
	}//end normaliseMarking()
}//end class
