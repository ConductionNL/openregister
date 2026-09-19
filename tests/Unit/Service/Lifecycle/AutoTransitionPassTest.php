<?php

/**
 * The pass: where an automatic move runs, and how far one write may travel.
 *
 * The boundary is the point of the class. A move applied inside the post-save
 * event would be lost to the second write a file-bearing save makes, so the
 * listener records and the OUTERMOST boundary exit drains. These tests pin
 * that down together with the two limits, each of which is mutation-checked in
 * task 4.2: a count-only cap would pass a ping-pong test, and a drain that ran
 * on every boundary exit would pass a single-move test.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Lifecycle;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionCandidate;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionDecision;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionPass;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionRunner;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Boundary, drain, chain and cap.
 */
class AutoTransitionPassTest extends TestCase {

	private AutoTransitionRunner&MockObject $runner;

	private LoggerInterface&MockObject $logger;

	private AutoTransitionPass $pass;

	/**
	 * The lifecycle value each object currently holds, keyed by uuid.
	 *
	 * @var array<string, string>
	 */
	private array $state = [];

	/**
	 * The transitions the fake schema declares, as from => to.
	 *
	 * @var array<string, string>
	 */
	private array $moves = [];

	/**
	 * The actions the runner was asked to fire, in order.
	 *
	 * @var array<int, string>
	 */
	private array $fired = [];

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->runner = $this->createMock(AutoTransitionRunner::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->pass = new AutoTransitionPass($this->runner, $this->logger);
		$this->state = [];
		$this->moves = [];
		$this->fired = [];
	}//end setUp()

	/**
	 * Wire the runner as a tiny in-memory state machine over `$this->moves`.
	 *
	 * `decide()` answers with the move declared from the object's current
	 * state, and `fire()` applies it and re-records the object, exactly as the
	 * real runner does through the engine's own update event. That is what
	 * makes the drain's loop, rather than its first iteration, observable.
	 *
	 * @param string $mode The execution mode every candidate carries.
	 *
	 * @return void
	 */
	private function wireStateMachine(string $mode = 'sync'): void {
		$this->runner->method('decide')->willReturnCallback(
			function (string $uuid, string $register, string $schema, array $previous): ?AutoTransitionDecision {
				$current = ($this->state[$uuid] ?? '');
				if (isset($this->moves[$current]) === false) {
					return null;
				}

				return new AutoTransitionDecision(
					uuid: $uuid,
					register: $register,
					schema: $schema,
					schemaSlug: 'bezwaar',
					candidate: new AutoTransitionCandidate(
						action: 'naar-' . $this->moves[$current],
						from: $current,
						to: $this->moves[$current],
						mode: $this->modeForTest
					),
					version: '1',
					updated: null
				);
			}
		);

		$this->modeForTest = $mode;

		$this->runner->method('fire')->willReturnCallback(
			function (AutoTransitionDecision $decision, array $lineage, bool $queueOnly): ?ObjectEntity {
				$this->fired[] = $decision->candidate->action;
				$this->lastLineage = $lineage;
				$this->lastQueueOnly = $queueOnly;

				if ($this->modeForTest !== 'sync' || $queueOnly === true) {
					return null;
				}

				$this->state[$decision->uuid] = $decision->candidate->to;

				// The move's own save re-records the object, which is how the
				// drain gets a second iteration to decide.
				$this->pass->enter();
				$this->pass->record(
					uuid: $decision->uuid,
					register: $decision->register,
					schema: $decision->schema,
					previous: []
				);
				$this->pass->leave();

				return $this->entity($decision->uuid, $decision->candidate->to);
			}
		);
	}//end wireStateMachine()

	/**
	 * The execution mode the wired state machine hands out.
	 *
	 * @var string
	 */
	private string $modeForTest = 'sync';

	/**
	 * The lineage the runner was last handed.
	 *
	 * @var array<string, mixed>
	 */
	private array $lastLineage = [];

	/**
	 * Whether the runner was last told to queue only.
	 *
	 * @var boolean
	 */
	private bool $lastQueueOnly = false;

	/**
	 * An entity in a given state.
	 *
	 * @param string $uuid The object's uuid.
	 * @param string $state The lifecycle value.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, string $state): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject(['status' => $state]);

		return $entity;
	}//end entity()

	/**
	 * Record one object inside an open boundary.
	 *
	 * @param string $uuid The object's uuid.
	 *
	 * @return void
	 */
	private function recordInside(string $uuid): void {
		$this->pass->record(uuid: $uuid, register: '1', schema: '2', previous: []);
	}//end recordInside()

	/**
	 * @return void
	 */
	public function testANestedBoundaryExitDoesNotStartANestedDrain(): void {
		// 🔴 THE DRAIN POSITION. Only leaving the OUTERMOST boundary may drain:
		// a nested exit is still inside the triggering write.
		$this->wireStateMachine();
		$this->state['obj-1'] = 'stap-1';
		$this->moves = ['stap-1' => 'stap-2'];

		$this->pass->enter();
		$this->recordInside('obj-1');

		$this->pass->enter();
		$this->pass->leave();
		$this->assertSame([], $this->fired, 'A nested exit must not drain.');

		$this->pass->leave();
		$this->assertSame(['naar-stap-2'], $this->fired);
	}//end testANestedBoundaryExitDoesNotStartANestedDrain()

	/**
	 * @return void
	 */
	public function testAFailureWhileDecidingDoesNotPropagate(): void {
		// The drain runs inside a `finally`. An exception escaping it would
		// replace the exception the triggering write was already raising.
		$this->runner->method('decide')->willThrowException(new RuntimeException('boom'));
		$this->logger->expects($this->atLeastOnce())->method('warning');

		$this->pass->enter();
		$this->recordInside('obj-1');
		$this->assertSame([], $this->pass->leave());
	}//end testAFailureWhileDecidingDoesNotPropagate()

	/**
	 * @return void
	 */
	public function testAChainContinuesInOnePassAndAnswersWithTheLastMove(): void {
		$this->wireStateMachine();
		$this->state['obj-1'] = 'stap-1';
		$this->moves = ['stap-1' => 'stap-2', 'stap-2' => 'stap-3'];

		$this->pass->enter();
		$this->recordInside('obj-1');
		$applied = $this->pass->leave();

		$this->assertSame(['naar-stap-2', 'naar-stap-3'], $this->fired);
		$this->assertSame('stap-3', ($applied['obj-1']->getObject()['status'] ?? null));
	}//end testAChainContinuesInOnePassAndAnswersWithTheLastMove()

	/**
	 * @return void
	 */
	public function testAPingPongIsCutAfterExactlyOneMove(): void {
		// 🔴 THE REVISIT RULE. A count-only cap would let this flip ten times,
		// write ten audit rows and stop on whichever state the parity picked.
		$this->wireStateMachine();
		$this->state['obj-1'] = 'heen';
		$this->moves = ['heen' => 'terug', 'terug' => 'heen'];

		$this->logger->expects($this->once())
			->method('error')
			->with(
				$this->stringContains('cut'),
				$this->callback(static fn (array $context): bool => $context['limit'] === 'revisit')
			);

		$this->pass->enter();
		$this->recordInside('obj-1');
		$applied = $this->pass->leave();

		$this->assertSame(['naar-terug'], $this->fired);
		$this->assertSame('terug', ($applied['obj-1']->getObject()['status'] ?? null));
	}//end testAPingPongIsCutAfterExactlyOneMove()

	/**
	 * @return void
	 */
	public function testATwelveStateChainStopsAtTenMoves(): void {
		// 🔴 THE CEILING. Twelve distinct states, so the revisit rule never
		// fires and only the count can cut this.
		$this->wireStateMachine();
		$this->state['obj-1'] = 's0';
		for ($step = 0; $step < 11; $step++) {
			$this->moves['s' . $step] = 's' . ($step + 1);
		}

		$this->logger->expects($this->once())
			->method('error')
			->with(
				$this->anything(),
				$this->callback(
					static fn (array $context): bool => $context['limit'] === 'ceiling'
						&& $context['ceiling'] === 10
						&& count($context['applied']) === 10
				)
			);

		$this->pass->enter();
		$this->recordInside('obj-1');
		$applied = $this->pass->leave();

		$this->assertCount(10, $this->fired);
		$this->assertSame('s10', ($applied['obj-1']->getObject()['status'] ?? null));
	}//end testATwelveStateChainStopsAtTenMoves()

	/**
	 * @return void
	 */
	public function testAResumedPassCarriesItsCountIntoTheNextMove(): void {
		// A queued move carries the hop count, so a loop cannot escape the cap
		// by crossing into a background job.
		$this->wireStateMachine();
		$this->state['obj-1'] = 's9';
		$this->moves = ['s9' => 's10', 's10' => 's11'];

		$this->pass->resume(uuid: 'obj-1', moves: 9, visited: ['s0', 's9']);

		$this->pass->enter();
		$this->recordInside('obj-1');
		$this->pass->leave();

		$this->assertSame(['naar-s10'], $this->fired, 'The tenth move runs and the eleventh is cut.');
	}//end testAResumedPassCarriesItsCountIntoTheNextMove()

	/**
	 * @return void
	 */
	public function testAResumedPassRefusesAStateItAlreadyVisited(): void {
		$this->wireStateMachine();
		$this->state['obj-1'] = 'terug';
		$this->moves = ['terug' => 'heen'];

		$this->pass->resume(uuid: 'obj-1', moves: 1, visited: ['heen', 'terug']);

		$this->pass->enter();
		$this->recordInside('obj-1');
		$this->pass->leave();

		$this->assertSame([], $this->fired);
	}//end testAResumedPassRefusesAStateItAlreadyVisited()

	/**
	 * @return void
	 */
	public function testTheAmbientFrameNamesTheMoveOnlyWhileItIsApplied(): void {
		// The event and the audit row read this frame rather than a parameter,
		// so no caller can claim a manual move was automatic.
		$this->assertNull($this->pass->applyingAction());

		$this->runner->method('decide')->willReturn(
			new AutoTransitionDecision(
				uuid: 'obj-1',
				register: '1',
				schema: '2',
				schemaSlug: 'bezwaar',
				candidate: new AutoTransitionCandidate('beslissen', 'open', 'besloten', 'sync'),
				version: '1',
				updated: null
			)
		);

		$seen = null;
		$this->runner->method('fire')->willReturnCallback(
			function () use (&$seen): ?ObjectEntity {
				$seen = $this->pass->applyingAction();
				return null;
			}
		);

		$this->pass->enter();
		$this->recordInside('obj-1');
		$this->pass->leave();

		$this->assertSame('beslissen', $seen);
		$this->assertNull($this->pass->applyingAction(), 'The frame must not outlive the move.');
	}//end testTheAmbientFrameNamesTheMoveOnlyWhileItIsApplied()

	/**
	 * @return void
	 */
	public function testRecordingInsideABoundaryAppliesNothingUntilItIsLeft(): void {
		// 🔴 THE ORDERING GUARANTEE, AND IT WAS UNGUARDED.
		// A mutation that drained from inside the recording listener reddened
		// nothing, which means the whole reason the drain waits for the
		// outermost boundary was untested. It waits because the triggering
		// write is not finished at record time: on a file-bearing save a second
		// update would overwrite the move, the audit row is not yet written,
		// and a named transition's own event has not been dispatched.
		//
		// So: inside a boundary, recording must apply NOTHING, and flushing
		// must apply nothing either. Only leaving the boundary drains.
		$this->wireStateMachine();
		$this->state['obj-1'] = 'stap-1';
		$this->moves = ['stap-1' => 'stap-2'];

		$this->pass->enter();
		$this->pass->record(uuid: 'obj-1', register: '1', schema: '2', previous: []);

		$this->assertSame([], $this->fired, 'Recording inside a boundary must apply nothing.');
		$this->assertSame('stap-1', $this->state['obj-1']);

		$this->pass->flushOutsideBoundary();

		$this->assertSame(
			[],
			$this->fired,
			'A flush while still inside the boundary must apply nothing: the triggering write is unfinished.'
		);
		$this->assertSame('stap-1', $this->state['obj-1']);

		$this->pass->leave();

		$this->assertSame(['naar-stap-2'], $this->fired, 'Leaving the outermost boundary is what drains.');
		$this->assertSame('stap-2', $this->state['obj-1']);
	}//end testRecordingInsideABoundaryAppliesNothingUntilItIsLeft()

	/**
	 * @return void
	 */
	public function testAMoveRecordedOutsideAnyBoundaryIsQueuedNotApplied(): void {
		// A bulk save, a deferred create event and a revert all write with no
		// saveObject() around them, so there is no point at which a sync move
		// could run after the write and before a response.
		$this->wireStateMachine();
		$this->state['obj-1'] = 'stap-1';
		$this->moves = ['stap-1' => 'stap-2'];

		$this->runner->expects($this->once())->method('flushQueued');

		$this->pass->record(uuid: 'obj-1', register: '1', schema: '2', previous: []);
		$this->assertSame([], $this->fired, 'Recording alone must apply nothing.');

		$this->pass->flushOutsideBoundary();

		$this->assertSame(['naar-stap-2'], $this->fired);
		$this->assertTrue($this->lastQueueOnly, 'It must be routed to the job, whatever its declared mode.');
		$this->assertSame('stap-1', $this->state['obj-1'], 'Nothing is applied in the request.');
	}//end testAMoveRecordedOutsideAnyBoundaryIsQueuedNotApplied()

	/**
	 * @return void
	 */
	public function testADrainIgnoresWhatWasRecordedOutsideTheBoundary(): void {
		$this->wireStateMachine();
		$this->state['obj-1'] = 'stap-1';
		$this->moves = ['stap-1' => 'stap-2'];

		$this->pass->record(uuid: 'obj-1', register: '1', schema: '2', previous: []);

		$this->pass->enter();
		$this->pass->leave();

		$this->assertSame([], $this->fired);
	}//end testADrainIgnoresWhatWasRecordedOutsideTheBoundary()

	/**
	 * @return void
	 */
	public function testTheQueuedLineageCarriesTheCountAndTheVisitedStates(): void {
		$this->wireStateMachine(mode: 'async');
		$this->state['obj-1'] = 'stap-1';
		$this->moves = ['stap-1' => 'stap-2'];

		$this->pass->enter();
		$this->recordInside('obj-1');
		$this->pass->leave();

		$this->assertSame(1, $this->lastLineage['moves']);
		$this->assertSame(['stap-1', 'stap-2'], array_keys($this->lastLineage['visited']));
	}//end testTheQueuedLineageCarriesTheCountAndTheVisitedStates()

	/**
	 * @return void
	 */
	public function testThePassIsEmptyAfterItDrains(): void {
		// The leak direction: a pass that kept its lineage would carry one
		// request's chain into the next job in a long-running worker.
		$this->wireStateMachine();
		$this->state['obj-1'] = 'stap-1';
		$this->moves = ['stap-1' => 'stap-2'];

		$this->pass->enter();
		$this->recordInside('obj-1');
		$this->pass->leave();
		$this->assertSame(['naar-stap-2'], $this->fired);

		// A second pass over the same object starts a fresh lineage, so the
		// move back into stap-1 is allowed rather than refused as a revisit.
		$this->moves = ['stap-2' => 'stap-1'];
		$this->pass->enter();
		$this->recordInside('obj-1');
		$this->pass->leave();

		$this->assertSame(['naar-stap-2', 'naar-stap-1'], $this->fired);
	}//end testThePassIsEmptyAfterItDrains()

	/**
	 * @return void
	 */
	public function testPreferAutomaticAnswersWithTheMoveWhenThereWasOne(): void {
		$saved = $this->entity('obj-1', 'open');
		$moved = $this->entity('obj-1', 'besloten');

		$this->assertSame($saved, $this->pass->preferAutomatic(saved: $saved, applied: []));
		$this->assertSame($moved, $this->pass->preferAutomatic(saved: $saved, applied: ['obj-1' => $moved]));
		$this->assertSame(
			$saved,
			$this->pass->preferAutomatic(saved: $saved, applied: ['other' => $moved]),
			'Another object\'s move must never be answered here.'
		);
	}//end testPreferAutomaticAnswersWithTheMoveWhenThereWasOne()
}//end class
