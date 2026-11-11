<?php

/**
 * SubFlowNode: a waited child that failed carries its error into the refusal.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Service\Flow\FlowLocator;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\Nodes\SubFlowNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\SubFlowNode
 * @uses \OCA\OpenRegister\Db\FlowRun
 * @uses \OCA\OpenRegister\Service\Flow\FlowToken
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\LogicNodeBase
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\SubFlowTarget
 */
class SubFlowNodeFailedChildBehaviourTest extends TestCase {

	private function node(FlowRun $finished): SubFlowNode {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$resolvers = $this->createMock(FlowLocator::class);
		$resolvers->method('resolveFlow')->willReturn(['id' => 'child']);
		$queued = new FlowRun();
		$queued->setStatus(FlowRun::STATUS_QUEUED);
		$runs = $this->createMock(FlowRunService::class);
		$runs->method('queueRun')->willReturn($queued);
		$runs->method('executePass')->willReturn($finished);

		return new SubFlowNode(new \OCA\OpenRegister\Service\Flow\FlowExecution($resolvers, $runs, new \Psr\Log\NullLogger()), new \OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation($l10n, $this->createMock(IURLGenerator::class)));
	}//end node()

	public function testTheChildsErrorIsNamed(): void {
		$failed = new FlowRun();
		$failed->setStatus(FlowRun::STATUS_FAILED);
		$failed->setError('register "cases" not found');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Sub-flow "child" did not complete (status: failed: register "cases" not found).');

		$this->node($failed)->execute(new FlowNodeCall(items: [], config: ['flowId' => 'child'], context: []));
	}//end testTheChildsErrorIsNamed()

	public function testWithoutAnErrorOnlyTheStatusIsNamed(): void {
		$failed = new FlowRun();
		$failed->setStatus(FlowRun::STATUS_FAILED);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Sub-flow "child" did not complete (status: failed).');

		$this->node($failed)->execute(new FlowNodeCall(items: [], config: ['flowId' => 'child'], context: []));
	}//end testWithoutAnErrorOnlyTheStatusIsNamed()
}//end class
