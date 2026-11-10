<?php

/**
 * OpenRegister AutoTransitionPass
 *
 * The request-scoped pass: what one write and everything it triggers
 * automatically amounts to, and the two limits that bound it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use Psr\Log\LoggerInterface;

/**
 * Where an automatic move is applied, and how far one write may travel.
 *
 * WHY NOT IN THE LISTENER. `ObjectUpdatedEvent` fires in the MIDDLE of a save,
 * not at its end: `SaveObject` still has the save's audit row to write, and on
 * a file-bearing save it calls `MagicMapper::update()` a second time with its
 * in-memory entity. A move applied inside that event would be written before
 * the triggering save's audit row and overwritten by the second update, which
 * carries the old lifecycle value. That is a lost update behind a transition
 * event that has already fired. So the listener only RECORDS, and this class
 * applies, at the outermost write boundary, where the triggering write is
 * finished in every sense that matters.
 *
 * THE BOUNDARY. `ObjectService::saveObject()` and `TransitionEngine::
 * transition()` each enter on the way in and leave in a `finally`. Leaving the
 * outermost one drains. The drain is a LOOP, not recursion: firing a move
 * re-records the object through its own update event, a draining flag stops
 * the nested boundary exit from starting a nested drain, and the outer loop
 * picks the re-recorded object up and decides the next step.
 *
 * THE TWO LIMITS, both per object per pass, following `SubFlowNode`'s shape:
 *
 * - NO REVISIT. A move into a state the object already occupied in this pass,
 *   including the one it started in, is not made. A to B to A is cut at the
 *   second move, deterministically, after exactly one write. A count-only cap
 *   would let the object flip ten times, write ten audit rows, send ten rounds
 *   of notifications, and stop on whichever state the parity of the ceiling
 *   picked.
 * - A CEILING OF 10. It only bites on a chain through more than ten distinct
 *   states. It is a private constant and NOT configurable: a configurable cap
 *   invites raising it to make a loop's symptom go away.
 *
 * A pass, rather than a request, is the unit deliberately. Within one request
 * every write reachable from the triggering one is in the same pass, so it is
 * at least as strict there; and the pass travels with a queued move, which a
 * request-scoped counter cannot do. A counter that reset in every background
 * job would let an async A to B, B to A loop run forever, one cron tick apart.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
class AutoTransitionPass implements AutoTransitionPassInterface {

	/**
	 * The most automatic moves one object may make in one pass.
	 *
	 * A constant, like `SubFlowNode::MAX_DEPTH`, and deliberately not a config
	 * value; see the class docblock.
	 *
	 * @var integer
	 */
	private const MAX_MOVES = 10;

	/**
	 * How many write boundaries are currently open.
	 *
	 * @var integer
	 */
	private int $depth = 0;

	/**
	 * Whether a drain is in progress, so a nested exit does not start another.
	 *
	 * @var boolean
	 */
	private bool $draining = false;

	/**
	 * Objects written in this pass and not yet decided.
	 *
	 * @var array<string, array{uuid: string, register: string, schema: string, inside: bool}>
	 */
	private array $pending = [];

	/**
	 * The state each object was in before the write that started the pass.
	 *
	 * First capture wins, and it is kept for the whole pass: every step of one
	 * object's chain reads the same `previous`, so an `autoWhen` comparing
	 * against it means the same thing at every hop.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $previous = [];

	/**
	 * The chain each object has travelled in this pass.
	 *
	 * @var array<string, array{visited: array<string, bool>, moves: int, applied: list<string>}>
	 */
	private array $lineage = [];

	/**
	 * The automatic transition being applied right now, if any.
	 *
	 * An ambient frame rather than a parameter on `TransitionEngine::
	 * transition()`, so no caller can claim a manual move was automatic.
	 *
	 * @var string|null
	 */
	private ?string $applying = null;

	/**
	 * Whether the end-of-request flush has been registered.
	 *
	 * @var boolean
	 */
	private bool $flushHooked = false;

	/**
	 * Constructor.
	 *
	 * @param AutoTransitionRunner $runner Decides and applies one step of the pass.
	 * @param LoggerInterface $logger Logs a cut at error level, with the chain it cut.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly AutoTransitionRunner $runner,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Open a write boundary.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function enter(): void {
		$this->depth++;
	}//end enter()

	/**
	 * Close a write boundary, draining the pass when it was the outermost one.
	 *
	 * @return array<string, ObjectEntity> The last entity applied per object uuid, empty when nothing was.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function leave(): array {
		if ($this->depth > 0) {
			$this->depth--;
		}

		if ($this->depth > 0 || $this->draining === true) {
			return [];
		}

		return $this->drain();
	}//end leave()

	/**
	 * Whether a write boundary is currently open.
	 *
	 * @return bool True when a save or a transition is in flight.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function isInsideBoundary(): bool {
		return $this->depth > 0;
	}//end isInsideBoundary()

	/**
	 * Note that an object was written, so the pass will decide it.
	 *
	 * Recording is ALL that happens on the event. Nothing here evaluates a
	 * rule and nothing here writes.
	 *
	 * @param ObjectRef $ref The object's register, schema and uuid.
	 * @param array<string, mixed> $previous The object as it was before this write.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function record(ObjectRef $ref, array $previous): void {
		$uuid = $ref->id;
		if ($uuid === '') {
			return;
		}

		if (array_key_exists($uuid, $this->previous) === false) {
			// First capture wins: `previous` is the state before the write that
			// STARTED the pass, not before the automatic move that last touched
			// the object.
			$this->previous[$uuid] = $previous;
		}

		$inside = $this->isInsideBoundary();
		$this->pending[$uuid] = ['uuid' => $uuid, 'register' => (string)$ref->register, 'schema' => (string)$ref->schema, 'inside' => $inside];

		if ($inside === false) {
			$this->hookFlush();
		}
	}//end record()

	/**
	 * The automatic transition being applied right now, if any.
	 *
	 * Read by `TransitionEngine` when it dispatches `ObjectTransitionedEvent`
	 * and by `AuditTrailMapper` when it builds the row, both of which need to
	 * mark the move without being told so by a caller.
	 *
	 * @return string|null The transition's name, or null for a manual move.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function applyingAction(): ?string {
		return $this->applying;
	}//end applyingAction()

	/**
	 * Answer with the automatic move's entity when the pass made one.
	 *
	 * This is how the response of an object save and of a named transition
	 * carries the new state without a controller change.
	 *
	 * @param ObjectEntity $saved The entity the write itself produced.
	 * @param array<string, ObjectEntity> $applied What the drain applied, by uuid.
	 *
	 * @return ObjectEntity The object in its final state.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function preferAutomatic(ObjectEntity $saved, array $applied): ObjectEntity {
		return ($applied[(string)$saved->getUuid()] ?? $saved);
	}//end preferAutomatic()

	/**
	 * Continue a pass that was carried into a background job, from the lineage it carried.
	 *
	 * @param string $uuid The object whose lineage is being restored.
	 * @param CarriedLineage $lineage The moves made and the states visited so far.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function resumeLineage(string $uuid, CarriedLineage $lineage): void {
		$flags = [];
		foreach ($lineage->visited as $state) {
			$flags[(string)$state] = true;
		}

		$this->lineage[$uuid] = ['visited' => $flags, 'moves' => max(0, $lineage->moves), 'applied' => []];
	}//end resumeLineage()

	/**
	 * Decide and queue every move recorded outside a write boundary.
	 *
	 * A bulk save, a deferred create event, a revert and a direct mapper write
	 * all dispatch post-save events with no `saveObject()` around them, so
	 * there is no point at which a sync move could run after the write
	 * completes and before a response. Their moves are queued whatever the
	 * declared mode.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function flushOutsideBoundary(): void {
		$queued = false;
		foreach (array_keys($this->pending) as $uuid) {
			$record = $this->pending[$uuid];
			if ($record['inside'] === true) {
				continue;
			}

			unset($this->pending[$uuid]);
			$this->safeStep(record: $record);
			$queued = true;
		}

		if ($queued === true) {
			$this->runner->flushQueued();
		}
	}//end flushOutsideBoundary()

	/**
	 * Apply every move this pass can still make, one object at a time.
	 *
	 * @return array<string, ObjectEntity> The last entity applied per object uuid.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function drain(): array {
		$this->draining = true;

		try {
			return $this->drainInside();
		} finally {
			$this->draining = false;
			$this->previous = [];
			$this->lineage = [];
		}
	}//end drain()

	/**
	 * Step every object recorded inside a boundary until none is left.
	 *
	 * A LOOP, not recursion: a fired move re-records its object, and the next
	 * iteration picks it up.
	 *
	 * @return array<string, ObjectEntity> The last entity applied per object uuid.
	 */
	private function drainInside(): array {
		$applied = [];
		$uuid = $this->nextInside();
		while ($uuid !== null) {
			$record = $this->pending[$uuid];
			unset($this->pending[$uuid]);

			$entity = $this->safeStep(record: $record);
			if ($entity !== null) {
				$applied[$uuid] = $entity;
			}

			$uuid = $this->nextInside();
		}

		return $applied;
	}//end drainInside()

	/**
	 * The next object recorded inside a boundary, or null when there is none.
	 *
	 * @return string|null The object's uuid.
	 */
	private function nextInside(): ?string {
		foreach ($this->pending as $uuid => $record) {
			if ($record['inside'] === true) {
				return (string)$uuid;
			}
		}

		return null;
	}//end nextInside()

	/**
	 * One step, with nothing it can raise reaching the caller.
	 *
	 * 🔴 THE DRAIN RUNS INSIDE A `finally`. An exception escaping here would
	 * REPLACE the exception the triggering write was already raising, so a save
	 * that failed for its own reason would be reported as an automatic-
	 * transition failure and the real cause would be gone. The refusals a move
	 * can meet are caught one layer down, in the runner, with their refusal
	 * code; this is the backstop for everything else.
	 *
	 * @param array{uuid: string, register: string, schema: string, inside: bool} $record What the listener recorded.
	 *
	 * @return ObjectEntity|null The object after the move, or null.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function safeStep(array $record): ?ObjectEntity {
		try {
			return $this->step(record: $record);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'[AutoTransitionPass] Deciding or applying an automatic transition failed; '
				. 'the triggering write stands.',
				['uuid' => $record['uuid'], 'schema' => $record['schema'], 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end safeStep()

	/**
	 * Decide one object's next move and take it, unless a limit says no.
	 *
	 * @param array{uuid: string, register: string, schema: string, inside: bool} $record What the listener recorded.
	 *
	 * @return ObjectEntity|null The object after the move, or null.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function step(array $record): ?ObjectEntity {
		$uuid = $record['uuid'];
		$decision = $this->decide(record: $record);
		if ($decision === null) {
			return null;
		}

		$this->seedLineage(uuid: $uuid, from: $decision->candidate->from);
		if (self::limitOf(lineage: $this->lineage[$uuid], to: $decision->candidate->to) !== null) {
			$this->cut(decision: $decision, lineage: $this->lineage[$uuid]);
			return null;
		}

		return $this->advance(record: $record, decision: $decision);
	}//end step()

	/**
	 * Ask the runner for the object's next move, against the state that started the pass.
	 *
	 * @param array{uuid: string, register: string, schema: string, inside: bool} $record What the listener recorded.
	 *
	 * @return AutoTransitionDecision|null The decided move, or null.
	 */
	private function decide(array $record): ?AutoTransitionDecision {
		return $this->runner->decide(
			ref: new ObjectRef(register: $record['register'], schema: $record['schema'], id: $record['uuid']),
			previous: ($this->previous[$record['uuid']] ?? [])
		);
	}//end decide()

	/**
	 * Count the move in the object's lineage and fire it inside the ambient frame.
	 *
	 * A record made outside any boundary is queued, whatever the declared mode.
	 *
	 * @param array{uuid: string, register: string, schema: string, inside: bool} $record   What the listener recorded.
	 * @param AutoTransitionDecision                                               $decision The move to make.
	 *
	 * @return ObjectEntity|null The object after the move, or null.
	 */
	private function advance(array $record, AutoTransitionDecision $decision): ?ObjectEntity {
		$uuid = $record['uuid'];
		$this->countMove(uuid: $uuid, candidate: $decision->candidate);

		$this->applying = $decision->candidate->action;
		try {
			if ($record['inside'] === false) {
				$this->runner->queueOutsideBoundary(decision: $decision, lineage: $this->lineage[$uuid]);
				return null;
			}

			return $this->runner->fire(decision: $decision, lineage: $this->lineage[$uuid]);
		} finally {
			$this->applying = null;
		}
	}//end advance()

	/**
	 * Record a move about to be made in the object's lineage.
	 *
	 * @param string                  $uuid      The object's uuid.
	 * @param AutoTransitionCandidate $candidate The move.
	 *
	 * @return void
	 */
	private function countMove(string $uuid, AutoTransitionCandidate $candidate): void {
		$this->lineage[$uuid]['visited'][$candidate->to] = true;
		$this->lineage[$uuid]['moves']++;
		$this->lineage[$uuid]['applied'][] = $candidate->action;
	}//end countMove()

	/**
	 * Start this object's lineage at the state it is in now.
	 *
	 * The starting state counts as visited, which is what makes A to B to A a
	 * single move rather than a flip-flop bounded only by the ceiling.
	 *
	 * @param string $uuid The object's uuid.
	 * @param string $from The state the object is in now.
	 *
	 * @return void
	 */
	private function seedLineage(string $uuid, string $from): void {
		if (isset($this->lineage[$uuid]) === true) {
			return;
		}

		$this->lineage[$uuid] = ['visited' => [$from => true], 'moves' => 0, 'applied' => []];
	}//end seedLineage()

	/**
	 * Which limit a move into `$to` reaches, or null when it may be made.
	 *
	 * @param array{visited: array<string, bool>, moves: int, applied: list<string>} $lineage The chain so far.
	 * @param string                                                                $to      The state the move enters.
	 *
	 * @return string|null `revisit`, `ceiling`, or null.
	 */
	private static function limitOf(array $lineage, string $to): ?string {
		if (isset($lineage['visited'][$to]) === true) {
			return 'revisit';
		}

		if ($lineage['moves'] >= self::MAX_MOVES) {
			return 'ceiling';
		}

		return null;
	}//end limitOf()

	/**
	 * Log a cut pass and make no move.
	 *
	 * Error level, not warning: a cut means a declaration loops, which is an
	 * authoring defect that will keep costing writes until someone reads this.
	 * It never throws, because the triggering write has already succeeded and
	 * nothing about the cut is the caller's fault.
	 *
	 * @param AutoTransitionDecision $decision The move that was not made.
	 * @param array{visited: array<string, bool>, moves: int, applied: list<string>} $lineage The chain so far.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function cut(AutoTransitionDecision $decision, array $lineage): void {
		$this->logger->error(
			'[AutoTransitionPass] Automatic transitions were cut for this object; the declaration loops.',
			[
				'schema' => $decision->schemaSlug,
				'uuid' => $decision->uuid,
				'limit' => self::limitOf(lineage: $lineage, to: $decision->candidate->to),
				'ceiling' => self::MAX_MOVES,
				'refused' => $decision->candidate->action,
				'to' => $decision->candidate->to,
				'applied' => $lineage['applied'],
				'visited' => array_keys($lineage['visited']),
			]
		);
	}//end cut()

	/**
	 * Register the end-of-request flush exactly once.
	 *
	 * @return void
	 */
	private function hookFlush(): void {
		if ($this->flushHooked === true) {
			return;
		}

		$this->flushHooked = true;
		register_shutdown_function(
			function (): void {
				$this->flushOutsideBoundary();
			}
		);
	}//end hookFlush()
}//end class
