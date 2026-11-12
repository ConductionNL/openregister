<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Search\Service\KanbanBoardRun}.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search\Service;

use OCA\OpenRegister\Search\Service\KanbanBoardRun;
use PHPUnit\Framework\TestCase;

/**
 * One kanban board fan-out: one paged query per column.
 *
 * @covers \OCA\OpenRegister\Search\Service\KanbanBoardRun
 */
class KanbanBoardRunTest extends TestCase {

	/**
	 * Paging defaults to 20/0 and a non-array column order reads as null.
	 *
	 * @return void
	 */
	public function testDefaults(): void {
		$run = new KanbanBoardRun(['groupByField' => 'status', 'columnOrder' => 'todo,done'], []);

		$this->assertSame('status', $run->groupByField);
		$this->assertNull($run->columnOrder);
		$this->assertSame(20, $run->limit);
		$this->assertSame(0, $run->offset);

	}//end testDefaults()

	/**
	 * Request paging is cast to int and an array column order is kept.
	 *
	 * @return void
	 */
	public function testRequestParamsAndColumnOrder(): void {
		$run = new KanbanBoardRun(
			['groupByField' => 'stage', 'columnOrder' => ['new', 'open']],
			['_limit' => '5', '_offset' => '10']
		);

		$this->assertSame(['new', 'open'], $run->columnOrder);
		$this->assertSame(5, $run->limit);
		$this->assertSame(10, $run->offset);

	}//end testRequestParamsAndColumnOrder()

	/**
	 * Each column gets the base query pinned to its value with the board's paging, base untouched.
	 *
	 * @return void
	 */
	public function testColumnQueries(): void {
		$run  = new KanbanBoardRun(['groupByField' => 'status'], ['_limit' => 3, '_offset' => 6]);
		$base = ['_search' => 'foo', 'status' => 'ignored', '_limit' => 100];

		$queries = $run->columnQueries($base, ['todo', 'done']);

		$this->assertSame(
			[
				['_search' => 'foo', 'status' => 'todo', '_limit' => 3, '_offset' => 6],
				['_search' => 'foo', 'status' => 'done', '_limit' => 3, '_offset' => 6],
			],
			$queries
		);
		$this->assertSame([], $run->columnQueries($base, []));

	}//end testColumnQueries()
}//end class
