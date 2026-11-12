<?php

/**
 * Unit tests for StepDispatch.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\StepDispatch;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\StepDispatch
 * @uses   \OCA\OpenRegister\Service\Flow\FlowNodeCall
 */
final class StepDispatchTest extends TestCase {

	/**
	 * The type is trimmed text and the config an array.
	 *
	 * @return void
	 */
	public function testTypeAndConfigAreNormalised(): void {
		$dispatch = new StepDispatch(step: ['type' => '  mail ', 'config' => ['to' => 'x']], items: [], context: []);

		self::assertSame('mail', $dispatch->type());
		self::assertSame(['to' => 'x'], $dispatch->config());
		self::assertSame('mail', $dispatch->where());
	}//end testTypeAndConfigAreNormalised()

	/**
	 * A step without a type is a routing edge with an empty config.
	 *
	 * @return void
	 */
	public function testAStepWithoutATypeIsARoutingEdge(): void {
		$dispatch = new StepDispatch(step: ['type' => '   '], items: [], context: []);

		self::assertSame('', $dispatch->type());
		self::assertSame([], $dispatch->config());
		self::assertSame('a routing edge', $dispatch->where());
	}//end testAStepWithoutATypeIsARoutingEdge()

	/**
	 * A new context yields a new dispatch with the same step and items.
	 *
	 * @return void
	 */
	public function testWithContextKeepsStepAndItems(): void {
		$dispatch = new StepDispatch(step: ['type' => 'set'], items: [['a' => 1]], context: ['old' => true]);

		$next = $dispatch->withContext(['new' => true]);

		self::assertNotSame($dispatch, $next);
		self::assertSame(['type' => 'set'], $next->step);
		self::assertSame([['a' => 1]], $next->items);
		self::assertSame(['new' => true], $next->context);
		self::assertSame(['old' => true], $dispatch->context);
	}//end testWithContextKeepsStepAndItems()

	/**
	 * The node call carries the items, the config and the context.
	 *
	 * @return void
	 */
	public function testNodeCallCarriesItemsConfigAndContext(): void {
		$dispatch = new StepDispatch(step: ['type' => 'set', 'config' => ['k' => 'v']], items: [['a' => 1]], context: ['run' => 'r1']);

		$call = $dispatch->nodeCall();

		self::assertSame([['a' => 1]], $call->items);
		self::assertSame(['k' => 'v'], $call->config);
		self::assertSame(['run' => 'r1'], $call->context);
	}//end testNodeCallCarriesItemsConfigAndContext()
}//end class
