<?php

/**
 * Golden: the marking reads, step-row outcome columns and refusal sentences
 * that used to be written out more than once answer the same from one place.
 *
 * Pinned on the code before the copies were folded together.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunStep;
use OCA\OpenRegister\Db\FlowRunStepMapper;
use OCA\OpenRegister\Service\Flow\FlowRunMarkingStore;
use OCA\OpenRegister\Service\Flow\FlowStepHistory;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowRunMarkingStore
 * @covers \OCA\OpenRegister\Service\Flow\FlowStepHistory
 */
final class FlowDuplicationGoldenTest extends TestCase {

	/**
	 * Every stored marking shape reads back as the places it marks.
	 *
	 * @return void
	 */
	public function testTheStoredMarkingShapes(): void {
		$cases = [
			[null, []],
			[[], []],
			[['a', 'b'], ['a' => 1, 'b' => 1]],
			[['a' => 3, 'b' => 0, 'c' => '2', 'd' => -4], ['a' => 1, 'b' => 1, 'c' => 1, 'd' => 1]],
			[[0 => 'x', 'y' => 2], ['x' => 1, 'y' => 1]],
		];
		foreach ($cases as [$stored, $expected]) {
			$run = new FlowRun();
			$run->setMarking($stored);
			$store = new FlowRunMarkingStore(run: $run);
			$this->assertSame($expected, $store->getMarking(subject: new stdClass())->getPlaces());
		}
	}//end testTheStoredMarkingShapes()

	/**
	 * A synced committed marking is stored normalised, tokens kept.
	 *
	 * @return void
	 */
	public function testASyncedMarkingIsNormalised(): void {
		$run   = new FlowRun();
		$store = new FlowRunMarkingStore(run: $run);
		$store->syncCommitted(marking: ['p', 'q' => 0, 'r' => 5]);
		$this->assertSame(['p' => 1, 'q' => 1, 'r' => 5], $run->getMarking());
	}//end testASyncedMarkingIsNormalised()

	/**
	 * The error and output columns a recorded step row carries.
	 *
	 * @return void
	 */
	public function testTheStepRowOutcomeColumns(): void {
		$rows  = [];
		$steps = $this->createMock(FlowRunStepMapper::class);
		$steps->method('highestSequence')->willReturn(-1);
		$steps->method('insert')->willReturnCallback(
			static function (FlowRunStep $step) use (&$rows): FlowRunStep {
				$rows[] = [$step->getError(), $step->getOutput(), $step->getStatus(), $step->getSequence()];
				return $step;
			}
		);
		$run = new FlowRun();
		$run->setUuid('run-1');
		$run->setFlowId('flow-1');

		(new FlowStepHistory(steps: $steps))->record(
			run: $run,
			entries: [
				['transition' => 'a', 'status' => 'failed', 'error' => 'boom', 'reason' => 'ignored', 'itemsIn' => 2, 'itemsOut' => 0],
				['transition' => 'b', 'status' => 'stopped', 'reason' => 'author stopped it', 'checkId' => 'c1'],
				['transition' => 'c', 'recorded' => true],
				'not an entry',
				['transition' => 'd', 'itemsIn' => null],
			]
		);

		$this->assertSame(
			[
				['boom', ['itemsIn' => 2, 'itemsOut' => 0], 'failed', 0],
				['author stopped it', ['checkId' => 'c1'], 'stopped', 1],
				[null, [], 'unknown', 2],
			],
			$rows
		);
	}//end testTheStepRowOutcomeColumns()
}//end class
