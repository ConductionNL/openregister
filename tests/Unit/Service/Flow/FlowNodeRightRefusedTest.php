<?php

/**
 * A refused step names its type, its label and the right, in the message and in the response body.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowNodeRightRefused;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowNodeRightRefused
 */
final class FlowNodeRightRefusedTest extends TestCase {

	/**
	 * The message names the step, its type and the missing right.
	 *
	 * @return void
	 */
	public function testTheMessageNamesStepTypeAndRight(): void {
		$refusal = new FlowNodeRightRefused(nodeType: 'http', step: 'Call the archive', right: 'flow.node.http');

		self::assertInstanceOf(RuntimeException::class, $refusal);
		self::assertSame(
			'The step "Call the archive" (http) needs the "flow.node.http" right, which you do not have. Ask an administrator to grant it.',
			$refusal->getMessage()
		);
		self::assertSame('http', $refusal->nodeType);
		self::assertSame('Call the archive', $refusal->step);
		self::assertSame('flow.node.http', $refusal->right);
	}//end testTheMessageNamesStepTypeAndRight()

	/**
	 * The body carries the message and each part separately, in a fixed key order.
	 *
	 * @return void
	 */
	public function testTheBodyCarriesTheMessageAndEachPart(): void {
		$refusal = new FlowNodeRightRefused(nodeType: 'script', step: '3', right: 'flow.node.script');

		self::assertSame(
			[
				'error'    => 'The step "3" (script) needs the "flow.node.script" right, which you do not have. Ask an administrator to grant it.',
				'nodeType' => 'script',
				'step'     => '3',
				'right'    => 'flow.node.script',
			],
			$refusal->body()
		);
		self::assertSame(['error', 'nodeType', 'step', 'right'], array_keys($refusal->body()));
	}//end testTheBodyCarriesTheMessageAndEachPart()
}//end class
