<?php

/**
 * The inbox page window and the sequence advance reason.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Task
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/flow-tasks/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Service\Task\PositionAdvance;
use OCA\OpenRegister\Service\Task\TaskInboxPage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Task\TaskInboxPage
 * @covers \OCA\OpenRegister\Service\Task\PositionAdvance
 * @uses \OCA\OpenRegister\Db\Task
 */
final class TaskInboxValuesTest extends TestCase {

	/**
	 * Requested windows and the window kept.
	 *
	 * @return array<string, array<int, int>>
	 */
	public static function windows(): array {
		return [
			'as asked' => [50, 100, 50, 100],
			'a limit below one is one' => [0, 0, 1, 0],
			'a negative limit is one' => [-5, 0, 1, 0],
			'a limit above 500 is 500' => [10000, 0, 500, 0],
			'exactly 500 stays' => [500, 0, 500, 0],
			'a negative offset is zero' => [25, -10, 25, 0],
		];
	}//end windows()

	#[DataProvider('windows')]
	public function testTheWindowIsClamped(int $limit, int $offset, int $keptLimit, int $keptOffset): void {
		$page = new TaskInboxPage($limit, $offset);

		$this->assertSame($keptLimit, $page->limit);
		$this->assertSame($keptOffset, $page->offset);
	}//end testTheWindowIsClamped()

	public function testTheDefaultIsTheFirstTwentyFive(): void {
		$page = new TaskInboxPage();

		$this->assertSame(['results' => [], 'total' => 0, 'limit' => 25, 'offset' => 0], $page->envelope([], 0));
	}//end testTheDefaultIsTheFirstTwentyFive()

	public function testTheEnvelopeCarriesResultsTotalAndTheKeptWindow(): void {
		$rows = [['id' => 1], ['id' => 2]];

		$this->assertSame(
			['results' => $rows, 'total' => 42, 'limit' => 500, 'offset' => 40],
			(new TaskInboxPage(9999, 40))->envelope($rows, 42)
		);
	}//end testTheEnvelopeCarriesResultsTotalAndTheKeptWindow()

	public function testTheAdvanceReasonNamesBothPositions(): void {
		$completed = new Task();
		$completed->setSequencePosition(2);
		$next = new Task();
		$next->setSequencePosition(3);

		$advance = new PositionAdvance($completed, $next);

		$this->assertSame('Position 2 approved; position 3 is now enabled.', $advance->reason());
		$this->assertSame($completed, $advance->completed);
		$this->assertSame($next, $advance->next);
	}//end testTheAdvanceReasonNamesBothPositions()

	public function testAnUnpositionedTaskReadsAsPositionZero(): void {
		$this->assertSame('Position 0 approved; position 1 is now enabled.', (new PositionAdvance(new Task(), (function (): Task {
			$t = new Task();
			$t->setSequencePosition(1);
			return $t;
		})()))->reason());
	}//end testAnUnpositionedTaskReadsAsPositionZero()
}//end class
