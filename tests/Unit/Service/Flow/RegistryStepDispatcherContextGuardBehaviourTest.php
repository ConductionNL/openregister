<?php

/**
 * RegistryStepDispatcher: a dispatcher built without a guard checkpoints against the run guard carried in the context.
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

use OCA\OpenRegister\Db\FlowRunMapperInterface;
use OCA\OpenRegister\Exception\FlowRunExpired;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistryInterface;
use OCA\OpenRegister\Service\Flow\FlowRunGuard;
use OCA\OpenRegister\Service\Flow\FlowRunGuardClock;
use OCA\OpenRegister\Service\Flow\RegistryStepDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Flow\RegistryStepDispatcher
 * @uses \OCA\OpenRegister\Service\Flow\FlowItemShape
 * @uses \OCA\OpenRegister\Service\Flow\FlowItems
 * @uses \OCA\OpenRegister\Service\Flow\FlowRunGuard
 * @uses \OCA\OpenRegister\Service\Flow\FlowRunGuardClock
 */
class RegistryStepDispatcherContextGuardBehaviourTest extends TestCase {

	private function guard(float $startedAt): FlowRunGuard {
		return new FlowRunGuard(
			mapper: $this->createMock(FlowRunMapperInterface::class),
			logger: new NullLogger(),
			clock: new FlowRunGuardClock(runUuid: 'run-1', startedAt: $startedAt, budget: 60)
		);
	}//end guard()

	public function testAnExpiredRunGuardFromTheContextStopsARoutingEdge(): void {
		$dispatcher = new RegistryStepDispatcher(registry: $this->createMock(FlowNodeRegistryInterface::class));

		$this->expectException(FlowRunExpired::class);
		$this->expectExceptionMessage('The run exceeded its maximum runtime of 60 seconds');

		$dispatcher->dispatch(step: [], items: [FlowItems::item(json: [])], context: [FlowRunGuard::CONTEXT_KEY => $this->guard(startedAt: microtime(true) - 3600)]);
	}//end testAnExpiredRunGuardFromTheContextStopsARoutingEdge()

	public function testSomethingElseUnderTheGuardKeyIsIgnored(): void {
		$dispatcher = new RegistryStepDispatcher(registry: $this->createMock(FlowNodeRegistryInterface::class));
		$items = [FlowItems::item(json: ['a' => 1])];

		self::assertSame($items, $dispatcher->dispatch(step: ['type' => ' '], items: $items, context: [FlowRunGuard::CONTEXT_KEY => 'not-a-guard']));
	}//end testSomethingElseUnderTheGuardKeyIsIgnored()
}//end class
