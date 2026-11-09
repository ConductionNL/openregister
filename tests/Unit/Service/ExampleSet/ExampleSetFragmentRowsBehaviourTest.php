<?php

/**
 * Behaviour tests for ExampleSetFragmentRows: the rows a fragment wrote, or the
 * stand-ins a check resolves against before anything is written.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;


use OCA\OpenRegister\Service\ExampleSet\ExampleSetFragmentRows;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour of the written-row lookup the set's placeholders resolve against.
 */
class ExampleSetFragmentRowsBehaviourTest extends TestCase {

	public function testWithoutARecordEveryRowGetsAStandInUuid(): void {
		$rows = (new ExampleSetFragmentRows())->viewsOf(['app' => 'alpha', 'views' => [['key' => 'v1'], ['key' => 2]]], null);

		$this->assertSame(
			['v1' => ['uuid' => 'example-set-stand-in:view:v1'], 2 => ['uuid' => 'example-set-stand-in:view:2']],
			$rows
		);
	}

	public function testWithARecordOnlyRowsTheAppWroteAreReturned(): void {
		$record = ['tasks' => ['alpha/t1' => ['uuid' => 'real-1'], 'beta/t2' => ['uuid' => 'other'], 'alpha/t3' => 'not-a-row']];

		$rows = (new ExampleSetFragmentRows())->tasksOf(['app' => 'alpha', 'tasks' => [['key' => 't1'], ['key' => 't2'], ['key' => 't3']]], $record);

		$this->assertSame(['t1' => ['uuid' => 'real-1']], $rows);
	}

	public function testAFragmentWithoutThePartHasNoRows(): void {
		$this->assertSame([], (new ExampleSetFragmentRows())->viewsOf(['app' => 'alpha'], null));
	}

	public function testRecordedFlowsAreReturnedAsWritten(): void {
		$record = ['flows' => ['alpha/f1' => ['uuid' => 'flow-real', 'runs' => ['r1' => 'run-real']]]];

		$rows = (new ExampleSetFragmentRows())->flowsOf(
			['app' => 'alpha', 'flows' => [['key' => 'f1', 'data' => ['runs' => [['key' => 'r1']]]]]],
			$record
		);

		$this->assertSame(['f1' => ['uuid' => 'flow-real', 'runs' => ['r1' => 'run-real']]], $rows);
	}

	public function testUnrecordedFlowsGetStandInRunsAndSuspendedRunsWaitOnTheirNode(): void {
		$fragment = [
			'app' => 'alpha',
			'flows' => [
				[
					'key' => 'f1',
					'data' => [
						'runs' => [
							['key' => 'done', 'status' => 'completed'],
							['key' => 'broke', 'status' => 'failed', 'node' => 'mail'],
							['key' => 'held', 'status' => 'suspended', 'node' => 'approve'],
							['key' => 'held2', 'status' => 'suspended'],
							['status' => 'completed'],
						],
					],
				],
				['key' => 'f2', 'data' => []],
			],
		];

		$rows = (new ExampleSetFragmentRows())->flowsOf($fragment, null);

		$this->assertSame(
			[
				'f1' => [
					'uuid' => 'example-set-stand-in:flow:f1',
					'runs' => [
						'done' => 'example-set-stand-in:run:f1.done',
						'broke' => 'example-set-stand-in:run:f1.broke',
						'held' => 'example-set-stand-in:run:f1.held',
						'held2' => 'example-set-stand-in:run:f1.held2',
					],
					'waitsOn' => [
						'example-set-stand-in:run:f1.held' => 'approve',
						'example-set-stand-in:run:f1.held2' => '',
					],
				],
				'f2' => ['uuid' => 'example-set-stand-in:flow:f2'],
			],
			$rows
		);
	}
}//end class
