<?php

/**
 * FlowRunService: the last-run write-back and the unattributed-refusal record.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/or-flow-connectivity-and-last-run/specs/flow-connectivity/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\FlowStateMapper;
use OCA\OpenRegister\Service\Flow\FlowEngine;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistryInterface;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowRunStores;
use OCA\OpenRegister\Service\Flow\FlowRunWalkServices;
use OCA\OpenRegister\Service\Flow\FlowUnattributed;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * A run that reaches a terminal state writes its outcome onto its flow; a
 * non-terminal run (queued, running, suspended) leaves the flow's last run
 * untouched. An unattributed refusal marks the flow in error and, only for a
 * schedule, disables it.
 *
 * The two methods under test are private: both are reached only
 * through the container-wired dispatch, so a unit test drives them directly.
 *
 * @covers \OCA\OpenRegister\Service\Flow\FlowRunService
 * @uses   \OCA\OpenRegister\Service\Flow\FlowRunStores
 * @uses   \OCA\OpenRegister\Service\Flow\FlowRunWalkServices
 * @uses   \OCA\OpenRegister\Service\Flow\FlowUnattributed
 * @uses   \OCA\OpenRegister\Db\FlowRun
 * @uses   \OCA\OpenRegister\Db\Flow
 */
final class FlowRunServiceLastRunTest extends TestCase {

	/**
	 * @var FlowMapper&MockObject
	 */
	private $flowMapper;

	/**
	 * The service under test.
	 *
	 * @var FlowRunService
	 */
	private FlowRunService $service;

	/**
	 * A service whose container answers only the FlowMapper lookup.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->flowMapper = $this->createMock(originalClassName: FlowMapper::class);
		$container        = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				if ($id === 'OCA\OpenRegister\Db\FlowMapper') {
					return $this->flowMapper;
				}

				throw new \RuntimeException('not available: ' . $id);
			}
		);

		$this->service = new FlowRunService(
			stores: new FlowRunStores(
				mapper: $this->createMock(originalClassName: FlowRunMapper::class),
				stateMapper: $this->createMock(originalClassName: FlowStateMapper::class)
			),
			walk: new FlowRunWalkServices(
				engine: $this->createMock(originalClassName: FlowEngine::class),
				registry: $this->createMock(originalClassName: FlowNodeRegistryInterface::class)
			),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			container: $container
		);
	}//end setUp()

	/**
	 * Invoke a private method of the service.
	 *
	 * @param string            $method The method name.
	 * @param array<int, mixed> $args   The arguments.
	 *
	 * @return mixed
	 */
	private function invoke(string $method, array $args): mixed {
		return (new ReflectionMethod(FlowRunService::class, $method))->invokeArgs($this->service, $args);
	}//end invoke()

	/**
	 * A FlowRun in the given state.
	 *
	 * @param string $status The status.
	 * @param string $error  Its error, when any.
	 *
	 * @return FlowRun
	 */
	private static function flowRun(string $status, string $error = ''): FlowRun {
		$run = new FlowRun();
		$run->setUuid('run-77');
		$run->setFlowId('flow-9');
		$run->setStatus($status);
		if ($error !== '') {
			$run->setError($error);
		}

		return $run;
	}//end run()

	/**
	 * Every terminal status writes the run's outcome onto its flow.
	 *
	 * @return void
	 */
	public function testATerminalRunIsWrittenBackToItsFlow(): void {
		$statuses = [
			[FlowRun::STATUS_COMPLETED, ''],
			[FlowRun::STATUS_FAILED, 'node threw'],
			[FlowRun::STATUS_STOPPED, ''],
			[FlowRun::STATUS_DEAD_LETTER, 'retries exhausted'],
		];
		foreach ($statuses as [$status, $error]) {
			$flow = new Flow();
			$this->flowMapper = $this->createMock(originalClassName: FlowMapper::class);
			$this->setUpWith(flow: $flow);
			$this->flowMapper->expects($this->once())->method('findByUuid')->with('flow-9')->willReturn($flow);
			$this->flowMapper->expects($this->once())->method('update')->with($flow)->willReturnArgument(0);

			$this->invoke(method: 'recordLastRun', args: [self::flowRun(status: $status, error: $error), $status]);

			$this->assertSame(expected: 'run-77', actual: $flow->getLastRunUuid(), message: $status);
			$this->assertSame(expected: $status, actual: $flow->getLastRunStatus(), message: $status);
			$expectedMessage = null;
			if ($error !== '') {
				$expectedMessage = $error;
			}

			$this->assertSame(expected: $expectedMessage, actual: $flow->getLastRunMessage(), message: $status);
			$this->assertNotNull(actual: $flow->getLastRunAt(), message: $status);
		}
	}//end testATerminalRunIsWrittenBackToItsFlow()

	/**
	 * A queued, running or suspended run never touches the flow's last run.
	 *
	 * @return void
	 */
	public function testANonTerminalRunDoesNotOverwriteTheLastRun(): void {
		$this->flowMapper->expects($this->never())->method('findByUuid');
		$this->flowMapper->expects($this->never())->method('update');

		foreach ([FlowRun::STATUS_QUEUED, FlowRun::STATUS_RUNNING, FlowRun::STATUS_SUSPENDED] as $status) {
			$this->invoke(method: 'recordLastRun', args: [self::flowRun(status: $status), $status]);
		}
	}//end testANonTerminalRunDoesNotOverwriteTheLastRun()

	/**
	 * A blank flow id is left alone: there is nothing to write back to.
	 *
	 * @return void
	 */
	public function testABlankFlowIdWritesNothing(): void {
		$this->flowMapper->expects($this->never())->method('findByUuid');
		$run = self::flowRun(status: FlowRun::STATUS_COMPLETED);
		$run->setFlowId('  ');

		$this->invoke(method: 'recordLastRun', args: [$run, FlowRun::STATUS_COMPLETED]);
	}//end testABlankFlowIdWritesNothing()

	/**
	 * A write-back failure is swallowed: a terminal run still finishes.
	 *
	 * @return void
	 */
	public function testAFailedWriteBackIsSwallowed(): void {
		$this->flowMapper->method('findByUuid')->willThrowException(new \RuntimeException('gone'));

		$this->invoke(method: 'recordLastRun', args: [self::flowRun(status: FlowRun::STATUS_COMPLETED), FlowRun::STATUS_COMPLETED]);
		$this->addToAssertionCount(count: 1);
	}//end testAFailedWriteBackIsSwallowed()

	/**
	 * A schedule refusal marks the flow in error and disables it, so a dead schedule stops retrying.
	 *
	 * @return void
	 */
	public function testAScheduleRefusalDisablesTheFlow(): void {
		$flow = new Flow();
		$flow->setEnabled(true);
		$this->flowMapper->method('findByUuid')->with('flow-9')->willReturn($flow);
		$this->flowMapper->expects($this->once())->method('update')->with($flow)->willReturnArgument(0);

		$this->invoke(method: 'recordUnattributed', args: [new FlowUnattributed(flowId: 'flow-9', trigger: 'schedule')]);

		$this->assertSame(expected: Flow::STATUS_ERROR, actual: $flow->getStatus());
		$this->assertStringContainsString(needle: 'named no acting identity', haystack: (string)$flow->getStatusMessage());
		$this->assertFalse(condition: (bool)$flow->getEnabled());
	}//end testAScheduleRefusalDisablesTheFlow()

	/**
	 * A positive control: a manual (non-schedule) refusal records the error but leaves the flow enabled.
	 *
	 * @return void
	 */
	public function testAManualRefusalLeavesTheFlowEnabled(): void {
		$flow = new Flow();
		$flow->setEnabled(true);
		$this->flowMapper->method('findByUuid')->willReturn($flow);
		$this->flowMapper->expects($this->once())->method('update')->willReturnArgument(0);

		$this->invoke(method: 'recordUnattributed', args: [new FlowUnattributed(flowId: 'flow-9', trigger: 'manual')]);

		$this->assertSame(expected: Flow::STATUS_ERROR, actual: $flow->getStatus());
		$this->assertTrue(condition: (bool)$flow->getEnabled(), message: 'only a schedule stops itself; a manual run has somebody watching');
	}//end testAManualRefusalLeavesTheFlowEnabled()

	/**
	 * Rebuild the service with a fresh flow mapper mid-test.
	 *
	 * @param Flow $flow Unused placeholder so the fresh mock is wired.
	 *
	 * @return void
	 */
	private function setUpWith(Flow $flow): void {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				if ($id === 'OCA\OpenRegister\Db\FlowMapper') {
					return $this->flowMapper;
				}

				throw new \RuntimeException('not available: ' . $id);
			}
		);
		$this->service = new FlowRunService(
			stores: new FlowRunStores(
				mapper: $this->createMock(originalClassName: FlowRunMapper::class),
				stateMapper: $this->createMock(originalClassName: FlowStateMapper::class)
			),
			walk: new FlowRunWalkServices(
				engine: $this->createMock(originalClassName: FlowEngine::class),
				registry: $this->createMock(originalClassName: FlowNodeRegistryInterface::class)
			),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			container: $container
		);
	}//end setUpWith()
}//end class
