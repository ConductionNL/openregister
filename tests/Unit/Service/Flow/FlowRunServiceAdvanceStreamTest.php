<?php

/**
 * FlowRunService::advanceStream(): the in-request advance budget follows the
 * completing branch and never blocks the request.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-completions-advance-budget-must-apply-to-the-completing-branch
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\FlowStateMapper;
use OCA\OpenRegister\Db\FlowStreamMapper;
use OCA\OpenRegister\Service\Flow\FlowEngine;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\FlowPlaceClaims;
use OCA\OpenRegister\Service\Flow\FlowRunCommit;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowStreamWalk;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * The advance budget.
 */
class FlowRunServiceAdvanceStreamTest extends TestCase {

	private FlowRunMapper&MockObject $mapper;

	private FlowEngine&MockObject $engine;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(FlowRunMapper::class);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->engine = $this->createMock(FlowEngine::class);
	}//end setUp()

	/**
	 * The service, with or without the stream layer.
	 *
	 * @param bool $withStreams Whether to wire the three stream collaborators.
	 *
	 * @return FlowRunService The service.
	 */
	private function service(bool $withStreams): FlowRunService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('not available'));
		$registry = $this->createMock(FlowNodeRegistry::class);

		if ($withStreams === false) {
			return new FlowRunService(
				$this->mapper,
				$this->createMock(FlowStateMapper::class),
				$this->engine,
				$registry,
				$this->createMock(LoggerInterface::class),
				$container
			);
		}

		return new FlowRunService(
			$this->mapper,
			$this->createMock(FlowStateMapper::class),
			$this->engine,
			$registry,
			$this->createMock(LoggerInterface::class),
			$container,
			null,
			null,
			$this->createMock(FlowStreamMapper::class),
			$this->createMock(FlowPlaceClaims::class),
			$this->createMock(FlowRunCommit::class)
		);
	}//end service()

	/**
	 * A suspended run.
	 *
	 * @return FlowRun The run.
	 */
	private function aRun(): FlowRun {
		$run = new FlowRun();
		$run->setUuid('run-1');
		$run->setFlowId('flow-1');
		$run->setStatus(FlowRun::STATUS_SUSPENDED);
		$run->setMarking(['task' => 1, 'other' => 1]);
		$run->setItems([]);

		return $run;
	}//end aRun()

	public function testABudgetOfZeroLeavesTheRunToTheQueue(): void {
		$this->engine->expects($this->never())->method('run');

		$run = $this->service(withStreams: true)->advanceStream(run: $this->aRun(), flow: ['id' => 'flow-1'], subject: new stdClass(), streamId: 's1', budget: 0);

		$this->assertSame(FlowRun::STATUS_QUEUED, $run->getStatus());
	}//end testABudgetOfZeroLeavesTheRunToTheQueue()

	public function testWithoutTheStreamLayerThereIsNoBranchToScopeToSoTheQueueAdvances(): void {
		$this->engine->expects($this->never())->method('run');

		$run = $this->service(withStreams: false)->advanceStream(run: $this->aRun(), flow: ['id' => 'flow-1'], subject: new stdClass(), streamId: 's1', budget: 'all');

		$this->assertSame(FlowRun::STATUS_QUEUED, $run->getStatus());
	}//end testWithoutTheStreamLayerThereIsNoBranchToScopeToSoTheQueueAdvances()

	/**
	 * 🔴 D3 REGRESSION, the persistence seam: the sync in-request advance
	 * (task completion with `advance: "all"`) hands the engine the RUN'S
	 * stored items and persists exactly the items the walk returns. The
	 * worker path already did both; this pins the sync path to the same
	 * contract, so a run can never come out of a correct walk with `items`
	 * emptied.
	 *
	 * @return void
	 */
	public function testTheSyncAdvanceCarriesTheStoredItemsInAndPersistsTheWalkItemsOut(): void {
		$seenItems = null;
		$this->engine->expects($this->once())->method('run')->willReturnCallback(
			function () use (&$seenItems): array {
				$args = func_get_args();
				// run(flow, store, subject, dispatcher, context, items, startAt, streams):
				// PHPUnit hands named arguments through positionally.
				$seenItems = $args[5];

				return [
					'status' => FlowEngine::STATUS_COMPLETED,
					'log' => [],
					'context' => [],
					'items' => [\OCA\OpenRegister\Service\Flow\FlowItems::item(json: ['name' => 'Case 7', 'branch' => 'rejected'])],
				];
			}
		);

		$run = $this->aRun();
		$run->setItems([\OCA\OpenRegister\Service\Flow\FlowItems::item(json: ['name' => 'Case 7'])]);

		$after = $this->service(withStreams: true)->advanceStream(
			run: $run,
			flow: ['id' => 'flow-1'],
			subject: new stdClass(),
			streamId: 's1',
			budget: 'all'
		);

		$this->assertSame('Case 7', ($seenItems[0]['json']['name'] ?? null), 'the walk resumes from the STORED items');
		$this->assertSame('rejected', ($after->getItems()[0]['json']['branch'] ?? null), 'the walk\'s items are persisted, never dropped');
	}//end testTheSyncAdvanceCarriesTheStoredItemsInAndPersistsTheWalkItemsOut()

	public function testABudgetWalksOnlyTheCompletingStreamThroughTheEngine(): void {
		$seen = null;
		$this->engine->expects($this->once())->method('run')->willReturnCallback(
			function () use (&$seen): array {
				$args = func_get_args();
				// `streams` is the last argument of run(); PHPUnit hands named
				// arguments through positionally.
				$seen = end($args);
				return ['status' => FlowEngine::STATUS_QUEUED, 'log' => [], 'context' => [], 'items' => []];
			}
		);

		$run = $this->service(withStreams: true)->advanceStream(run: $this->aRun(), flow: ['id' => 'flow-1', 'limits' => ['streams' => 3]], subject: new stdClass(), streamId: 's1', budget: 2);

		$this->assertInstanceOf(FlowStreamWalk::class, $seen);
		// The walk is scoped: two firings at most, one stream only.
		$this->assertFalse($seen->budgetSpent());
		$this->assertSame(FlowRun::STATUS_QUEUED, $run->getStatus());
	}//end testABudgetWalksOnlyTheCompletingStreamThroughTheEngine()
}//end class
