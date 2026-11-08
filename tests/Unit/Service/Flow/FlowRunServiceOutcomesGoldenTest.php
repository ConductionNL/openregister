<?php

/**
 * Characterisation test for FlowRunService's execute/signal/executeNode outcomes.
 *
 * Pins what a run looks like after the engine stops it at its runtime
 * ceiling, after the engine throws, and after a suspension that carries a
 * correlation key — the branches the existing behaviour tests do not reach —
 * so those methods can be split into smaller units without moving an outcome.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\FlowStateMapper;
use OCA\OpenRegister\Exception\FlowRunExpired;
use OCA\OpenRegister\Service\Flow\FlowEngine;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistryInterface;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden outcomes for the run lifecycle around the engine.
 */
class FlowRunServiceOutcomesGoldenTest extends TestCase {
	use PublishedVersionDouble;

	/**
	 * The engine double.
	 *
	 * @var FlowEngine&MockObject
	 */
	private FlowEngine&MockObject $engine;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The service under test.
	 *
	 * @var FlowRunService
	 */
	private FlowRunService $service;

	/**
	 * Wire the service around a mocked engine.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$mapper = $this->createMock(FlowRunMapper::class);
		$mapper->method('insert')->willReturnArgument(0);
		$mapper->method('update')->willReturnArgument(0);

		$versions  = $this->publishedVersionMapper();
		$pin       = $this->pinReturning();
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($versions, $pin): object {
				if ($id === \OCA\OpenRegister\Db\FlowVersionMapper::class) {
					return $versions;
				}

				if ($id === \OCA\OpenRegister\Service\Flow\FlowDefinitionPin::class) {
					return $pin;
				}

				throw new RuntimeException('not available');
			}
		);

		$this->engine  = $this->createMock(FlowEngine::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
		$this->service = new FlowRunService(
			$mapper,
			$this->createMock(FlowStateMapper::class),
			$this->engine,
			$this->createMock(FlowNodeRegistryInterface::class),
			$this->logger,
			$container
		);

	}//end setUp()

	/**
	 * A one-node flow.
	 *
	 * @return array<string, mixed>
	 */
	private function flow(): array {
		return ['id' => 'f1', 'nodes' => [['id' => 'hop', 'type' => 'test.wait']], 'edges' => []];

	}//end flow()

	/**
	 * A run stopped at its runtime ceiling fails, logged as a warning.
	 *
	 * @return void
	 */
	public function testARunStoppedAtItsCeilingFailsWithAWarning(): void {
		$this->engine->method('run')->willThrowException(new FlowRunExpired('ceiling reached'));
		$this->logger->expects($this->once())->method('warning')->with(
			$this->stringContains('runtime ceiling'),
			$this->callback(static fn (array $context): bool => $context['error'] === 'ceiling reached' && $context['flow'] === 'f1')
		);
		$this->logger->expects($this->never())->method('error');

		$run = $this->service->queue('f1', user: 'alice');
		$run = $this->service->execute($run, $this->flow(), new \stdClass());

		$this->assertSame(FlowRun::STATUS_FAILED, $run->getStatus());
		$this->assertTrue($run->isTerminal());

	}//end testARunStoppedAtItsCeilingFailsWithAWarning()

	/**
	 * Any other engine failure fails the run, logged as an error.
	 *
	 * @return void
	 */
	public function testAnEngineFailureFailsTheRunWithAnError(): void {
		$this->engine->method('run')->willThrowException(new RuntimeException('boom'));
		$this->logger->expects($this->once())->method('error')->with(
			$this->stringContains('outside the walk'),
			$this->callback(static fn (array $context): bool => $context['error'] === 'boom')
		);

		$run = $this->service->queue('f1', user: 'alice');
		$run = $this->service->execute($run, $this->flow(), new \stdClass());

		$this->assertSame(FlowRun::STATUS_FAILED, $run->getStatus());

	}//end testAnEngineFailureFailsTheRunWithAnError()

	/**
	 * The engine receives the seed items and start node on a fresh run.
	 *
	 * @return void
	 */
	public function testAFreshRunHandsTheSeedAndStartToTheEngine(): void {
		$seen = [];
		$this->engine->method('run')->willReturnCallback(
			static function (...$args) use (&$seen): array {
				$seen = ['items' => $args[5], 'startAt' => $args[6], 'streams' => $args[7]];
				return ['status' => FlowRun::STATUS_COMPLETED, 'log' => [], 'context' => [], 'items' => [], 'marking' => []];
			}
		);

		$seed = [['json' => ['a' => 1], 'binary' => [], 'pairedItem' => null]];
		$run  = $this->service->queue('f1', user: 'alice');
		$run  = $this->service->execute($run, $this->flow(), new \stdClass(), $seed, 'hop');

		$this->assertSame(['items' => $seed, 'startAt' => 'hop', 'streams' => null], $seen);
		$this->assertSame(FlowRun::STATUS_COMPLETED, $run->getStatus());
		$this->assertNull($run->getCorrelationKey());

	}//end testAFreshRunHandsTheSeedAndStartToTheEngine()

	/**
	 * A suspended run carries the first non-blank correlation key of its resume slots.
	 *
	 * @return void
	 */
	public function testASuspendedRunCarriesTheFirstCorrelationKey(): void {
		$slots = [
			'a' => 'not-a-slot',
			'b' => ['correlationKey' => '   '],
			'c' => ['other' => 1],
			'd' => ['correlationKey' => ' vote:42 '],
			'e' => ['correlationKey' => 'later'],
		];
		$this->engine->method('run')->willReturn(
			[
				'status' => FlowRun::STATUS_SUSPENDED,
				'log' => [],
				'context' => [FlowResumeState::CONTEXT_KEY => new FlowResumeState($slots)],
				'items' => [],
				'marking' => [],
			]
		);

		$run = $this->service->queue('f1', user: 'alice');
		$run = $this->service->execute($run, $this->flow(), new \stdClass());

		$this->assertSame(FlowRun::STATUS_SUSPENDED, $run->getStatus());
		$this->assertSame('vote:42', $run->getCorrelationKey());

	}//end testASuspendedRunCarriesTheFirstCorrelationKey()

	/**
	 * A suspension without usable slots, or a finished run with them, has no key.
	 *
	 * @return void
	 */
	public function testNoCorrelationKeyWithoutASuspensionOrSlots(): void {
		$results = [
			['status' => FlowRun::STATUS_SUSPENDED, 'log' => [], 'context' => [FlowResumeState::CONTEXT_KEY => 'scalar'], 'items' => [], 'marking' => []],
			['status' => FlowRun::STATUS_SUSPENDED, 'log' => [], 'context' => [], 'items' => [], 'marking' => []],
			['status' => FlowRun::STATUS_SUSPENDED, 'log' => [], 'context' => [FlowResumeState::CONTEXT_KEY => new FlowResumeState(['n' => ['correlationKey' => '']])], 'items' => [], 'marking' => []],
			['status' => FlowRun::STATUS_COMPLETED, 'log' => [], 'context' => [FlowResumeState::CONTEXT_KEY => new FlowResumeState(['n' => ['correlationKey' => 'k']])], 'items' => [], 'marking' => []],
		];
		$this->engine->method('run')->willReturnOnConsecutiveCalls(...$results);

		foreach ($results as $index => $result) {
			$run = $this->service->queue('f1', user: 'alice');
			$run = $this->service->execute($run, $this->flow(), new \stdClass());
			$this->assertSame($result['status'], $run->getStatus(), 'result '.$index);
			$this->assertNull($run->getCorrelationKey(), 'result '.$index);
		}

	}//end testNoCorrelationKeyWithoutASuspensionOrSlots()

	/**
	 * A signal on a run that is not suspended is refused with null.
	 *
	 * @return void
	 */
	public function testASignalOnARunThatIsNotSuspendedIsRefused(): void {
		$run = $this->service->queue('f1', user: 'alice');

		$this->assertNull($this->service->signal($run, ['decision' => 'x']));
		$this->assertSame(FlowRun::STATUS_QUEUED, $run->getStatus());

	}//end testASignalOnARunThatIsNotSuspendedIsRefused()
}//end class
