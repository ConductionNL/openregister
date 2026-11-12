<?php

/**
 * FlowRunStepMapperSequenceTest: where a resumed run continues numbering its steps, and clearing a run's steps.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\FlowRunStepMapper;
use OCA\OpenRegister\Tests\Unit\Db\Support\RecordsQueries;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\FlowRunStepMapper
 */
class FlowRunStepMapperSequenceTest extends TestCase {
	use RecordsQueries;

	public function testHighestSequenceIsTheMaximumRecordedForTheRun(): void {
		$this->rowSets = [[['top' => '7']]];

		$this->assertSame(7, (new FlowRunStepMapper($this->recordingConnection()))->highestSequence('r1'));
		$this->assertSame(['selectAlias(max(sequence), top)', 'from(openregister_flow_steps)', "where(eq(run_uuid, 'r1'))", 'executeQuery'], $this->queries[0]);
	}//end testHighestSequenceIsTheMaximumRecordedForTheRun()

	public function testARunWithoutStepsStartsBelowZero(): void {
		$this->rowSets = [[['top' => null]]];

		$this->assertSame(-1, (new FlowRunStepMapper($this->recordingConnection()))->highestSequence('r1'));
	}//end testARunWithoutStepsStartsBelowZero()

	public function testNoRowAtAllAlsoStartsBelowZero(): void {
		$this->rowSets = [[]];

		$this->assertSame(-1, (new FlowRunStepMapper($this->recordingConnection()))->highestSequence('r1'));
	}//end testNoRowAtAllAlsoStartsBelowZero()

	public function testSequenceZeroIsARealStep(): void {
		$this->rowSets = [[['top' => 0]]];

		$this->assertSame(0, (new FlowRunStepMapper($this->recordingConnection()))->highestSequence('r1'));
	}//end testSequenceZeroIsARealStep()

	public function testDeleteByRunRemovesOnlyThatRunsSteps(): void {
		$this->statementResults = [4];

		$this->assertSame(4, (new FlowRunStepMapper($this->recordingConnection()))->deleteByRun('r1'));
		$this->assertSame(['delete(openregister_flow_steps)', "where(eq(run_uuid, 'r1'))", 'executeStatement'], $this->queries[0]);
	}//end testDeleteByRunRemovesOnlyThatRunsSteps()
}//end class
