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
 */
class AutoTransitionPass {

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
	 * @var array<string, array{register: string, schema: string, inside: bool}>
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
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
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
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	public function enter(): void {
		$this->depth++;
	}//end enter()

	/**
	 * Close a write boundary, draining the pass when it was the outermost one.
	 *
	 * @return array<string, ObjectEntity> The last entity applied per object uuid, empty when nothing was.
	 *
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
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
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
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
	 * @param string $uuid The object's uuid.
	 * @param string $register The object's register reference.
	 * @param string $schema The object's schema reference.
	 * @param array<string, mixed> $previous The object as it was before this write.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	public function record(string $uuid, string $register, string $schema, array $previous): void {
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
		$this->pending[$uuid] = ['register' => $register, 'schema' => $schema, 'inside' => $inside];

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
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
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
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	public function preferAutomatic(ObjectEntity $saved, array $applied): ObjectEntity {
		return ($applied[(string)$saved->getUuid()] ?? $saved);
	}//end preferAutomatic()

	/**
	 * Continue a pass that was carried into a background job.
	 *
	 * @param string $uuid The object whose lineage is being restored.
	 * @param int $moves How many automatic moves the pass had already made.
	 * @param array<int, string> $visited The states the object has occupied in this pass.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	public function resume(string $uuid, int $moves, array $visited): void {
		$flags = [];
		foreach ($visited as $state) {
			$flags[(string)$state] = true;
		}

		$this->lineage[$uuid] = ['visited' => $flags, 'moves' => max(0, $moves), 'applied' => []];
	}//end resume()

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
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	public function flushOutsideBoundary(): void {
		$queued = false;
		foreach (array_keys($this->pending) as $uuid) {
			$record = $this->pending[$uuid];
			if ($record['inside'] === true) {
				continue;
			}

			unset($this->pending[$uuid]);
			$this->safeStep(uuid: $uuid, record: $record, queueOnly: true);
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
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	private function drain(): array {
		$applied = [];
		$this->draining = true;

		try {
			while (true) {
				$uuid = $this->nextInside();
				if ($uuid === null) {
					break;
				}

				$record = $this->pending[$uuid];
				unset($this->pending[$uuid]);

				$entity = $this->safeStep(uuid: $uuid, record: $record, queueOnly: false);
				if ($entity !== null) {
					$applied[$uuid] = $entity;
				}
			}
		} finally {
			$this->draining = false;
			$this->previous = [];
			$this->lineage = [];
		}//end try

		return $applied;
	}//end drain()

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
	 * @param string $uuid The object's uuid.
	 * @param array{register: string, schema: string, inside: bool} $record What the listener recorded.
	 * @param bool $queueOnly True for a move recorded outside any boundary.
	 *
	 * @return ObjectEntity|null The object after the move, or null.
	 *
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	private function safeStep(string $uuid, array $record, bool $queueOnly): ?ObjectEntity {
		try {
			return $this->step(uuid: $uuid, record: $record, queueOnly: $queueOnly);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'[AutoTransitionPass] Deciding or applying an automatic transition failed; '
				. 'the triggering write stands.',
				['uuid' => $uuid, 'schema' => $record['schema'], 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end safeStep()

	/**
	 * Decide one object's next move and take it, unless a limit says no.
	 *
	 * @param string $uuid The object's uuid.
	 * @param array{register: string, schema: string, inside: bool} $record What the listener recorded.
	 * @param bool $queueOnly True for a move recorded outside any boundary.
	 *
	 * @return ObjectEntity|null The object after the move, or null.
	 *
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	private function step(string $uuid, array $record, bool $queueOnly): ?ObjectEntity {
		$decision = $this->runner->decide(
			uuid: $uuid,
			register: $record['register'],
			schema: $record['schema'],
			previous: ($this->previous[$uuid] ?? [])
		);
		if ($decision === null) {
			return null;
		}

		$candidate = $decision->candidate;
		$this->seedLineage(uuid: $uuid, from: $candidate->from);
		$lineage = $this->lineage[$uuid];

		if (isset($lineage['visited'][$candidate->to]) === true) {
			$this->cut(decision: $decision, lineage: $lineage, limit: 'revisit');
			return null;
		}

		if ($lineage['moves'] >= self::MAX_MOVES) {
			$this->cut(decision: $decision, lineage: $lineage, limit: 'ceiling');
			return null;
		}

		$this->lineage[$uuid]['visited'][$candidate->to] = true;
		$this->lineage[$uuid]['moves']++;
		$this->lineage[$uuid]['applied'][] = $candidate->action;

		$this->applying = $candidate->action;
		try {
			return $this->runner->fire(
				decision: $decision,
				lineage: $this->lineage[$uuid],
				queueOnly: $queueOnly
			);
		} finally {
			$this->applying = null;
		}
	}//end step()

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
	 * Log a cut pass and make no move.
	 *
	 * Error level, not warning: a cut means a declaration loops, which is an
	 * authoring defect that will keep costing writes until someone reads this.
	 * It never throws, because the triggering write has already succeeded and
	 * nothing about the cut is the caller's fault.
	 *
	 * @param AutoTransitionDecision $decision The move that was not made.
	 * @param array{visited: array<string, bool>, moves: int, applied: list<string>} $lineage The chain so far.
	 * @param string $limit Which limit was reached: `revisit` or `ceiling`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	private function cut(AutoTransitionDecision $decision, array $lineage, string $limit): void {
		$this->logger->error(
			'[AutoTransitionPass] Automatic transitions were cut for this object; the declaration loops.',
			[
				'schema' => $decision->schemaSlug,
				'uuid' => $decision->uuid,
				'limit' => $limit,
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
