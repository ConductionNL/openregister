<?php

/**
 * Keeps a run's Petri-net marking on its FlowRun row.
 *
 * `symfony/workflow` reads and writes the marking through a
 * `MarkingStoreInterface`, and the in-memory implementations put it on a
 * property of the subject. That is fine for a run that starts and finishes
 * inside one request, and useless for one that does not.
 *
 * This store puts the marking where it survives: on the run. Resuming a
 * suspended run is then handing the stored places back to Symfony, not
 * replaying the graph from the beginning — which matters because replaying
 * would re-run every side effect the run already performed.
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
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\MarkingStore\MarkingStoreInterface;

/**
 * A marking store backed by a FlowRun row.
 */
class FlowRunMarkingStore implements MarkingStoreInterface {

	/**
	 * The delta the last `setMarking()` applied.
	 *
	 * @var array{consumed: array<int, string>, produced: array<int, string>}
	 */
	private array $lastDelta = ['consumed' => [], 'produced' => []];

	/**
	 * Constructor.
	 *
	 * @param FlowRun $run The run whose marking this store reads and writes.
	 */
	public function __construct(
		private readonly FlowRun $run,
	) {

	}//end __construct()

	/**
	 * Read the marking.
	 *
	 * The subject is ignored: the marking belongs to the RUN, not to the object
	 * the run is about. Two runs over one object hold two independent markings,
	 * which is the whole reason this is not stored on the subject.
	 *
	 * @param object $subject The subject (unused).
	 *
	 * @return Marking The current marking.
	 *
	 * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
	 */
	public function getMarking(object $subject): Marking {
		$places = ($this->run->getMarking() ?? []);
		if (is_array($places) === false || $places === []) {
			return new Marking();
		}

		// Stored as `place => tokens`. A list of place names is also accepted,
		// because that is the shape a hand-authored fixture tends to take.
		$normalised = [];
		foreach ($places as $key => $value) {
			if (is_int($key) === true) {
				$normalised[(string)$value] = 1;
				continue;
			}

			$normalised[(string)$key] = max(1, (int)$value);
		}

		return new Marking($normalised);
	}//end getMarking()

	/**
	 * Write the marking back onto the run.
	 *
	 * Only mutates the entity; persisting is the caller's job, so a whole hop
	 * (marking, items, log) is written in one update rather than three.
	 *
	 * @param object $subject The subject (unused).
	 * @param Marking $marking The new marking.
	 * @param array<string,mixed> $context Transition context (unused).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
	 */
	public function setMarking(object $subject, Marking $marking, array $context = []): void {
		// A DELTA, never the whole value. Symfony hands over the marking it
		// computed; the difference against what the run holds is exactly one
		// token off each consumed place and one onto each produced one, and
		// THAT is what is applied — so the write mentions no other place and
		// cannot resurrect a token another writer consumed or drop one it
		// produced. The whole-value assignment that used to live here is gone,
		// not wrapped: leaving it reachable leaves the lost update reachable.
		$current = $this->normalise(places: ($this->run->getMarking() ?? []));
		$next = $this->normalise(places: $marking->getPlaces());
		$this->lastDelta = ['consumed' => [], 'produced' => []];

		foreach (array_unique(array_merge(array_keys($current), array_keys($next))) as $place) {
			$diff = (($next[$place] ?? 0) - ($current[$place] ?? 0));
			if ($diff < 0) {
				$this->lastDelta['consumed'][] = (string)$place;
				$current[$place] = (($current[$place] ?? 0) + $diff);
				if ($current[$place] <= 0) {
					unset($current[$place]);
				}
			} elseif ($diff > 0) {
				$this->lastDelta['produced'][] = (string)$place;
				$current[$place] = (($current[$place] ?? 0) + $diff);
			}
		}

		$this->run->setMarking($current);

	}//end setMarking()

	/**
	 * The delta the last `setMarking()` applied: the places it took a token
	 * off and the places it put one onto.
	 *
	 * @return array{consumed: array<int, string>, produced: array<int, string>} The delta.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
	 */
	public function lastDelta(): array {
		return $this->lastDelta;
	}//end lastDelta()

	/**
	 * Replace the run's marking with a COMMITTED one, read back from the
	 * database under the run-row lock. The one legitimate whole write: the
	 * value came from the lock, not from a read taken before the step ran.
	 *
	 * @param array<string, int> $marking The committed marking.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
	 */
	public function syncCommitted(array $marking): void {
		$this->run->setMarking($this->normalise(places: $marking));
	}//end syncCommitted()

	/**
	 * A stored marking as `place => tokens`.
	 *
	 * @param mixed $places The raw value (a map, or a list of place names).
	 *
	 * @return array<string, int> The normalised marking.
	 */
	private function normalise(mixed $places): array {
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
	}//end normalise()
}//end class
