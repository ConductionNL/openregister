<?php

/**
 * FlowStateBinding loads a flow's stored state into the run context and writes
 * it back only when a step changed it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowState;
use OCA\OpenRegister\Db\FlowStateMapperInterface;
use OCA\OpenRegister\Service\Flow\FlowStateBinding;
use OCA\OpenRegister\Service\Flow\FlowStateHandle;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowStateBinding
 * @covers \OCA\OpenRegister\Service\Flow\FlowStateHandle
 * @uses   \OCA\OpenRegister\Db\FlowRun
 * @uses   \OCA\OpenRegister\Db\FlowState
 */
class FlowStateBindingTest extends TestCase {

	/**
	 * State mapper.
	 *
	 * @var FlowStateMapperInterface&MockObject
	 */
	private FlowStateMapperInterface&MockObject $mapper;

	/**
	 * Logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Class under test.
	 *
	 * @var FlowStateBinding
	 */
	private FlowStateBinding $binding;

	/**
	 * Build the binding over mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper  = $this->createMock(FlowStateMapperInterface::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
		$this->binding = new FlowStateBinding($this->mapper, $this->logger);
	}

	/**
	 * A run for the given flow.
	 *
	 * @param string|null $flowId The flow id.
	 *
	 * @return FlowRun
	 */
	private function flowRun(?string $flowId): FlowRun {
		$run = new FlowRun();
		$run->setFlowId($flowId);
		$run->setUuid('run-1');
		return $run;
	}

	/**
	 * A run without a flow id gets no state handle and no lookup.
	 *
	 * @return void
	 */
	public function testAttachSkipsRunWithoutFlow(): void {
		$this->mapper->expects($this->never())->method('findByFlow');
		$context = [];
		$this->binding->attach($this->flowRun('  '), $context);
		$this->assertArrayNotHasKey(FlowStateHandle::CONTEXT_KEY, $context);
	}

	/**
	 * Stored state is loaded into a clean handle on the context.
	 *
	 * @return void
	 */
	public function testAttachLoadsStoredState(): void {
		$stored = new FlowState();
		$stored->setState(['counter' => 3]);
		$this->mapper->expects($this->once())->method('findByFlow')->with('flow-1')->willReturn($stored);

		$context = [];
		$this->binding->attach($this->flowRun('flow-1'), $context);

		$handle = $context[FlowStateHandle::CONTEXT_KEY];
		$this->assertSame(['counter' => 3], $handle->all());
		$this->assertFalse($handle->isDirty());
	}

	/**
	 * A flow without stored state starts from an empty handle.
	 *
	 * @return void
	 */
	public function testAttachWithoutStoredStateStartsEmpty(): void {
		$this->mapper->method('findByFlow')->willReturn(null);
		$context = [];
		$this->binding->attach($this->flowRun('flow-1'), $context);
		$this->assertSame([], $context[FlowStateHandle::CONTEXT_KEY]->all());
	}

	/**
	 * A changed handle is written back under the trimmed flow id.
	 *
	 * @return void
	 */
	public function testPersistWritesChangedState(): void {
		$handle = new FlowStateHandle(['a' => 1]);
		$handle->set('b', 2);
		$this->mapper->expects($this->once())->method('put')->with('flow-1', ['a' => 1, 'b' => 2])->willReturn(new FlowState());
		$this->logger->expects($this->never())->method('error');

		$this->binding->persist($this->flowRun(' flow-1 '), [FlowStateHandle::CONTEXT_KEY => $handle]);
	}

	/**
	 * An untouched handle, a missing handle, or a foreign value is not written.
	 *
	 * @return void
	 */
	public function testPersistSkipsUnchangedOrMissingHandle(): void {
		$this->mapper->expects($this->never())->method('put');
		$this->binding->persist($this->flowRun('flow-1'), [FlowStateHandle::CONTEXT_KEY => new FlowStateHandle(['a' => 1])]);
		$this->binding->persist($this->flowRun('flow-1'), []);
		$this->binding->persist($this->flowRun('flow-1'), [FlowStateHandle::CONTEXT_KEY => ['a' => 1]]);
	}

	/**
	 * A changed handle on a run without a flow id is not written.
	 *
	 * @return void
	 */
	public function testPersistSkipsRunWithoutFlow(): void {
		$handle = new FlowStateHandle();
		$handle->set('a', 1);
		$this->mapper->expects($this->never())->method('put');
		$this->binding->persist($this->flowRun(null), [FlowStateHandle::CONTEXT_KEY => $handle]);
	}

	/**
	 * A failed write is logged with the flow and run, not thrown.
	 *
	 * @return void
	 */
	public function testPersistFailureIsLogged(): void {
		$handle = new FlowStateHandle();
		$handle->set('a', 1);
		$this->mapper->method('put')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('error')->with(
			'[FlowStateBinding] Could not persist flow state',
			$this->callback(static fn (array $c) => $c['flow'] === 'flow-1' && $c['run'] === 'run-1' && $c['error'] === 'db down')
		);

		$this->binding->persist($this->flowRun('flow-1'), [FlowStateHandle::CONTEXT_KEY => $handle]);
	}
}
