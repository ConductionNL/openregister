<?php

/**
 * Unit tests for FlowStepOutcome.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowRunStep;
use OCA\OpenRegister\Service\Flow\FlowStepOutcome;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowStepOutcome
 * @uses   \OCA\OpenRegister\Db\FlowRunStep
 */
final class FlowStepOutcomeTest extends TestCase {

	/**
	 * The error wins over the reason, and the counts land in the output.
	 *
	 * @return void
	 */
	public function testTheErrorWinsAndTheCountsAreKept(): void {
		$step = new FlowRunStep();

		FlowStepOutcome::stamp(
			step: $step,
			entry: ['error' => 'boom', 'reason' => 'ignored', 'itemsIn' => 3, 'itemsOut' => 0, 'checkId' => 'c1', 'other' => 'x']
		);

		self::assertSame('boom', $step->getError());
		self::assertSame(['itemsIn' => 3, 'itemsOut' => 0, 'checkId' => 'c1'], $step->getOutput());
	}//end testTheErrorWinsAndTheCountsAreKept()

	/**
	 * Without an error the reason is recorded.
	 *
	 * @return void
	 */
	public function testTheReasonIsTheFallbackError(): void {
		$step = new FlowRunStep();

		FlowStepOutcome::stamp(step: $step, entry: ['reason' => 'skipped', 'itemsIn' => 1]);

		self::assertSame('skipped', $step->getError());
		self::assertSame(['itemsIn' => 1], $step->getOutput());
	}//end testTheReasonIsTheFallbackError()

	/**
	 * An empty entry clears the error and leaves an empty output.
	 *
	 * @return void
	 */
	public function testAnEmptyEntryStampsNothing(): void {
		$step = new FlowRunStep();
		$step->setError('previous');

		FlowStepOutcome::stamp(step: $step, entry: []);

		self::assertNull($step->getError());
		self::assertSame([], $step->getOutput());
	}//end testAnEmptyEntryStampsNothing()

	/**
	 * Of the hop's state delta the row keeps the key names per item, never the values.
	 * @return void
	 */
	public function testTheStateDeltaIsKeptAsKeyNames(): void {
		$step = new FlowRunStep();

		FlowStepOutcome::stamp(
			step: $step,
			entry: ['itemsIn' => 2, 'itemsOut' => 2, 'produced' => [['status' => 'open', 'assignee' => 'ann'], [], [7 => 'x']]]
		);

		self::assertSame(['itemsIn' => 2, 'itemsOut' => 2, 'produced' => [['status', 'assignee'], [], ['7']]], $step->getOutput());
	}//end testTheStateDeltaIsKeptAsKeyNames()

	/**
	 * A delta that is not a list of deltas is ignored; a non-array member names nothing.
	 * @return void
	 */
	public function testAMalformedDeltaIsNotStored(): void {
		$step = new FlowRunStep();
		FlowStepOutcome::stamp(step: $step, entry: ['produced' => 'oops']);
		self::assertSame([], $step->getOutput());

		$step = new FlowRunStep();
		FlowStepOutcome::stamp(step: $step, entry: ['produced' => ['oops', ['a' => 1]]]);
		self::assertSame(['produced' => [[], ['a']]], $step->getOutput());
	}//end testAMalformedDeltaIsNotStored()
}//end class
