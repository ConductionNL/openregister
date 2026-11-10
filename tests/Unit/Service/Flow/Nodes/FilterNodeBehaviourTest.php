<?php

/**
 * FilterNode: which items a condition keeps, what a kept item carries, and the
 * refusal of a condition that is not a valid expression.
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

use OCA\OpenRegister\Service\Flow\FlowItemOptions;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\Nodes\FilterNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\FilterNode
 * @uses \OCA\OpenRegister\Service\Flow\FlowExpression
 * @uses \OCA\OpenRegister\Service\Flow\FlowExpressionOperators
 * @uses \OCA\OpenRegister\Service\Flow\FlowItemShape
 * @uses \OCA\OpenRegister\Service\Flow\FlowItems
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\LogicNodeBase
 */
class FilterNodeBehaviourTest extends TestCase {

	private FilterNode $node;

	protected function setUp(): void {
		parent::setUp();
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$this->node = new FilterNode($l10n, $this->createMock(IURLGenerator::class));
	}//end setUp()

	public function testOnlyItemsMatchingTheConditionAreKeptWithTheirOrigin(): void {
		$items = [
			FlowItems::item(json: ['status' => 'synced', 'n' => 1], options: new FlowItemOptions(binary: ['f' => ['name' => 'a.pdf']])),
			FlowItems::item(json: ['status' => 'failed', 'n' => 2]),
			FlowItems::item(json: ['status' => 'synced', 'n' => 3]),
		];

		$kept = $this->node->execute($items, ['condition' => ['==' => [['var' => 'json.status'], 'synced']]], []);

		self::assertSame([1, 3], array_map(static fn (array $item): int => $item[FlowItems::JSON]['n'], $kept));
		self::assertSame(['f' => ['name' => 'a.pdf']], $kept[0][FlowItems::BINARY]);
		self::assertSame(0, $kept[0][FlowItems::PAIRED_ITEM]['item']);
		self::assertSame(2, $kept[1][FlowItems::PAIRED_ITEM]['item']);
	}//end testOnlyItemsMatchingTheConditionAreKeptWithTheirOrigin()

	public function testTheConditionSeesTheItemPositionAndTheRunContext(): void {
		$items = [FlowItems::item(json: []), FlowItems::item(json: []), FlowItems::item(json: [])];

		$last = $this->node->execute($items, ['condition' => ['==' => [['var' => 'itemIndex'], ['-' => [['var' => 'itemCount'], 1]]]]], []);
		$byContext = $this->node->execute($items, ['condition' => ['==' => [['var' => 'context.mode'], 'live']]], ['mode' => 'test']);

		self::assertCount(1, $last);
		self::assertSame([], $byContext);
	}//end testTheConditionSeesTheItemPositionAndTheRunContext()

	public function testAnInvalidExpressionIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('That condition is not a valid expression.');

		$this->node->validateConfig(['condition' => ['no-such-operator' => [1, 2]]]);
	}//end testAnInvalidExpressionIsRefused()

	public function testAValidExpressionIsAccepted(): void {
		$this->node->validateConfig(['condition' => ['==' => [['var' => 'json.status'], 'synced']]]);

		self::assertSame(['condition'], $this->node->configKeys());
	}//end testAValidExpressionIsAccepted()
}//end class
