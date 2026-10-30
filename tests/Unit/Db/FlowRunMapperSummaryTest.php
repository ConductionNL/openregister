<?php

/**
 * The per-flow run summary: every requested flow answered, counts and the
 * newest run joined onto the right flow.
 *
 * The two query seams are stubbed, so what is asserted is how
 * `summarizeByFlow()` assembles their answers, not the SQL.
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

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FlowRunMapperSummaryTest extends TestCase {

	/**
	 * A mapper whose two summary seams answer from fixed data.
	 *
	 * @param array<string, int>     $counts Runs since, per flow.
	 * @param array<int, FlowRun>    $latest Newest run per flow.
	 *
	 * @return FlowRunMapper&MockObject The mapper.
	 */
	private function mapper(array $counts, array $latest): FlowRunMapper&MockObject {
		$mapper = $this->getMockBuilder(FlowRunMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['countSinceByFlow', 'latestRunsByFlow'])
			->getMock();
		$mapper->method('countSinceByFlow')->willReturn($counts);
		$mapper->method('latestRunsByFlow')->willReturn($latest);

		return $mapper;
	}

	private function latestRun(string $flowId, string $status): FlowRun {
		$run = new FlowRun();
		$run->setUuid($flowId . '-latest');
		$run->setFlowId($flowId);
		$run->setStatus($status);
		$error = null;
		if ($status === FlowRun::STATUS_FAILED) {
			$error = 'boom';
		}

		$run->setError($error);
		$run->setCreated(new DateTime('2026-10-15T08:41:00+00:00'));
		$run->setUpdated(new DateTime('2026-10-15T08:41:01+00:00'));

		return $run;
	}

	public function testEveryRequestedFlowIsAnsweredAndNeverRunReadsAsNull(): void {
		$mapper = $this->mapper(['flow-a' => 31], [$this->latestRun('flow-a', FlowRun::STATUS_FAILED)]);

		$summary = $mapper->summarizeByFlow(['flow-a', 'flow-b', ' '], new DateTime('-7 days'));

		$this->assertSame(['flow-a', 'flow-b'], array_keys($summary));
		$this->assertSame(31, $summary['flow-a']['runsSince']);
		$this->assertSame('failed', $summary['flow-a']['lastRun']['status']);
		$this->assertSame('boom', $summary['flow-a']['lastRun']['error']);
		$this->assertSame('2026-10-15T08:41:00+00:00', $summary['flow-a']['lastRun']['created']);
		$this->assertSame(['lastRun' => null, 'runsSince' => 0], $summary['flow-b']);
	}

	public function testRowsForFlowsNobodyAskedAboutAreIgnored(): void {
		$mapper = $this->mapper(['flow-x' => 9], [$this->latestRun('flow-x', FlowRun::STATUS_COMPLETED)]);

		$summary = $mapper->summarizeByFlow(['flow-a'], new DateTime('-7 days'));

		$this->assertSame(['flow-a' => ['lastRun' => null, 'runsSince' => 0]], $summary);
	}

	public function testNoFlowsMeansNoQuery(): void {
		$mapper = $this->getMockBuilder(FlowRunMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['countSinceByFlow', 'latestRunsByFlow'])
			->getMock();
		$mapper->expects($this->never())->method('countSinceByFlow');
		$mapper->expects($this->never())->method('latestRunsByFlow');

		$this->assertSame([], $mapper->summarizeByFlow([], new DateTime()));
	}
}
