<?php

/**
 * Unit tests for FlowStepHistory — how a run's steps are numbered and recorded.
 *
 * The numbering is the part that matters most and is hardest to see. An audit
 * row is stamped with a step number DURING the walk; the step row is written
 * after it. Both sides compute that number independently, and if they disagree
 * the attributed row and its step row describe different steps — which is worse
 * than no attribution, because it looks correct.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/flow-object-attribution/specs/flow-object-attribution/spec.md
 */

namespace Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunStep;
use OCA\OpenRegister\Db\FlowRunStepMapper;
use OCA\OpenRegister\Service\Flow\FlowStepHistory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class FlowStepHistoryTest extends TestCase {

	/**
	 * A run with a uuid.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return FlowRun The run.
	 *
	 * Named aRun(), not run(): PHPUnit's TestCase::run() is final.
	 */
	private function aRun(string $uuid = 'run-1'): FlowRun {
		$run = new FlowRun();
		$run->setUuid($uuid);
		$run->setFlowId('flow-1');

		return $run;
	}//end aRun()

	public function testWithNoStepMapperNumberingStartsAtZero(): void {
		$history = new FlowStepHistory();

		$this->assertSame(0, $history->baseFor(runUuid: 'run-1'));
	}//end testWithNoStepMapperNumberingStartsAtZero()

	public function testAnEmptyRunUuidNumbersFromZero(): void {
		$steps = $this->createMock(FlowRunStepMapper::class);
		$steps->expects($this->never())->method('highestSequence');

		$this->assertSame(0, (new FlowStepHistory(steps: $steps))->baseFor(runUuid: '  '));
	}//end testAnEmptyRunUuidNumbersFromZero()

	/**
	 * The base CONTINUES from what is already recorded.
	 *
	 * A run that suspends and resumes days later must read as one ordered
	 * history, not two starting at zero.
	 */
	public function testTheBaseContinuesFromTheHighestRecordedSequence(): void {
		$steps = $this->createMock(FlowRunStepMapper::class);
		$steps->method('highestSequence')->with('run-1')->willReturn(11);

		$this->assertSame(12, (new FlowStepHistory(steps: $steps))->baseFor(runUuid: 'run-1'));
	}//end testTheBaseContinuesFromTheHighestRecordedSequence()

	/**
	 * A failed read degrades to zero rather than failing the run.
	 *
	 * The work is the run; the numbering is the account of it. Wrong only in
	 * its offset, and still ordering the run's writes among themselves.
	 */
	public function testAFailedReadDegradesToZeroAndIsLogged(): void {
		$steps = $this->createMock(FlowRunStepMapper::class);
		$steps->method('highestSequence')->willThrowException(new RuntimeException('db gone'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$history = new FlowStepHistory(steps: $steps, logger: $logger);

		$this->assertSame(0, $history->baseFor(runUuid: 'run-1'));
	}//end testAFailedReadDegradesToZeroAndIsLogged()

	public function testRecordingWithoutAStepMapperIsANoOp(): void {
		$history = new FlowStepHistory();

		$history->record(run: $this->aRun(), entries: [['transition' => 'a', 'status' => 'completed']]);

		$this->addToAssertionCount(1);
	}//end testRecordingWithoutAStepMapperIsANoOp()

	public function testNoEntriesRecordsNothing(): void {
		$steps = $this->createMock(FlowRunStepMapper::class);
		$steps->expects($this->never())->method('insert');

		(new FlowStepHistory(steps: $steps))->record(run: $this->aRun(), entries: []);
	}//end testNoEntriesRecordsNothing()

	/**
	 * 🔑 THE NUMBERS THE ROWS GET ARE THE NUMBERS ATTRIBUTION PREDICTED.
	 *
	 * `baseFor()` is what the engine stamped onto audit rows before the walk;
	 * these are the rows written after it. Asserted together, in one test,
	 * because their agreement is the property — checking either alone would
	 * pass while they disagreed.
	 */
	public function testRecordedSequencesContinueFromTheSameBaseAttributionUsed(): void {
		$steps = $this->createMock(FlowRunStepMapper::class);
		$steps->method('highestSequence')->willReturn(4);

		$recorded = [];
		$steps->method('insert')->willReturnCallback(
			function (FlowRunStep $step) use (&$recorded) {
				$recorded[] = [$step->getNodeId(), $step->getSequence()];

				return $step;
			}
		);

		$history = new FlowStepHistory(steps: $steps);

		$this->assertSame(5, $history->baseFor(runUuid: 'run-1'));

		$history->record(
			run: $this->aRun(),
			entries: [
				['transition' => 'first', 'status' => 'completed'],
				['transition' => 'second', 'status' => 'completed'],
			]
		);

		$this->assertSame([['first', 5], ['second', 6]], $recorded);
	}//end testRecordedSequencesContinueFromTheSameBaseAttributionUsed()

	public function testAFailedInsertDoesNotStopTheRemainingSteps(): void {
		$steps = $this->createMock(FlowRunStepMapper::class);
		$steps->method('highestSequence')->willReturn(0);

		$seen = [];
		$steps->method('insert')->willReturnCallback(
			function (FlowRunStep $step) use (&$seen) {
				$seen[] = $step->getNodeId();
				if ($step->getNodeId() === 'first') {
					throw new RuntimeException('write failed');
				}

				return $step;
			}
		);

		(new FlowStepHistory(steps: $steps, logger: $this->createMock(LoggerInterface::class)))->record(
			run: $this->aRun(),
			entries: [
				['transition' => 'first', 'status' => 'completed'],
				['transition' => 'second', 'status' => 'completed'],
			]
		);

		$this->assertSame(['first', 'second'], $seen, 'One unwritable row must not cost the rest their history.');
	}//end testAFailedInsertDoesNotStopTheRemainingSteps()

	/**
	 * A non-array entry is skipped rather than crashing the recorder.
	 */
	public function testMalformedEntriesAreSkipped(): void {
		$steps = $this->createMock(FlowRunStepMapper::class);
		$steps->method('highestSequence')->willReturn(0);

		$count = 0;
		$steps->method('insert')->willReturnCallback(
			function (FlowRunStep $step) use (&$count) {
				$count++;

				return $step;
			}
		);

		(new FlowStepHistory(steps: $steps))->record(
			run: $this->aRun(),
			entries: ['not an array', ['transition' => 'real', 'status' => 'completed']]
		);

		$this->assertSame(1, $count);
	}//end testMalformedEntriesAreSkipped()

	/**
	 * A stopped step's REASON lands in the error column.
	 *
	 * A thrown step and a deliberately stopped one are each something a person
	 * needs to read back, and they arrive under different keys.
	 */
	public function testAStopReasonIsRecordedAsTheStepsError(): void {
		$steps = $this->createMock(FlowRunStepMapper::class);
		$steps->method('highestSequence')->willReturn(0);

		$errors = [];
		$steps->method('insert')->willReturnCallback(
			function (FlowRunStep $step) use (&$errors) {
				$errors[] = $step->getError();

				return $step;
			}
		);

		(new FlowStepHistory(steps: $steps))->record(
			run: $this->aRun(),
			entries: [
				['transition' => 'a', 'status' => 'failed', 'error' => 'it threw'],
				['transition' => 'b', 'status' => 'stopped', 'reason' => 'author stopped it'],
			]
		);

		$this->assertSame(['it threw', 'author stopped it'], $errors);
	}//end testAStopReasonIsRecordedAsTheStepsError()
}//end class
