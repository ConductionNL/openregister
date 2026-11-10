<?php

/**
 * How a run reads as ended, from its status and its log.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Db;

use OCA\OpenRegister\Db\FlowRun;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FlowRun::getOutcome().
 */
class FlowRunOutcomeTest extends TestCase {
	/**
	 * A run with a status and a log.
	 *
	 * @param string                           $status The status.
	 * @param array<int, array<string, mixed>> $log    The log.
	 *
	 * @return FlowRun
	 */
	private function runWith(string $status, array $log): FlowRun {
		$run = new FlowRun();
		$run->setStatus($status);
		$run->setLog($log);

		return $run;
	}

	public function testACleanEndReadsAsCompleted(): void {
		$run = $this->runWith(FlowRun::STATUS_STOPPED, [
			['transition' => 'write', 'status' => 'completed'],
			['transition' => 'end', 'type' => 'openregister.end', 'status' => 'stopped', 'reason' => 'Flow stopped', 'checkId' => null],
		]);

		self::assertSame(FlowRun::STATUS_COMPLETED, $run->getOutcome());
		self::assertSame(FlowRun::STATUS_COMPLETED, $run->jsonSerialize()['outcome']);
		self::assertSame(FlowRun::STATUS_STOPPED, $run->jsonSerialize()['status']);
	}

	public function testAStoredRunWhoseStopHasNoCheckIdKeyReadsAsCompleted(): void {
		$run = $this->runWith(FlowRun::STATUS_STOPPED, [['transition' => 'end', 'status' => 'stopped']]);

		self::assertSame(FlowRun::STATUS_COMPLETED, $run->getOutcome());
	}

	public function testAStopFromAnOversightCheckStaysStopped(): void {
		$run = $this->runWith(FlowRun::STATUS_STOPPED, [
			['transition' => 'write', 'status' => 'stopped', 'reason' => 'Over budget', 'checkId' => 'budget'],
		]);

		self::assertSame(FlowRun::STATUS_STOPPED, $run->getOutcome());
	}

	public function testAStoppedRunWithoutAStopInItsLogStaysStopped(): void {
		self::assertSame(FlowRun::STATUS_STOPPED, $this->runWith(FlowRun::STATUS_STOPPED, [])->getOutcome());

		$killed = $this->runWith(FlowRun::STATUS_STOPPED, [['transition' => 'write', 'status' => 'failed', 'error' => 'boom']]);
		self::assertSame(FlowRun::STATUS_STOPPED, $killed->getOutcome());
	}

	public function testEveryOtherStatusReadsAsItself(): void {
		self::assertSame(FlowRun::STATUS_FAILED, $this->runWith(FlowRun::STATUS_FAILED, [['status' => 'stopped']])->getOutcome());
		self::assertSame(FlowRun::STATUS_COMPLETED, $this->runWith(FlowRun::STATUS_COMPLETED, [])->getOutcome());
	}
}//end class
