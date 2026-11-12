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
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunStep;
use OCA\OpenRegister\Db\FlowStream;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionSecrets;
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
	 * @param IDBConnection     $db       The connection whose transaction is the critical section.
	 * @param FlowRunCommitRows $rows     The run, stream, claim and step rows.
	 * @param LoggerInterface   $logger   Logger for rollbacks.
	 * @param FlowHopAudit|null $hopAudit Audits each committed firing.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly FlowRunCommitRows $rows,
		private readonly LoggerInterface $logger,
		private readonly ?FlowHopAudit $hopAudit = null,
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
	 * @param FlowRun        $run    The run (refreshed in place).
	 * @param FlowPassFiring $firing What fired, on which stream, with what effect, and the pass token whose
	 *                               claims on the firing's places are released.
	 *
	 * @return FlowFiringResult The committed marking, place items, and the streams now live.
	 *
	 * @throws Throwable Rolls back and rethrows on any failure.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
	 */
	public function commitFiring(FlowRun $run, FlowPassFiring $firing): FlowFiringResult {
		return $this->commit(run: $run, commit: new FlowFiringCommitRun(firing: $firing->firing, release: $firing->release()));
	}//end commitFiring()

	/**
	 * Commit one firing in its own transaction, then refresh and audit.
	 *
	 * @param FlowRun             $run    The run (refreshed in place).
	 * @param FlowFiringCommitRun $commit The firing and what it releases.
	 *
	 * @return FlowFiringResult The committed marking, place items, and the streams now live.
	 *
	 * @throws Throwable Rolls back and rethrows on any failure.
	 */
	private function commit(FlowRun $run, FlowFiringCommitRun $commit): FlowFiringResult {
		$uuid = (string)$run->getUuid();

		$this->db->beginTransaction();
		try {
			$result = $this->writeFiring(commit: $commit, uuid: $uuid);
			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollBack();
			$this->logger->error(
				message: '[FlowRunCommit] Firing commit rolled back',
				context: ['file' => __FILE__, 'line' => __LINE__, 'run' => $uuid, 'transition' => $commit->firing->transition, 'error' => $e->getMessage()]
			);
			throw $e;
		}//end try

		$this->refresh(run: $run, from: $commit->locked);
		$this->hopAudit?->record(run: $run, step: $commit->step);

		return $result;
	}//end commit()

	/**
	 * The writes of one firing, inside the transaction and under the run-row lock.
	 *
	 * @param FlowFiringCommitRun $commit The firing; receives the locked row and the step row.
	 * @param string              $uuid   The run uuid.
	 *
	 * @return FlowFiringResult The committed marking, place items, and the streams now live.
	 */
	private function writeFiring(FlowFiringCommitRun $commit, string $uuid): FlowFiringResult {
		$locked = $this->rows->runs->lockByUuid(uuid: $uuid);
		$commit->locked = $locked;
		$write = new FlowCommitWrite(rows: $this->rows, run: $locked);

		// THE DELTA, against the value read inside the lock; the per-place
		// items move with the tokens, in the same write.
		$marking = self::markingAfter(marking: self::normaliseMarking(places: ($locked->getMarking() ?? [])), firing: $commit->firing);
		$placeItems = self::placeItemsAfter(placeItems: (array)($locked->getPlaceItems() ?? []), firing: $commit->firing);

		$commit->step = $this->writeStreams(commit: $commit, write: $write);

		// The run row: marking, place items, firings + 1, derived status.
		$locked->setMarking($marking);
		$locked->setPlaceItems($this->storedPlaceItems(run: $locked, placeItems: $placeItems));
		$locked->setFirings(((int)($locked->getFirings() ?? 0) + 1));
		$locked->setUpdated($write->now);

		// Release this firing's claims inside the lock, then derive.
		$this->rows->claims->release(runUuid: $uuid, places: $commit->release->claimedPlaces);
		$live = $this->applyDerivedStatus(run: $locked, release: $commit->release);
		$this->rows->runs->update($locked);

		return new FlowFiringResult(marking: $marking, placeItems: $placeItems, streams: $live, firings: (int)$locked->getFirings());
	}//end writeFiring()

	/**
	 * The stream rows of one firing: the firing stream continues, splits, or is folded.
	 *
	 * A join folds its input streams onto their common prefix FIRST, so the
	 * step row lands on the carrier at ITS next position — the split-and-join
	 * reads as one history with a fan-out in the middle.
	 *
	 * @param FlowFiringCommitRun $commit The firing, with the locked run row.
	 * @param FlowCommitWrite     $write  The writer bound to the locked row.
	 *
	 * @return FlowRunStep The step row, positioned WITHIN the carrier stream.
	 */
	private function writeStreams(FlowFiringCommitRun $commit, FlowCommitWrite $write): FlowRunStep {
		$uuid = (string)$commit->locked?->getUuid();
		$firing = $commit->firing;
		$streams = $this->indexByStreamId(streams: $this->rows->streams->findByRun(runUuid: $uuid));
		$streams[$firing->streamId] ??= $write->mintStream(ref: self::firingStreamRef(firing: $firing));

		$carrier = $write->foldCarrier(streams: $streams, firing: $firing);
		$step = $write->appendStep(carrier: $carrier, entry: $firing->getLogEntry());
		$carrier = $this->rows->streams->findByRunAndStream(runUuid: $uuid, streamId: (string)$carrier->getStreamId()) ?? $carrier;
		$write->settleStreams(firing: $firing, carrier: $carrier);

		return $step;
	}//end writeStreams()

	/**
	 * The descriptor a firing stream is minted from when it has no row yet.
	 *
	 * @param FlowFiring $firing What fired, on which stream.
	 *
	 * @return FlowStreamRef
	 */
	private static function firingStreamRef(FlowFiring $firing): FlowStreamRef {
		return new FlowStreamRef(
			streamId: $firing->streamId,
			path: $firing->getStreamPath(),
			parent: $firing->getStreamParent(),
			place: ($firing->froms[0] ?? null)
		);
	}//end firingStreamRef()

	/**
	 * The marking after a firing: one token off each consumed place, one on
	 * each taken place.
	 *
	 * @param array<string, int> $marking The marking read inside the lock.
	 * @param FlowFiring         $firing  The firing.
	 *
	 * @return array<string, int> The marking after.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
	 */
	private static function markingAfter(array $marking, FlowFiring $firing): array {
		foreach ($firing->froms as $from) {
			$marking[$from] = (($marking[$from] ?? 0) - 1);
			if ($marking[$from] <= 0) {
				unset($marking[$from]);
			}
		}

		foreach ($firing->taken as $to) {
			$marking[$to] = (($marking[$to] ?? 0) + 1);
		}

		return $marking;
	}//end markingAfter()

	/**
	 * The per-place items after a firing: the items move with the tokens.
	 *
	 * @param array<string, array> $placeItems The items read inside the lock.
	 * @param FlowFiring           $firing     The firing.
	 *
	 * @return array<string, array> The items after.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
	 */
	private static function placeItemsAfter(array $placeItems, FlowFiring $firing): array {
		foreach ($firing->froms as $from) {
			unset($placeItems[$from]);
		}

		foreach ($firing->getItemsByPlace() as $to => $items) {
			$placeItems[$to] = $items;
		}

		return $placeItems;
	}//end placeItemsAfter()

	/**
	 * Stop one stream and re-derive the run's status. A parked stream is
	 * suspended with its wake time and resumes ON the transition that
	 * suspended it, so the marking is untouched; an ended stream takes its
	 * terminal status (failed, stopped, dead-lettered). Either way the pass
	 * releases the stream's claims.
	 *
	 * @param FlowRun $run The run (refreshed in place).
	 * @param FlowStreamClosure $closure The stream, its new status, wake time,
	 *                                   reason and what the pass releases.
	 *
	 * @return array<int, FlowStream> The streams now live.
	 *
	 * @throws Throwable Rolls back and rethrows on any failure.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-runs-status-must-stay-derivable-from-its-streams-with-no-new-value
	 */
	public function closeStream(FlowRun $run, FlowStreamClosure $closure): array {
		$uuid = (string)$run->getUuid();
		$ref = $closure->stream;

		$this->db->beginTransaction();
		try {
			$locked = $this->rows->runs->lockByUuid(uuid: $uuid);
			$write = new FlowCommitWrite(rows: $this->rows, run: $locked);
			$now = $write->now;
			$stream = $this->rows->streams->findByRunAndStream(runUuid: $uuid, streamId: $ref->streamId)
				?? $write->mintStream(ref: $ref);
			$stream->setStatus($closure->status);
			if ($closure->parks() === true) {
				$stream->setResumeAt($closure->resumeAt());
			}

			$stream->setError($closure->error());
			$stream->setUpdated($now);
			$this->rows->streams->update($stream);

			$this->rows->claims->release(runUuid: $uuid, places: $closure->release->claimedPlaces);
			$live = $this->applyDerivedStatus(run: $locked, release: $closure->release);
			$locked->setUpdated($now);
			$this->rows->runs->update($locked);
			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}//end try

		$this->refresh(run: $run, from: $locked);

		return $live;
	}//end closeStream()

	/**
	 * The pass's last word on a run: release everything this pass still
	 * holds, then derive the run's status from its streams and the marking —
	 * all under the lock, so whichever pass locks last sees the truth.
	 *
	 * Terminality is decided HERE, never by a worker's own loop running dry:
	 * an enabled-but-unclaimed transition leaves the run `queued`, which the
	 * next pass drains — a missed pickup is latency, never a lost wake-up.
	 *
	 * @param FlowRun       $run    The run (refreshed in place).
	 * @param FlowRunEnding $ending Whose pass ends, how, and when.
	 *
	 * @return string The derived status.
	 *
	 * @throws Throwable Rolls back and rethrows on any failure.
	 */
	public function end(FlowRun $run, FlowRunEnding $ending): string {
		$this->db->beginTransaction();
		try {
			$locked = $this->rows->runs->lockByUuid(uuid: (string)$run->getUuid());
			$this->rows->claims->releaseByOwner(runUuid: (string)$run->getUuid(), owner: $ending->owner);
			$this->forceTerminal(run: $locked, ending: $ending);
			// A run told to end has no enabled work left to honour: the
			// projection then reads the (now uniformly terminal) streams.
			$this->applyDerivedStatus(run: $locked, release: $ending->release());
			$locked->setUpdated($ending->endedAt);
			$this->rows->runs->update($locked);
			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}//end try

		$this->refresh(run: $run, from: $locked);

		return (string)$locked->getStatus();
	}//end end()

	/**
	 * A run-level end: every non-terminal stream ends with it, so no branch
	 * begins a firing after the run has been told to stop.
	 *
	 * @param FlowRun       $run    The locked run row.
	 * @param FlowRunEnding $ending The ending; nothing happens without a forced terminal.
	 *
	 * @return void
	 */
	private function forceTerminal(FlowRun $run, FlowRunEnding $ending): void {
		if ($ending->forcedTerminal === null) {
			return;
		}

		foreach ($this->rows->streams->findByRun(runUuid: (string)$run->getUuid()) as $stream) {
			if ($stream->isTerminal() === false) {
				$stream->setStatus($ending->forcedTerminal);
				$stream->setUpdated($ending->endedAt);
				$this->rows->streams->update($stream);
			}
		}
	}//end forceTerminal()

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
	 * The streams are read here, after the caller's writes and claim release,
	 * so the projection sees what the transaction is about to commit.
	 *
	 * @param FlowRun           $run     The locked run row.
	 * @param FlowStreamRelease $release Whose claims do not count as foreign, and whether any transition is enabled.
	 *
	 * @return array<int, FlowStream> The run's live streams the status was derived from.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-runs-status-must-stay-derivable-from-its-streams-with-no-new-value
	 */
	private function applyDerivedStatus(FlowRun $run, FlowStreamRelease $release): array {
		$streams = $this->rows->streams->findByRun(runUuid: (string)$run->getUuid());
		$status = self::projectedStatus(streams: $streams, enabled: $release->enabled);
		if ($this->holdsForeignClaim(run: $run, owner: $release->owner) === true) {
			$status = FlowRun::STATUS_RUNNING;
		}

		$wake = null;
		if ($status === FlowRun::STATUS_SUSPENDED) {
			$wake = self::earliestWake(streams: $streams);
		}

		$run->setStatus($status);
		$run->setResumeAt($wake);

		return $streams;
	}//end applyDerivedStatus()

	/**
	 * Whether another pass holds a live claim on this run.
	 *
	 * @param FlowRun $run   The locked run row.
	 * @param string  $owner The pass whose claims do not count as foreign.
	 *
	 * @return bool
	 */
	private function holdsForeignClaim(FlowRun $run, string $owner): bool {
		foreach ($this->rows->claims->findByRun(runUuid: (string)$run->getUuid()) as $claim) {
			if ($claim->getOwner() !== $owner) {
				return true;
			}
		}

		return false;
	}//end holdsForeignClaim()

	/**
	 * The projection without claims: queued, suspended, or the severest terminal.
	 *
	 * @param array<int, FlowStream> $streams The run's streams.
	 * @param bool                   $enabled Whether any transition is enabled.
	 *
	 * @return string
	 */
	private static function projectedStatus(array $streams, bool $enabled): string {
		if ($enabled === true) {
			return FlowRun::STATUS_QUEUED;
		}

		foreach ($streams as $stream) {
			if ($stream->getStatus() === FlowRun::STATUS_SUSPENDED) {
				return FlowRun::STATUS_SUSPENDED;
			}
		}

		return self::severestTerminal(streams: $streams);
	}//end projectedStatus()

	/**
	 * The MIN over the NON-NULL wake times of the parked streams; null when
	 * every parked stream waits on a signal.
	 *
	 * @param array<int, FlowStream> $streams The run's streams.
	 *
	 * @return DateTime|null
	 */
	private static function earliestWake(array $streams): ?DateTime {
		$earliest = null;
		foreach ($streams as $stream) {
			$wake = $stream->getResumeAt();
			if ($stream->getStatus() === FlowRun::STATUS_SUSPENDED && $wake !== null && ($earliest === null || $wake < $earliest)) {
				$earliest = $wake;
			}
		}

		return $earliest;
	}//end earliestWake()

	/**
	 * The most severe terminal status among the streams; completed when none is terminal.
	 *
	 * @param array<int, FlowStream> $streams The run's streams.
	 *
	 * @return string
	 */
	private static function severestTerminal(array $streams): string {
		$severest = null;
		foreach ($streams as $stream) {
			$rank = (self::SEVERITY[(string)$stream->getStatus()] ?? 0);
			if ($stream->isTerminal() === true && ($severest === null || $rank > (self::SEVERITY[$severest] ?? 0))) {
				$severest = (string)$stream->getStatus();
			}
		}

		return $severest ?? FlowRun::STATUS_COMPLETED;
	}//end applyDerivedStatus()

	/**
	 * A deterministic stream id for a path within a run.
	 *
	 * @param string $runUuid The run.
	 * @param string $path The ordinal path.
	 *
	 * @return string The stream id.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-the-run-log-must-be-ordered-by-branch-never-by-completion
	 */
	public static function streamIdFor(string $runUuid, string $path): string {
		// NOSEMGREP: php.lang.security.weak-crypto.weak-crypto -- The hash only derives a stable identifier, not a security value.
		return substr(sha1($runUuid . '|' . $path), 0, 32);
	}//end streamIdFor()

	/**
	 * The per-place items as stored: a writeOnly action input held under the
	 * run's inputsRef is redacted at `json.inputs.<name>` and wherever a string
	 * equals it whole (a copy; four or more characters), so a resume reads the
	 * subject, timestamps and counters it stored. The walk
	 * carries on with the values this request still holds.
	 *
	 * @param FlowRun              $run        The locked run row.
	 * @param array<string, mixed> $placeItems The per-place items.
	 *
	 * @return array<string, mixed> The items to store.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectActionSecrets is the request-scoped secret holder.
	 */
	private function storedPlaceItems(FlowRun $run, array $placeItems): array {
		$ref = (($run->getContext() ?? [])[ObjectActionSecrets::CONTEXT_KEY] ?? null);

		foreach ($placeItems as $place => $items) {
			if (is_array($items) === true) {
				$items = ObjectActionSecrets::redactItems(items: $items, ref: $ref);
				// A whole-string copy a later step made, e.g. `json.code`.
				$placeItems[$place] = ObjectActionSecrets::redactCopies(data: $items, ref: $ref);
			}
		}

		return $placeItems;
	}//end storedPlaceItems()

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
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
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
