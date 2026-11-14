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
 * @uses \OCA\OpenRegister\Db\FlowRunStep
 * @uses \OCA\OpenRegister\Db\LinkColumn
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

	public function testFindByNodeTypeReadsTheNewestStepsOfThatTypeAndStatus(): void {
		$this->rowSets = [[['id' => 9, 'run_uuid' => 'r2', 'node_type' => 'openregister.set-fields', 'status' => 'failed']]];

		$steps = (new FlowRunStepMapper($this->recordingConnection()))->findByNodeType('openregister.set-fields', 'failed', 20);

		$this->assertCount(1, $steps);
		$this->assertSame('r2', $steps[0]->getRunUuid());
		$this->assertSame(
			[
				'select(*)',
				'from(openregister_flow_steps)',
				"where(eq(node_type, 'openregister.set-fields'))",
				"andWhere(eq(status, 'failed'))",
				'orderBy(id, DESC)',
				'setMaxResults(20)',
				'executeQuery',
			],
			$this->queries[0]
		);
	}//end testFindByNodeTypeReadsTheNewestStepsOfThatTypeAndStatus()

	public function testFindByNodeTypeWithoutAStatusReadsEveryStatus(): void {
		$this->rowSets = [[]];

		(new FlowRunStepMapper($this->recordingConnection()))->findByNodeType('acme.step');

		$this->assertSame(
			['select(*)', 'from(openregister_flow_steps)', "where(eq(node_type, 'acme.step'))", 'orderBy(id, DESC)', 'setMaxResults(100)', 'executeQuery'],
			$this->queries[0]
		);
	}//end testFindByNodeTypeWithoutAStatusReadsEveryStatus()

	public function testDeleteOlderThanRemovesStepsCreatedBeforeTheMoment(): void {
		$this->statementResults = [12];

		$removed = (new FlowRunStepMapper($this->recordingConnection()))->deleteOlderThan(new \DateTimeImmutable('2026-10-13 00:00:00'));

		$this->assertSame(12, $removed);
		$this->assertSame(['delete(openregister_flow_steps)', "where(lt(created, '2026-10-13 00:00:00'))", 'executeStatement'], $this->queries[0]);
	}//end testDeleteOlderThanRemovesStepsCreatedBeforeTheMoment()
}//end class
