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
use OCA\OpenRegister\Db\FlowStreamMapper;

/**
 * One run's streams, for one worker pass.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) The engine's per-hop protocol —
 * schedule, claim, commit, park, end, finalize — is one method each on purpose,
 * so the walk in FlowEngine reads as the walk.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Stream bookkeeping for the
 * split, join, park and resume cases; each is one branch of the token's life.
 * @SuppressWarnings(PHPMD.StaticAccess) FlowRunCommit::streamIdFor and FlowStream::childPath
 * are pure id/path helpers; the walk needs their answers before any row exists.
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
	 * Streams parked during this pass (suspended), by id.
	 *
	 * @var array<string, true>
	 */
	private array $parked = [];

	/**
	 * Streams that found no enabled transition on their last visit, by id.
	 * Cleared whenever a commit changes the marking, because a sibling's
	 * commit may have enabled a join.
	 *
	 * @var array<string, true>
	 */
	private array $exhausted = [];

	/**
	 * Streams whose claim was refused this pass, by id. They stay enabled and
	 * leave the run `queued` for the next pass.
	 *
	 * @var array<string, true>
	 */
	private array $refused = [];

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
	 * Constructor.
	 *
	 * @param FlowRun $run The run being walked (refreshed by every commit).
	 * @param FlowPlaceClaims $claims The claim protocol.
	 * @param FlowRunCommit $commit The commit path.
	 * @param FlowStreamMapper $streamMapper The stream rows.
	 * @param string $owner This pass's claim token.
	 * @param int|null $runCap The per-run stream cap; null for FlowConcurrency's default.
	 * @param string|null $onlyStream Restrict the walk to one stream (an in-request advance).
	 * @param int|null $budget Firings this walk may commit; null for unbounded (the ceiling still applies).
	 */
	public function __construct(
		private readonly FlowRun $run,
		private readonly FlowPlaceClaims $claims,
		private readonly FlowRunCommit $commit,
		private readonly FlowStreamMapper $streamMapper,
		private readonly string $owner,
		private readonly ?int $runCap = null,
		private readonly ?string $onlyStream = null,
		private readonly ?int $budget = null,
	) {

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
		$uuid = (string)$this->run->getUuid();
		$this->streams = [];

		$covered = [];
		foreach ($this->streamMapper->findByRun(runUuid: $uuid) as $row) {
			if ($row->isTerminal() === true) {
				continue;
			}

			$id = (string)$row->getStreamId();
			$place = $row->getPlace();
			$this->streams[$id] = [
				'id' => $id,
				'path' => (string)$row->getOrdinalPath(),
				'parent' => $row->getParentStreamId(),
				'place' => $place,
				'status' => (string)$row->getStatus(),
			];
			if ($place !== null) {
				$covered[$place] = (($covered[$place] ?? 0) + 1);
			}
		}

		$unassigned = [];
		foreach ($marking as $place => $tokens) {
			$spare = ((int)$tokens - ($covered[(string)$place] ?? 0));
			for ($i = 0; $i < $spare; $i++) {
				$unassigned[] = (string)$place;
			}
		}

		sort($unassigned, SORT_STRING);
		$rootId = FlowRunCommit::streamIdFor(runUuid: $uuid, path: FlowStream::ROOT_PATH);
		$ordinal = 1;
		foreach ($unassigned as $place) {
			if (isset($this->streams[$rootId]) === false) {
				$this->streams[$rootId] = [
					'id' => $rootId,
					'path' => FlowStream::ROOT_PATH,
					'parent' => null,
					'place' => $place,
					'status' => FlowRun::STATUS_QUEUED,
				];
				continue;
			}

			$ordinal++;
			$path = FlowStream::childPath(parentPath: FlowStream::ROOT_PATH, index: $ordinal);
			$id = FlowRunCommit::streamIdFor(runUuid: $uuid, path: $path);
			$this->streams[$id] = [
				'id' => $id,
				'path' => $path,
				'parent' => $rootId,
				'place' => $place,
				'status' => FlowRun::STATUS_QUEUED,
			];
		}

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
		if (isset($this->streams[$id]) === false) {
			return false;
		}

		if ($this->onlyStream !== null && $id !== $this->onlyStream) {
			return false;
		}

		return isset($this->parked[$id]) === false
			&& isset($this->refused[$id]) === false
			&& isset($this->exhausted[$id]) === false;
	}//end isAdvanceable()

	/**
	 * The place holding a stream's token.
	 *
	 * @param string $id The stream id.
	 *
	 * @return string|null The place.
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
	 */
	public function exhaust(string $id): void {
		$this->exhausted[$id] = true;
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
		$claimed = $this->claims->acquire(
			runUuid: (string)$this->run->getUuid(),
			streamId: $id,
			transition: $transition,
			places: $places,
			owner: $this->owner,
			runCap: $this->runCap
		);

		if ($claimed === null) {
			$this->refused[$id] = true;
		}

		return $claimed;
	}//end claim()

	/**
	 * Release claims without firing (an oversight refusal, a run-level end).
	 *
	 * @param array<int, string> $places The claimed places.
	 *
	 * @return void
	 */
	public function release(array $places): void {
		$this->claims->release(runUuid: (string)$this->run->getUuid(), places: $places);
	}//end release()

	/**
	 * Commit one firing and update the in-memory streams from what the commit
	 * settled: the carrier moved on, or split into children, or absorbed the
	 * streams a join consumed.
	 *
	 * @param string $id The firing stream.
	 * @param string $transition The transition name.
	 * @param array<int, string> $froms The consumed places.
	 * @param array<int, string> $taken The produced places actually taken, in declaration order.
	 * @param array<string, array> $placeItems The engine's per-place items after the firing.
	 * @param array<int, string> $claimed The places this firing claimed.
	 * @param array $logEntry The engine's log entry.
	 * @param bool $enabledAfter Whether any transition is enabled after the firing.
	 * @param string $streamStatus The carrier's status after the firing.
	 * @param string|null $streamError The carrier's error under a `continue` failure.
	 *
	 * @return FlowFiringResult The committed state.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) The hop's whole effect, handed once to the commit.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
	 */
	public function commitFiring(
		string $id,
		string $transition,
		array $froms,
		array $taken,
		array $placeItems,
		array $claimed,
		array $logEntry,
		bool $enabledAfter,
		string $streamStatus = FlowRun::STATUS_RUNNING,
		?string $streamError = null,
	): FlowFiringResult {
		$stream = ($this->streams[$id] ?? ['path' => FlowStream::ROOT_PATH, 'parent' => null]);
		$consumed = $this->streamsOn(places: $froms, except: $id);

		$itemsByPlace = [];
		foreach ($taken as $to) {
			$itemsByPlace[$to] = ($placeItems[$to] ?? []);
		}

		$firing = new FlowFiring(
			streamId: $id,
			transition: $transition,
			froms: array_values($froms),
			taken: array_values($taken),
			itemsByPlace: $itemsByPlace,
			claimedPlaces: $claimed,
			consumedStreamIds: $consumed,
			logEntry: $logEntry,
			enabledAfter: $enabledAfter,
			streamStatus: $streamStatus,
			streamError: $streamError,
			streamPath: (string)$stream['path'],
			streamParent: $stream['parent']
		);

		$result = $this->commit->commitFiring(run: $this->run, firing: $firing, owner: $this->owner);
		$this->fired++;

		// Re-read the stream picture from what was committed: the commit is the
		// authority on lineage, and a sibling pass may have moved things too.
		$this->streams = [];
		foreach ($result->streams as $row) {
			if ($row->isTerminal() === true) {
				continue;
			}

			$rowId = (string)$row->getStreamId();
			$this->streams[$rowId] = [
				'id' => $rowId,
				'path' => (string)$row->getOrdinalPath(),
				'parent' => $row->getParentStreamId(),
				'place' => $row->getPlace(),
				'status' => (string)$row->getStatus(),
			];
		}

		$this->sortStreams();

		// A commit changed the marking, so a stream that found nothing before
		// may find a join enabled now. Parked and refused streams stay so.
		$this->exhausted = [];

		return $result;
	}//end commitFiring()

	/**
	 * Park a stream on a suspension: it is done for this pass, its siblings
	 * are not.
	 *
	 * @param string $id The stream id.
	 * @param DateTime|null $resumeAt Its wake time; null while waiting on a signal.
	 * @param string $reason Why it parked.
	 * @param array<int, string> $claimed The claims to release.
	 * @param bool $enabled Whether any transition is enabled afterwards.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function park(string $id, ?DateTime $resumeAt, string $reason, array $claimed, bool $enabled): void {
		$stream = ($this->streams[$id] ?? ['path' => FlowStream::ROOT_PATH, 'parent' => null, 'place' => null]);
		$this->commit->park(
			run: $this->run,
			streamId: $id,
			resumeAt: $resumeAt,
			reason: $reason,
			claimedPlaces: $claimed,
			owner: $this->owner,
			enabled: $enabled,
			path: (string)$stream['path'],
			parent: $stream['parent'],
			place: $stream['place']
		);
		$this->parked[$id] = true;
		if (isset($this->streams[$id]) === true) {
			$this->streams[$id]['status'] = FlowRun::STATUS_SUSPENDED;
		}
	}//end park()

	/**
	 * End a stream terminally.
	 *
	 * @param string $id The stream id.
	 * @param string $status One of FlowRun::TERMINAL.
	 * @param string|null $error The reason.
	 * @param array<int, string> $claimed The claims to release.
	 * @param bool $enabled Whether any transition is enabled afterwards.
	 *
	 * @return void
	 */
	public function endStream(string $id, string $status, ?string $error, array $claimed, bool $enabled): void {
		$stream = ($this->streams[$id] ?? ['path' => FlowStream::ROOT_PATH, 'parent' => null, 'place' => null]);
		$this->commit->endStream(
			run: $this->run,
			streamId: $id,
			status: $status,
			error: $error,
			claimedPlaces: $claimed,
			owner: $this->owner,
			enabled: $enabled,
			path: (string)$stream['path'],
			parent: $stream['parent'],
			place: $stream['place']
		);
		unset($this->streams[$id]);
	}//end endStream()

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
		return $this->commit->finalize(run: $this->run, owner: $this->owner, enabled: $enabled, forcedTerminal: $forcedTerminal);
	}//end finalize()

	/**
	 * The run's durable firing count, as last committed.
	 *
	 * @return int Firings across all streams and passes.
	 */
	public function firings(): int {
		return (int)($this->run->getFirings() ?? 0);
	}//end firings()

	/**
	 * Whether this walk's own firing budget is spent (an in-request advance).
	 *
	 * @return bool True when no more firings may be committed by this walk.
	 */
	public function budgetSpent(): bool {
		return $this->budget !== null && $this->fired >= $this->budget;
	}//end budgetSpent()

	/**
	 * Whether any stream was parked during this pass.
	 *
	 * @return bool True when a stream suspended.
	 */
	public function anyParked(): bool {
		return $this->parked !== [];
	}//end anyParked()

	/**
	 * Whether any stream's claim was refused during this pass.
	 *
	 * @return bool True when a firing was skipped on contention.
	 */
	public function anyRefused(): bool {
		return $this->refused !== [];
	}//end anyRefused()

	/**
	 * The run, refreshed by the last commit.
	 *
	 * @return FlowRun The run.
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
