<?php

/**
 * Unit test for FlowDeadEndVerdict: a flow with dead ends is marked in error
 * with the refusal's message, and a flow without them loses a stale error.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapperInterface;
use OCA\OpenRegister\Service\Flow\FlowDeadEnd;
use OCA\OpenRegister\Service\Flow\FlowDeadEndVerdict;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowDeadEndVerdict
 * @uses   \OCA\OpenRegister\Service\Flow\FlowDeadEnd
 * @uses   \OCA\OpenRegister\Db\Flow
 */
class FlowDeadEndVerdictTest extends TestCase {

	/**
	 * A flow with the given status.
	 *
	 * @param string $status The status.
	 *
	 * @return Flow
	 */
	private function flow(string $status): Flow {
		$flow = new Flow();
		$flow->setStatus($status);
		$flow->setStatusMessage('was '.$status);
		return $flow;
	}//end flow()

	/**
	 * Dead ends mark the flow in error with the refusal's message, store it, and return the refusal.
	 *
	 * @return void
	 */
	public function testDeadEndsMarkTheFlowInError(): void {
		$flow   = $this->flow(status: Flow::STATUS_OK);
		$mapper = $this->createMock(FlowMapperInterface::class);
		$mapper->expects($this->once())->method('update')->with($flow)->willReturnArgument(0);

		$refusal = (new FlowDeadEndVerdict(mapper: $mapper, flow: $flow))->record(deadEnds: ['mail', 'wacht']);

		$this->assertInstanceOf(FlowDeadEnd::class, $refusal);
		$this->assertSame(['mail', 'wacht'], $refusal->getNodeIds());
		$this->assertSame(Flow::STATUS_ERROR, $flow->getStatus());
		$this->assertSame($refusal->getMessage(), $flow->getStatusMessage());
		$this->assertStringContainsString('nodes "mail", "wacht" have no outgoing edge', (string) $flow->getStatusMessage());
	}//end testDeadEndsMarkTheFlowInError()

	/**
	 * No dead ends clear a stale error: status ok, no message, stored.
	 *
	 * @return void
	 */
	public function testNoDeadEndsClearAStaleError(): void {
		$flow   = $this->flow(status: Flow::STATUS_ERROR);
		$mapper = $this->createMock(FlowMapperInterface::class);
		$mapper->expects($this->once())->method('update')->with($flow)->willReturnArgument(0);

		$this->assertNull((new FlowDeadEndVerdict(mapper: $mapper, flow: $flow))->record(deadEnds: []));
		$this->assertSame(Flow::STATUS_OK, $flow->getStatus());
		$this->assertNull($flow->getStatusMessage());
	}//end testNoDeadEndsClearAStaleError()

	/**
	 * No dead ends on a flow that is not in error leave it and store nothing.
	 *
	 * @return void
	 */
	public function testNoDeadEndsLeaveAHealthyFlowAlone(): void {
		$flow   = $this->flow(status: Flow::STATUS_OK);
		$mapper = $this->createMock(FlowMapperInterface::class);
		$mapper->expects($this->never())->method('update');

		$this->assertNull((new FlowDeadEndVerdict(mapper: $mapper, flow: $flow))->record(deadEnds: []));
		$this->assertSame('was ok', $flow->getStatusMessage());
	}//end testNoDeadEndsLeaveAHealthyFlowAlone()
}//end class
