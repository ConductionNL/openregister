<?php

/**
 * FlowRunContext: which run uuid the innermost frame answers with, and the fail-closed null for an unattributable hop.
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

use OCA\OpenRegister\Service\Flow\FlowRunContext;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Service\Flow\FlowRunFrame;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowRunContext
 */
class FlowRunContextRunUuidBehaviourTest extends TestCase {

	public function testOutsideARunThereIsNoRunUuid(): void {
		self::assertNull((new FlowRunContext())->currentRunUuid());
	}//end testOutsideARunThereIsNoRunUuid()

	public function testTheInnermostFrameAnswersTrimmed(): void {
		$context = new FlowRunContext();
		$context->push(frame: new FlowRunFrame(runUuid: 'outer', nodeId: 'a', sequence: 1));
		$context->push(frame: new FlowRunFrame(runUuid: ' inner ', nodeId: 'b', sequence: 2));

		self::assertSame('inner', $context->currentRunUuid());

		$context->pop();
		self::assertSame('outer', $context->currentRunUuid());
	}//end testTheInnermostFrameAnswersTrimmed()

	public function testAnUnattributableHopDoesNotFallThroughToTheEnclosingRun(): void {
		$context = new FlowRunContext();
		$context->push(frame: new FlowRunFrame(runUuid: 'outer', nodeId: 'a', sequence: 1));
		$context->push(frame: new FlowRunFrame(runUuid: '  ', nodeId: 'b', sequence: 2));

		self::assertNull($context->currentRunUuid());
		self::assertSame(2, $context->depth());
	}//end testAnUnattributableHopDoesNotFallThroughToTheEnclosingRun()
}//end class
