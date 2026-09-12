<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Service\Flow\FlowDefinitionBuilder;
use OCA\OpenRegister\Service\Flow\FlowEngine;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent;
use OCA\OpenRegister\Tests\Unit\Service\Flow\PublishedVersionDouble;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/** A subject whose serialisation carries a fixed field, for FlowItems::fromSubject(). */
class ExecuteNodeSubject {
	public function jsonSerialize(): array {
		return ['case' => 'c-1'];
	}
}

/** Counts calls and echoes items through, so "did this run" is directly assertable. */
class CountingNode implements IFlowNode {
	public int $calls = 0;

	public function __construct(
		private readonly string $id,
	) {
	}

	public function getId(): string {
		return $this->id;
	}

	public function getDisplayName(): string {
		return 'Counter';
	}

	public function getDescription(): string {
		return 'Counts.';
	}

	public function getIcon(): string {
		return 'i.svg';
	}

	public function isAvailableForScope(int $scope): bool {
		return true;
	}

	public function validateConfig(array $config): void {
	}

	public function execute(array $items, array $config, array $context): array {
		$this->calls++;

		return $items;
	}
}

/** Always throws, to exercise the FAILED path. */
class ExplodingNode implements IFlowNode {
	public function getId(): string {
		return 'test.explode';
	}

	public function getDisplayName(): string {
		return 'Explode';
	}

	public function getDescription(): string {
		return 'Always throws.';
	}

	public function getIcon(): string {
		return 'i.svg';
	}

	public function isAvailableForScope(int $scope): bool {
		return true;
	}

	public function validateConfig(array $config): void {
	}

	public function execute(array $items, array $config, array $context): array {
		throw new RuntimeException('the node refused');
	}
}

/** Always suspends — the direct-invoke endpoint does not support this. */
class SuspendingNode implements IFlowNode {
	public function getId(): string {
		return 'test.suspend';
	}

	public function getDisplayName(): string {
		return 'Suspend';
	}

	public function getDescription(): string {
		return 'Always suspends.';
	}

	public function getIcon(): string {
		return 'i.svg';
	}

	public function isAvailableForScope(int $scope): bool {
		return true;
	}

	public function validateConfig(array $config): void {
	}

	public function execute(array $items, array $config, array $context): array {
		throw new FlowSuspension(new \DateTime('@1900000000'), 'waiting for nothing in particular');
	}
}

/**
 * Unit tests for `FlowRunService::executeNode()` (or-flow-run-node task 2):
 * the "run exactly ONE node, not to the end" mode the direct-invoke endpoint
 * needs, distinct from `execute()`'s `startAt`.
 */
class FlowRunServiceExecuteNodeTest extends TestCase {
	use PublishedVersionDouble;

	private FlowRunMapper $mapper;
	private FlowRunService $service;
	private CountingNode $target;
	private CountingNode $downstream;

	protected function setUp(): void {
		parent::setUp();

		$this->mapper = $this->createMock(FlowRunMapper::class);
		$this->mapper->method('insert')->willReturnArgument(0);
		$this->mapper->method('update')->willReturnArgument(0);

		$this->target = new CountingNode('test.target');
		$this->downstream = new CountingNode('test.downstream');
		$exploding = new ExplodingNode();
		$suspending = new SuspendingNode();

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				if ($event instanceof RegisterFlowNodesEvent) {
					$event->registerNode($this->target);
					$event->registerNode($this->downstream);
					$event->registerNode(new ExplodingNode());
					$event->registerNode(new SuspendingNode());
				}
			}
		);

		$registry = new FlowNodeRegistry($dispatcher, $this->createMock(LoggerInterface::class));
		$engine = new FlowEngine(new FlowDefinitionBuilder(), $this->createMock(LoggerInterface::class));

		$versions = $this->publishedVersionMapper();
		// The PUBLISHED graph names two nodes joined by a real edge — "target"
		// leads to "downstream". If executeNode() ever walked the graph
		// instead of dispatching the one named step directly, "downstream"
		// would run too. It must not: RN-1's whole subject-authorization
		// boundary is per NODE, and silently running more of the graph than
		// the caller was authorized for would defeat it.
		$pin = $this->pinReturning(
			graph: [
				'nodes' => [
					['id' => 'target', 'type' => 'test.target'],
					['id' => 'downstream', 'type' => 'test.downstream'],
					['id' => 'boom', 'type' => 'test.explode'],
					['id' => 'wait', 'type' => 'test.suspend'],
				],
				'edges' => [
					['id' => 'e1', 'source' => 'target', 'target' => 'downstream'],
				],
			]
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($versions, $pin): object {
				if ($id === \OCA\OpenRegister\Db\FlowVersionMapper::class) {
					return $versions;
				}

				if ($id === \OCA\OpenRegister\Service\Flow\FlowDefinitionPin::class) {
					return $pin;
				}

				throw new RuntimeException('not available');
			}
		);

		$this->service = new FlowRunService(
			$this->mapper,
			$this->createMock(\OCA\OpenRegister\Db\FlowStateMapper::class),
			$engine,
			$registry,
			$this->createMock(LoggerInterface::class),
			$container
		);
	}//end setUp()

	private function flowDoc(): array {
		return ['id' => 'f1', 'nodes' => [], 'edges' => []];
	}//end flowDoc()

	public function testExecuteNodeRunsOnlyTheNamedNodeAndCompletes(): void {
		$run = $this->service->queue(flowId: 'f1', trigger: FlowRunService::TRIGGER_DIRECT_NODE, user: 'alice');

		$result = $this->service->executeNode(run: $run, flow: $this->flowDoc(), subject: new ExecuteNodeSubject(), nodeId: 'target');

		$this->assertSame(FlowRun::STATUS_COMPLETED, $result->getStatus());
		$this->assertSame(1, $this->target->calls);
	}//end testExecuteNodeRunsOnlyTheNamedNodeAndCompletes()

	/**
	 * 🔴 or#3642 CORE GUARANTEE. `executeNode()` must dispatch ONLY the named
	 * node — never route to whatever it points at in the graph. Mutation
	 * check: replace the direct `RegistryStepDispatcher::dispatch()` call
	 * with `$this->engine->run(...)` (a full graph walk) and this reddens —
	 * `downstream`'s counter goes from 0 to 1.
	 */
	public function testExecuteNodeDoesNotRunDownstreamNodes(): void {
		$run = $this->service->queue(flowId: 'f1', trigger: FlowRunService::TRIGGER_DIRECT_NODE, user: 'alice');

		$this->service->executeNode(run: $run, flow: $this->flowDoc(), subject: new ExecuteNodeSubject(), nodeId: 'target');

		$this->assertSame(1, $this->target->calls, 'the named node must run exactly once');
		$this->assertSame(0, $this->downstream->calls, 'nothing downstream of it may run');
	}//end testExecuteNodeDoesNotRunDownstreamNodes()

	public function testExecuteNodeFailsWhenTheNodeThrows(): void {
		$run = $this->service->queue(flowId: 'f1', trigger: FlowRunService::TRIGGER_DIRECT_NODE, user: 'alice');

		$result = $this->service->executeNode(run: $run, flow: $this->flowDoc(), subject: new ExecuteNodeSubject(), nodeId: 'boom');

		$this->assertSame(FlowRun::STATUS_FAILED, $result->getStatus());
		$this->assertStringContainsString('the node refused', (string)$result->getError());
	}//end testExecuteNodeFailsWhenTheNodeThrows()

	public function testExecuteNodeMarksAnUnsupportedSuspensionAsFailed(): void {
		$run = $this->service->queue(flowId: 'f1', trigger: FlowRunService::TRIGGER_DIRECT_NODE, user: 'alice');

		$result = $this->service->executeNode(run: $run, flow: $this->flowDoc(), subject: new ExecuteNodeSubject(), nodeId: 'wait');

		$this->assertSame(FlowRun::STATUS_FAILED, $result->getStatus());
		$this->assertStringContainsString('does not support', (string)$result->getError());
	}//end testExecuteNodeMarksAnUnsupportedSuspensionAsFailed()

	/**
	 * 🔴 A node id that is not part of the PUBLISHED graph this run is
	 * pinned to must fail the run rather than silently doing nothing or
	 * throwing an uncaught error — a caller resolved the node moments ago
	 * (the controller's own check), so this is either a republish race or a
	 * draft-only id, and either way it is the run's problem to report.
	 */
	public function testExecuteNodeFailsWhenTheNodeIdIsNotInThePublishedGraph(): void {
		$run = $this->service->queue(flowId: 'f1', trigger: FlowRunService::TRIGGER_DIRECT_NODE, user: 'alice');

		$result = $this->service->executeNode(run: $run, flow: $this->flowDoc(), subject: new ExecuteNodeSubject(), nodeId: 'ghost');

		$this->assertSame(FlowRun::STATUS_FAILED, $result->getStatus());
		$this->assertSame(0, $this->target->calls);
		$this->assertSame(0, $this->downstream->calls);
	}//end testExecuteNodeFailsWhenTheNodeIdIsNotInThePublishedGraph()

	/**
	 * A run that queue() did not hand back QUEUED (parked awaiting delegated
	 * consent, or already advanced by a racing call) must not be double-run.
	 * Mutation check: delete the status guard at the top of executeNode()
	 * and this reddens — the node runs a second time.
	 */
	public function testExecuteNodeDoesNothingWhenTheRunIsNotQueued(): void {
		$run = $this->service->queue(flowId: 'f1', trigger: FlowRunService::TRIGGER_DIRECT_NODE, user: 'alice');
		$run->setStatus(FlowRun::STATUS_RUNNING);

		$result = $this->service->executeNode(run: $run, flow: $this->flowDoc(), subject: new ExecuteNodeSubject(), nodeId: 'target');

		$this->assertSame(FlowRun::STATUS_RUNNING, $result->getStatus());
		$this->assertSame(0, $this->target->calls);
	}//end testExecuteNodeDoesNothingWhenTheRunIsNotQueued()

}//end class
