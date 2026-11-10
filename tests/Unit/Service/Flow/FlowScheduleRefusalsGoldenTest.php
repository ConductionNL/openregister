<?php

/**
 * Characterisation test for how FlowScheduleService handles a flow that refuses to fire.
 *
 * Pins the delegation and dead-end refusals (logged, skipped, no fire
 * recorded, later flows still fire) and that any other failure still
 * propagates, so the per-flow firing can move into smaller units without
 * changing which flows fire.
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

use DateTimeImmutable;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Service\Delegation\DelegationRefused;
use OCA\OpenRegister\Service\Delegation\DelegationVerdict;
use OCA\OpenRegister\Service\Flow\FlowDeadEnd;
use OCA\OpenRegister\Service\Flow\FlowLocator;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowScheduleService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden outcomes for refused scheduled flows.
 */
class FlowScheduleRefusalsGoldenTest extends TestCase {

	/**
	 * The stored last-fire markers.
	 *
	 * @var array<string, string>
	 */
	private array $store = [];

	/**
	 * Logged warnings: [message, context].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $warnings = [];

	/**
	 * The service, over flows `a`, `b`, `c` due every five minutes, where queue() runs $queue.
	 *
	 * @param callable $queue What queue() does.
	 *
	 * @return FlowScheduleService The service.
	 */
	private function service(callable $queue): FlowScheduleService {
		$registry = $this->createMock(FlowLocator::class);
		$registry->method('scheduledFlows')->willReturn(
			array_map(
				static fn (string $id): array => ['id' => $id, 'enabled' => true, 'trigger' => 'schedule', 'cron' => '*/5 * * * *', 'since' => '2026-07-25T09:00:00+00:00'],
				['a', 'b', 'c']
			)
		);
		/** @var FlowRunService&MockObject $runs */
		$runs = $this->createMock(FlowRunService::class);
		$runs->method('hasActiveRun')->willReturn(false);
		$runs->method('queueRun')->willReturnCallback($queue);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->store[$key] ?? $default)
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->store[$key] = $value;
				return true;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);

		return new FlowScheduleService($registry, $runs, $config, $logger);

	}//end service()

	/**
	 * A delegation refusal skips that flow, logs it with the refusal's parties, and records no fire.
	 *
	 * @return void
	 */
	public function testARefusedDelegationIsSkippedAndReported(): void {
		$service = $this->service(
			static function (string $flowId): FlowRun {
				if ($flowId === 'b') {
					throw new DelegationRefused(
						principal: 'owner',
						actingAs: 'agent',
						verdict: DelegationVerdict::refused(reason: 'revoked', detail: 'The grant was revoked.')
					);
				}

				return new FlowRun();
			}
		);

		$this->assertSame(['a', 'c'], $service->fireDueFlows(new DateTimeImmutable('2026-07-25 10:00:00')));
		$this->assertCount(1, $this->warnings);
		$this->assertStringStartsWith('[FlowSchedule] Skipping due flow — its delegation is no longer live: ', $this->warnings[0][0]);
		$this->assertSame('b', $this->warnings[0][1]['flow']);
		$this->assertSame('owner', $this->warnings[0][1]['principal']);
		$this->assertSame('agent', $this->warnings[0][1]['actingAs']);
		$this->assertSame('revoked', $this->warnings[0][1]['reason']);
		$this->assertCount(2, $this->store);

	}//end testARefusedDelegationIsSkippedAndReported()

	/**
	 * A dead-end flow is skipped and logged with its nodes; later flows still fire.
	 *
	 * @return void
	 */
	public function testADeadEndFlowIsSkippedAndReported(): void {
		$service = $this->service(
			static function (string $flowId): FlowRun {
				if ($flowId === 'a') {
					throw new FlowDeadEnd(nodeIds: ['n1', 'n2']);
				}

				return new FlowRun();
			}
		);

		$this->assertSame(['b', 'c'], $service->fireDueFlows(new DateTimeImmutable('2026-07-25 10:00:00')));
		$this->assertCount(1, $this->warnings);
		$this->assertStringStartsWith('[FlowSchedule] Skipping due flow — it is not runnable: ', $this->warnings[0][0]);
		$this->assertSame(['n1', 'n2'], $this->warnings[0][1]['nodes']);
		$this->assertSame('a', $this->warnings[0][1]['flow']);
		$this->assertCount(2, $this->store);

	}//end testADeadEndFlowIsSkippedAndReported()

	/**
	 * Any other failure is not swallowed: it propagates after the earlier flows fired.
	 *
	 * @return void
	 */
	public function testAnUnexpectedFailurePropagates(): void {
		$service = $this->service(
			static function (string $flowId): FlowRun {
				if ($flowId === 'b') {
					throw new RuntimeException('database gone');
				}

				return new FlowRun();
			}
		);

		try {
			$service->fireDueFlows(new DateTimeImmutable('2026-07-25 10:00:00'));
			$this->fail('an unexpected failure must propagate');
		} catch (RuntimeException $e) {
			$this->assertSame('database gone', $e->getMessage());
		}

		$this->assertCount(1, $this->store);
		$this->assertSame([], $this->warnings);

	}//end testAnUnexpectedFailurePropagates()
}//end class
