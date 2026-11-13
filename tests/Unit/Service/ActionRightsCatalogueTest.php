<?php

/**
 * Unit test for ActionRightsCatalogue: the action rights, described, and their groups.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Service\ActionRightsCatalogue;
use OCA\OpenRegister\Service\Flow\FlowNodeRightsGuardInterface;
use OCA\OpenRegister\Service\OpenRegisterActionAuthService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * What the catalogue lists, and what it refuses to write.
 *
 * @covers \OCA\OpenRegister\Service\ActionRightsCatalogue
 */
final class ActionRightsCatalogueTest extends TestCase {

	/**
	 * The action matrix.
	 *
	 * @var OpenRegisterActionAuthService&MockObject
	 */
	private OpenRegisterActionAuthService&MockObject $matrix;

	/**
	 * The node rights.
	 *
	 * @var FlowNodeRightsGuardInterface&MockObject
	 */
	private FlowNodeRightsGuardInterface&MockObject $nodeRights;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->matrix = $this->createMock(OpenRegisterActionAuthService::class);
		$this->nodeRights = $this->createMock(FlowNodeRightsGuardInterface::class);
	}//end setUp()

	/**
	 * The catalogue under test.
	 *
	 * @return ActionRightsCatalogue
	 */
	private function catalogue(): ActionRightsCatalogue {
		return new ActionRightsCatalogue(matrix: $this->matrix, nodeRights: $this->nodeRights);
	}//end catalogue()

	/**
	 * Every right of the matrix and every node right is listed, with its description.
	 *
	 * A node right the matrix does not hold yet is still listed, so an
	 * administrator can see it before the repair step has run.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-the-action-rights-are-administered-and-published
	 */
	public function testItListsTheMatrixAndTheNodeRights(): void {
		$this->matrix->method('getMatrix')->willReturn(['flow.create' => ['@authenticated'], 'object.correct' => ['admin']]);
		$this->nodeRights->method('nodeRights')->willReturn(
			['flow.node.send-email' => ['description' => 'Send e-mail.', 'nodeTypes' => ['openregister.send-email']]]
		);

		$actions = $this->catalogue()->actions();

		$this->assertSame(
			[
				['action' => 'flow.create', 'app' => 'openregister', 'description' => 'Create a flow, or import one from BPMN.', 'nodeTypes' => []],
				[
					'action' => 'flow.node.send-email',
					'app' => 'openregister',
					'description' => 'Send e-mail.',
					'nodeTypes' => ['openregister.send-email'],
				],
				['action' => 'object.correct', 'app' => 'openregister', 'description' => 'Correct a recorded value of an object.', 'nodeTypes' => []],
			],
			$actions
		);
	}//end testItListsTheMatrixAndTheNodeRights()

	/**
	 * Each listed right carries the groups the matrix grants it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-the-action-rights-are-administered-and-published
	 */
	public function testEachRightCarriesItsGroups(): void {
		$this->matrix->method('getMatrix')->willReturn(['flow.create' => ['flow-authors']]);
		$this->nodeRights->method('nodeRights')->willReturn(
			['flow.node.send-email' => ['description' => 'Send e-mail.', 'nodeTypes' => ['openregister.send-email']]]
		);
		$this->matrix->method('getAllowedGroups')->willReturnCallback(
			static fn (string $action): array => ($action === 'flow.create' ? ['flow-authors'] : ['admin'])
		);

		$groups = array_column($this->catalogue()->actionsWithGroups(), 'groups', 'action');

		$this->assertSame(['flow.create' => ['flow-authors'], 'flow.node.send-email' => ['admin']], $groups);
	}//end testEachRightCarriesItsGroups()

	/**
	 * A write changes only the named actions and leaves the rest of the matrix alone.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-the-action-rights-are-administered-and-published
	 */
	public function testAWriteKeepsTheActionsItWasNotGiven(): void {
		$this->matrix->method('getMatrix')->willReturn(['flow.create' => ['@authenticated'], 'object.correct' => ['admin']]);
		$this->nodeRights->method('nodeRights')->willReturn(
			['flow.node.send-email' => ['description' => 'Send e-mail.', 'nodeTypes' => ['openregister.send-email']]]
		);
		$this->matrix->expects($this->once())->method('setMatrix')->with(
			[
				'flow.create' => ['@authenticated'],
				'object.correct' => ['admin'],
				'flow.node.send-email' => ['builders'],
			]
		);

		$this->catalogue()->setGroups(groups: ['flow.node.send-email' => ['builders']]);
	}//end testAWriteKeepsTheActionsItWasNotGiven()

	/**
	 * An unknown action is refused by name, and nothing is written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-the-action-rights-are-administered-and-published
	 */
	public function testAnUnknownActionIsRefusedByName(): void {
		$this->matrix->method('getMatrix')->willReturn(['flow.create' => ['@authenticated']]);
		$this->nodeRights->method('nodeRights')->willReturn([]);
		$this->matrix->expects($this->never())->method('setMatrix');

		try {
			$this->catalogue()->setGroups(groups: ['flow.create' => ['a'], 'flow.node.invented' => ['b']]);
			$this->fail('an unknown action must be refused');
		} catch (InvalidArgumentException $e) {
			$this->assertStringContainsString('flow.node.invented', $e->getMessage());
			$this->assertStringNotContainsString('flow.create', $e->getMessage());
		}
	}//end testAnUnknownActionIsRefusedByName()

	/**
	 * A group list that is not a list of strings is refused, and nothing is written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-the-action-rights-are-administered-and-published
	 */
	public function testAMalformedGroupListIsRefused(): void {
		$this->matrix->method('getMatrix')->willReturn(['flow.create' => ['@authenticated']]);
		$this->nodeRights->method('nodeRights')->willReturn([]);
		$this->matrix->expects($this->never())->method('setMatrix');

		$this->expectException(InvalidArgumentException::class);
		$this->catalogue()->setGroups(groups: ['flow.create' => [['nested']]]);
	}//end testAMalformedGroupListIsRefused()
}//end class
