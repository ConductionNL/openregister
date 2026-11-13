<?php

/**
 * Unit tests for DryRunStep.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\DryRunStep;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\DryRunStep
 * @uses   \OCA\OpenRegister\Service\Flow\FlowNodeCall
 */
final class DryRunStepTest extends TestCase {

	/**
	 * The node call is built from the step's items, config and context.
	 *
	 * @return void
	 */
	public function testNodeCallCarriesTheStepInput(): void {
		$step = new DryRunStep(nodeId: 'n1', config: ['field' => 'x'], items: [['id' => 1], ['id' => 2]], context: ['dryRun' => true]);

		$call = $step->nodeCall();

		self::assertSame([['id' => 1], ['id' => 2]], $call->items);
		self::assertSame(['field' => 'x'], $call->config);
		self::assertSame(['dryRun' => true], $call->context);
	}//end testNodeCallCarriesTheStepInput()
}//end class
