<?php

/**
 * One claimed firing of the per-stream walk: the transition, its step, the
 * items it reads, the stream that fires it and the places it claimed.
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

/**
 * A hop of the stream walk, from its claim until it is committed or ends the run.
 */
final class FlowStreamHop extends FlowHop {

	/**
	 * Constructor. The start moment is taken when the hop is made.
	 *
	 * @param FlowStreamPass     $pass     The pass the hop belongs to.
	 * @param FlowHopPlan        $plan     The transition, its step and its items.
	 * @param string             $streamId The firing stream.
	 * @param array<int, string> $claimed  The places the claim holds.
	 */
	public function __construct(
		public readonly FlowStreamPass $pass,
		FlowHopPlan $plan,
		public readonly string $streamId,
		public readonly array $claimed,
	) {
		parent::__construct(owner: $pass, plan: $plan);
	}//end __construct()

	/**
	 * The places the firing took, in the transition's declaration order.
	 *
	 * @param array<int, string> $taken The output places the exit claimed.
	 *
	 * @return array<int, string> The taken places, ordered.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
	 */
	public function takenInOrder(array $taken): array {
		$takenTos = [];
		foreach ($this->transition->getTos() as $to) {
			if (in_array((string)$to, array_map('strval', $taken), true) === true) {
				$takenTos[] = (string)$to;
			}
		}

		return $takenTos;
	}//end takenInOrder()

	/**
	 * A log entry, placed on this hop's stream.
	 *
	 * @param array<string, mixed> $entry The entry.
	 *
	 * @return array<string, mixed> The entry with the stream and its ordinal path.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function onStream(array $entry): array {
		$entry['streamId'] = $this->streamId;
		$entry['ordinalPath'] = $this->pass->streams->pathOf(id: $this->streamId);

		return $entry;
	}//end onStream()

	/**
	 * The log entry of a committed firing.
	 *
	 * @param array<string, mixed> $entry   The entry.
	 * @param int                  $firings The run's durable firing count after the commit.
	 *
	 * @return array<string, mixed> The entry, marked as recorded and placed on this hop's stream.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
	 */
	public function committedEntry(array $entry, int $firings): array {
		$entry['recorded'] = true;
		$entry = $this->onStream(entry: $entry);
		$entry['firings'] = $firings;

		return $entry;
	}//end committedEntry()

	/**
	 * The firing this hop commits, with the places it claimed and took.
	 *
	 * @param array<int, string> $takenTos The places the firing took, in order.
	 *
	 * @return FlowFiring The firing.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
	 */
	public function firing(array $takenTos): FlowFiring {
		$froms = array_map('strval', $this->transition->getFroms());
		$firing = new FlowFiring(streamId: $this->streamId, transition: $this->name(), froms: $froms, taken: $takenTos);

		return $firing->withClaimedPlaces(claimedPlaces: $this->claimed);
	}//end firing()

	/**
	 * End the run because a Stop step or an oversight check stopped the hop.
	 *
	 * A run-level end: the claim is released and no stream begins another firing.
	 *
	 * @param FlowStop $stop The stop.
	 *
	 * @return array The terminal run result.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
	 */
	public function stopped(FlowStop $stop): array {
		$pass = $this->pass;
		$pass->streams->release(places: $this->claimed);
		$pass->log[] = $this->onStream(entry: $this->stoppedEntry(stop: $stop));
		$pass->streams->finalize(enabled: false, forcedTerminal: $stop->runStatus());

		return $pass->result(status: $stop->runStatus()) + ['error' => $stop->runError()];
	}//end stopped()


	/**
	 * Park the stream whose step asked to wait; its siblings keep going.
	 *
	 * The stream's marking is not advanced, so it resumes ON this transition.
	 *
	 * @param FlowSuspension $suspension The suspension.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	public function parked(FlowSuspension $suspension): void {
		$pass = $this->pass;
		$pass->log[] = $this->onStream(entry: $this->suspendedEntry(suspension: $suspension));
		// `settling`: this stream is the one parking, and the walk does not
		// know that yet — its token still enables the very transition it is
		// waiting ON. Counted, the park derives `queued` with no wake time,
		// which a parallel worker picks up immediately.
		$enabled = $pass->streams->workRemains(
			transitions: $pass->workflow->getEnabledTransitions(subject: $pass->subject),
			settling: $this->streamId
		);
		$halt = new FlowStreamHalt(streamId: $this->streamId, claimed: $this->claimed, enabled: $enabled);
		$pass->streams->park(halt: $halt->because(reason: $suspension->getMessage()), resumeAt: $suspension->getResumeAt());
	}//end parked()


	/**
	 * End the failed stream and the run, per the step's terminal policy.
	 *
	 * `failed`, not `stopped`: the same distinction
	 * {@see FlowEngine::outcomeForFailedStep()} explains for the single-stream walk,
	 * and it has to be made in BOTH places or which walk happened to run
	 * decides whether a wreck is queryable. The stream row already carries the
	 * message; the RUN row is what a query reads, so the envelope carries it
	 * too and `persistResult()` puts it on `error`.
	 *
	 * @param array<string, mixed> $entry The failure's log entry, holding `error`.
	 *
	 * @return array The terminal run result.
	 *
	 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
	 */
	public function ended(array $entry): array {
		$pass = $this->pass;
		$terminal = FlowEngine::STATUS_FAILED;
		if ((string)($this->step['onError'] ?? FlowEngine::ON_ERROR_STOP) === FlowEngine::ON_ERROR_DEAD_LETTER) {
			$terminal = FlowEngine::STATUS_DEAD_LETTER;
		}

		$pass->log[] = $this->onStream(entry: $entry);
		$halt = new FlowStreamHalt(streamId: $this->streamId, claimed: $this->claimed, enabled: false);
		$pass->streams->endStream(halt: $halt->because(reason: $entry['error']), status: $terminal);
		$pass->streams->finalize(enabled: false, forcedTerminal: $terminal);

		return $pass->result(status: $terminal) + ['error' => $entry['error']];
	}//end ended()


	/**
	 * Sync from what was COMMITTED: the marking read under the lock holds every
	 * sibling's committed effect, which the in-memory apply cannot.
	 *
	 * @param FlowFiringResult $result What the commit wrote.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
	 */
	public function settle(FlowFiringResult $result): void {
		$pass = $this->pass;
		if ($pass->store instanceof FlowRunMarkingStore) {
			$pass->store->syncCommitted(marking: $result->marking);
		}

		$pass->placeItems = $result->placeItems;
	}//end settle()
}//end class
