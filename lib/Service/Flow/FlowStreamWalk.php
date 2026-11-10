<?php

/**
 * The per-run stream collaborator the engine walks with: which token belongs
 * to which stream, round-robin scheduling over advanceable streams, claims
 * before every firing, and the commit path after it.
 *
 * Built by FlowRunService for a persisted run and handed to
 * FlowEngine::run(). Absent (unit tests, the flow tester) the engine walks a
 * single in-memory stream exactly as before — a flow with one stream IS the
 * run, which is what makes the `flow-engine` spec change safe for every
 * existing flow.
 *
 * "Simultaneously" means two precise things here and neither is PHP threads:
 * across processes, genuinely parallel through the claim protocol; within one
 * pass, INTERLEAVED — the walk visits streams round-robin instead of draining
 * one to exhaustion, so a stream that suspends yields to its siblings rather
 * than returning the whole run.
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
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use DateTime;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowStream;

/**
 * One run's streams, for one worker pass.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) The engine's per-hop protocol —
 * schedule, claim, commit, park, end, finalize — is one method each on purpose,
 * so the walk in FlowEngine reads as the walk.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Stream bookkeeping for the
 * split, join, park and resume cases; each is one branch of the token's life.
 */
class FlowStreamWalk {

	/**
	 * In-memory stream descriptors by id:
	 * `{id, path, parent, place, status}`.
	 *
	 * @var array<string, array{id: string, path: string, parent: string|null, place: string|null, status: string}>
	 */
	private array $streams = [];

	/**
	 * Round-robin cursor over the ordinal-ordered stream list.
	 *
	 * @var int
	 */
	private int $cursor = 0;

	/**
	 * Firings committed by this walk.
	 *
	 * @var int
	 */
	private int $fired = 0;

	/**
	 * The streams this pass has set aside, and its scope.
	 *
	 * @var FlowStreamVisits
	 */
	private readonly FlowStreamVisits $visits;

	/**
	 * Builds the picture's entries for this run.
	 *
	 * @var FlowStreamEntries
	 */
	private readonly FlowStreamEntries $entries;

	/**
	 * Constructor.
	 *
	 * @param FlowRun              $run    The run being walked (refreshed by every commit).
	 * @param FlowStreamWalkParts  $parts  The claim protocol, commit path and stream rows.
	 * @param FlowStreamWalkLimits $limits The pass's claim token, stream cap, scope and budget.
	 */
	public function __construct(
		private readonly FlowRun $run,
		private readonly FlowStreamWalkParts $parts,
		private readonly FlowStreamWalkLimits $limits,
	) {
		$this->entries = new FlowStreamEntries(runUuid: (string)$run->getUuid());
		$this->visits  = new FlowStreamVisits(onlyStream: $limits->onlyStream);
	}//end __construct()

	/**
	 * Load the run's streams and assign the marking's tokens to them.
	 *
	 * Persisted streams carry their place. A marked place no stream claims —
	 * a fresh run's initial place, or a pre-stream run's tokens — is given an
	 * in-memory stream: the first the root, the rest the root's children in
	 * sorted place order, exactly as the migration back-fill does. Suspended
	 * streams become eligible again: the run was woken, and a node that is
	 * still waiting will simply park its stream again.
	 *
	 * @param array<string, int> $marking The run's marking, `place => tokens`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function begin(array $marking): void {
		$live = $this->entries->live(rows: $this->parts->streamMapper->findByRun(runUuid: $this->entries->runUuid));
		$places = $this->entries->unassigned(marking: $marking, covered: $this->entries->covered(entries: $live));
		$this->streams = $this->entries->seed(entries: $live, places: $places);
		$this->sortStreams();
	}//end begin()

	/**
	 * The next stream to visit, round-robin over live streams that are not
	 * parked, refused or exhausted this pass — or null when none remains.
	 *
	 * @return string|null The stream id.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function nextStream(): ?string {
		$ids = array_keys($this->streams);
		$count = count($ids);
		for ($i = 0; $i < $count; $i++) {
			$id = $ids[(($this->cursor + $i) % $count)];
			if ($this->isAdvanceable(id: $id) === true) {
				$this->cursor = ((($this->cursor + $i) + 1) % max(1, $count));
				return $id;
			}
		}

		return null;
	}//end nextStream()

	/**
	 * Whether a stream may be visited this pass.
	 *
	 * @param string $id The stream id.
	 *
	 * @return bool True when advanceable.
	 */
	private function isAdvanceable(string $id): bool {
		return isset($this->streams[$id]) === true && $this->visits->mayVisit(id: $id) === true;
	}//end isAdvanceable()

	/**
	 * Whether any of the given enabled transitions is work somebody could
	 * still do: one that consumes from a place held by a stream NOT parked in
	 * this pass. A parked stream's token keeps its transition enabled in the
	 * Petri net, but that transition is a wait, not work — counting it would
	 * make every parked run read as `queued` and spin the worker.
	 *
	 * 🔴 THE ANSWER MUST DESCRIBE THE STATE AFTER THE THING BEING COMMITTED,
	 * not the state the walk still holds in memory. It is called as an
	 * ARGUMENT to `commitFiring()` and to `park()`, and both of those are what
	 * move the stream — so at the moment this runs, `$this->streams` still has
	 * the firing stream on the place the firing just consumed and the pass's visits
	 * do not yet know about the stream that is parking. Answering from that
	 * stale picture said "no work remains" at EVERY ordinary mid-flow firing,
	 * `FlowRunCommit::applyDerivedStatus()` then derived `completed` for a run
	 * that was still working, `FlowRunMapper::update()` announced it, and
	 * `FlowRunLockReleaseListener` released the run's object locks — the
	 * defect this parameter pair exists to close. `$produced` and `$settling`
	 * are how the caller says what its commit is about to change.
	 *
	 * @param array<int, object> $transitions The enabled transitions.
	 * @param array<int, string> $produced The places the commit is about to put tokens on.
	 * @param string|null $settling A stream whose current place is about to stop counting: it is parking, or ending.
	 *
	 * @return bool True when an unparked stream has an enabled transition.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-runs-status-must-stay-derivable-from-its-streams-with-no-new-value
	 */
	public function workRemains(array $transitions, array $produced = [], ?string $settling = null): bool {
		return $this->anyEnabledFrom(transitions: $transitions, live: $this->livePlacesAfter(produced: $produced, settling: $settling));
	}//end workRemains()

	/**
	 * Whether any of the transitions takes a token from one of the live places.
	 *
	 * @param array<int, object> $transitions The enabled transitions.
	 * @param array<string, true> $live        The live places, as a set.
	 *
	 * @return bool True when a transition has a live input place.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-runs-status-must-stay-derivable-from-its-streams-with-no-new-value
	 */
	private function anyEnabledFrom(array $transitions, array $live): bool {
		foreach ($transitions as $transition) {
			$froms = array_flip(array_map('strval', $transition->getFroms()));
			if (array_intersect_key($froms, $live) !== []) {
				return true;
			}
		}

		return false;
	}//end anyEnabledFrom()

	/**
	 * The places a token will sit on once the caller's commit lands, excluding
	 * the parked streams' — see {@see self::workRemains()} for why the answer
	 * cannot be read off the walk's own picture.
	 *
	 * @param array<int, string> $produced The places the commit is about to mark.
	 * @param string|null $settling A stream whose place stops counting.
	 *
	 * @return array<string, true> The places, as a set.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-runs-status-must-stay-derivable-from-its-streams-with-no-new-value
	 */
	private function livePlacesAfter(array $produced, ?string $settling): array {
		$live = array_fill_keys(array_map('strval', $produced), true);
		foreach ($this->streams as $id => $stream) {
			if ($stream['place'] !== null && $this->holdsWork(id: $id, settling: $settling) === true) {
				$live[$stream['place']] = true;
			}
		}

		return $live;
	}//end livePlacesAfter()

	/**
	 * Whether a stream's place still counts as somewhere work can happen:
	 * it is neither the settling stream nor parked.
	 *
	 * @param int|string  $id       The stream id, as an array key.
	 * @param string|null $settling A stream whose place stops counting.
	 *
	 * @return bool True when the stream's place counts.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-runs-status-must-stay-derivable-from-its-streams-with-no-new-value
	 */
	private function holdsWork(int|string $id, ?string $settling): bool {
		return $id !== $settling && $this->visits->isParked(id: $id) === false;
	}//end holdsWork()

	/**
	 * The ordinal path of a stream, for a log entry that is not a firing (a
	 * suspension, a stop, a terminal failure) and so is written by the step
	 * history rather than the commit.
	 *
	 * @param string $id The stream id.
	 *
	 * @return string The path; the root path for an unknown stream.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-the-run-log-must-be-ordered-by-branch-never-by-completion
	 */
	public function pathOf(string $id): string {
		return (string)($this->streams[$id]['path'] ?? FlowStream::ROOT_PATH);
	}//end pathOf()

	/**
	 * The place holding a stream's token.
	 *
	 * @param string $id The stream id.
	 *
	 * @return string|null The place.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function placeOf(string $id): ?string {
		return ($this->streams[$id]['place'] ?? null);
	}//end placeOf()

	/**
	 * The live streams whose token sits on one of the given places, other than
	 * the firing stream — the siblings a join consumes.
	 *
	 * @param array<int, string> $places The transition's input places.
	 * @param string $except The firing stream.
	 *
	 * @return array<int, string> Stream ids.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function streamsOn(array $places, string $except): array {
		$found = [];
		foreach ($this->streams as $id => $stream) {
			if ($id === $except || $stream['place'] === null) {
				continue;
			}

			if (in_array($stream['place'], $places, true) === true) {
				$found[] = $id;
			}
		}

		return $found;
	}//end streamsOn()

	/**
	 * Note that a stream found nothing to fire on this visit.
	 *
	 * @param string $id The stream id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function exhaust(string $id): void {
		$this->visits->exhaust(id: $id);
	}//end exhaust()

	/**
	 * Try to claim every place a firing touches, for a stream.
	 *
	 * A refusal marks the stream refused for this pass: the firing stays
	 * enabled and the run ends the pass `queued`, never waited on.
	 *
	 * @param string $id The stream id.
	 * @param string $transition The transition name.
	 * @param array<int, string> $places `froms ∪ tos`.
	 *
	 * @return array<int, string>|null The claimed places, or null when refused.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-firing-must-exclusively-claim-every-place-it-touches
	 */
	public function claim(string $id, string $transition, array $places): ?array {
		return $this->claimFiring(firing: new FiringClaim(runUuid: (string)$this->run->getUuid(), streamId: $id, transition: $transition, places: $places));
	}//end claim()

	/**
	 * Claim one firing's places for this pass; a refused stream is not walked again this pass.
	 *
	 * @param FiringClaim $firing The run, the stream, the transition and the places it touches.
	 *
	 * @return array<int, string>|null The claimed places, or null when refused.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-firing-must-exclusively-claim-every-place-it-touches
	 */
	private function claimFiring(FiringClaim $firing): ?array {
		$claimed = $this->parts->claims->claim(firing: $firing, pass: new ClaimPass(owner: $this->limits->owner, runCap: $this->limits->runCap));
		if ($claimed === null) {
			$this->visits->refuse(id: $firing->streamId);
		}

		return $claimed;
	}//end claimFiring()

	/**
	 * Release claims without firing (an oversight refusal, a run-level end).
	 *
	 * @param array<int, string> $places The claimed places.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-firing-must-exclusively-claim-every-place-it-touches
	 */
	public function release(array $places): void {
		$this->parts->claims->release(runUuid: (string)$this->run->getUuid(), places: $places);
	}//end release()

	/**
	 * Commit one firing on its stream and re-read the stream picture from
	 * what was committed.
	 *
	 * The walk places the firing in the stream tree and names the sibling
	 * streams a join consumes; the engine supplies the rest.
	 *
	 * @param FlowFiring $firing The firing, as the engine describes it.
	 *
	 * @return FlowFiringResult The committed state.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
	 */
	public function commitFiring(FlowFiring $firing): FlowFiringResult {
		$id = $firing->streamId;
		$stream = $this->entries->ref(entries: $this->streams, id: $id);
		$placed = $firing->withConsumedStreamIds(consumedStreamIds: $this->streamsOn(places: $firing->froms, except: $id))
			->withStreamPath(path: $stream->path, parent: $stream->parent);

		$result = $this->parts->commit->commitFiring(run: $this->run, firing: $placed, owner: $this->limits->owner);
		$this->fired++;

		// The commit is the authority on lineage, and a sibling pass may have
		// moved things too. A commit changed the marking, so a stream that
		// found nothing before may find a join enabled now; parked and
		// refused streams stay so.
		$this->streams = $this->entries->live(rows: $result->streams);
		$this->sortStreams();
		$this->visits->forgetExhausted();

		return $result;
	}//end commitFiring()

	/**
	 * Park a stream on a suspension: it is done for this pass, its siblings
	 * are not.
	 *
	 * @param FlowStreamHalt $halt     The stream, its claims and why it parked.
	 * @param DateTime|null  $resumeAt Its wake time; null while waiting on a signal.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function park(FlowStreamHalt $halt, ?DateTime $resumeAt): void {
		$closure = $this->closure(halt: $halt, status: FlowRun::STATUS_SUSPENDED);
		$this->parts->commit->closeStream(run: $this->run, closure: $closure->wakingAt(resumeAt: $resumeAt));
		$id = $halt->streamId;
		$this->visits->park(id: $id);
		if (isset($this->streams[$id]) === true) {
			$this->streams[$id]['status'] = FlowRun::STATUS_SUSPENDED;
		}
	}//end park()

	/**
	 * End a stream terminally.
	 *
	 * @param FlowStreamHalt $halt   The stream, its claims and the error.
	 * @param string         $status One of FlowRun::TERMINAL.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-runs-status-must-stay-derivable-from-its-streams-with-no-new-value
	 */
	public function endStream(FlowStreamHalt $halt, string $status): void {
		$this->parts->commit->closeStream(run: $this->run, closure: $this->closure(halt: $halt, status: $status));
		unset($this->streams[$halt->streamId]);
	}//end endStream()

	/**
	 * The closure the commit writes for a halted stream.
	 *
	 * @param FlowStreamHalt $halt   The halt.
	 * @param string         $status The stream's status.
	 *
	 * @return FlowStreamClosure The closure.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	private function closure(FlowStreamHalt $halt, string $status): FlowStreamClosure {
		$stream = $this->entries->ref(entries: $this->streams, id: $halt->streamId);
		$closure = new FlowStreamClosure(stream: $stream, status: $status, release: $halt->releaseFor(owner: $this->limits->owner));

		return $closure->because(error: $halt->reason());
	}//end closure()

	/**
	 * The pass's last word: release what this pass still holds and derive the
	 * run's status under the lock.
	 *
	 * @param bool $enabled Whether any transition is enabled on the committed marking.
	 * @param string|null $forcedTerminal A run-level terminal outcome, when the walk ended the run.
	 *
	 * @return string The derived status.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-runs-status-must-stay-derivable-from-its-streams-with-no-new-value
	 */
	public function finalize(bool $enabled, ?string $forcedTerminal = null): string {
		return $this->parts->commit->end(
			run: $this->run,
			ending: new FlowRunEnding(owner: $this->limits->owner, enabled: $enabled, forcedTerminal: $forcedTerminal, endedAt: new DateTime())
		);
	}//end finalize()

	/**
	 * The run's durable firing count, as last committed.
	 *
	 * @return int Firings across all streams and passes.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-the-transition-ceiling-must-count-a-run-not-a-pass
	 */
	public function firings(): int {
		return (int)($this->run->getFirings() ?? 0);
	}//end firings()

	/**
	 * Whether this walk's own firing budget is spent (an in-request advance).
	 *
	 * @return bool True when no more firings may be committed by this walk.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-completions-advance-budget-must-apply-to-the-completing-branch
	 */
	public function budgetSpent(): bool {
		return $this->limits->budget !== null && $this->fired >= $this->limits->budget;
	}//end budgetSpent()

	/**
	 * The run, refreshed by the last commit.
	 *
	 * @return FlowRun The run.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function run(): FlowRun {
		return $this->run;
	}//end run()

	/**
	 * Keep the stream list in ordinal order, so the round-robin visits
	 * branches in the author's declaration order.
	 *
	 * @return void
	 */
	private function sortStreams(): void {
		uasort($this->streams, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));
	}//end sortStreams()
}//end class
