<?php

/**
 * RouterNode and SwitchNode: a rule without an output is skipped, rules that are not a list leave only the fallback, and the legacy switch passes items through untouched.
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

use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\Nodes\RouterNode;
use OCA\OpenRegister\Service\Flow\Nodes\SwitchNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\RouterNode
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\SwitchNode
 * @uses \OCA\OpenRegister\Service\Flow\FlowExpression
 * @uses \OCA\OpenRegister\Service\Flow\FlowExpressionOperators
 * @uses \OCA\OpenRegister\Service\Flow\FlowItemShape
 * @uses \OCA\OpenRegister\Service\Flow\FlowItems
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\LogicNodeBase
 */
class RouterNodeRulesBehaviourTest extends TestCase {

	private IL10N $l10n;

	protected function setUp(): void {
		parent::setUp();
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnArgument(0);
	}//end setUp()

	public function testARuleWithoutAnOutputIsSkippedEvenWhenItMatches(): void {
		$router = new RouterNode($this->l10n, $this->createMock(IURLGenerator::class));

		$out = $router->execute(new FlowNodeCall(
			items: [FlowItems::item(json: ['status' => 'open'])],
			config: ['rules' => [['output' => ' ', 'condition' => true], ['output' => 'open', 'condition' => ['==' => [['var' => 'json.status'], 'open']]]]],
			context: []
		));

		self::assertSame('open', $out[0][FlowItems::OUTPUT]);
	}//end testARuleWithoutAnOutputIsSkippedEvenWhenItMatches()

	public function testRulesThatAreNotAListLeaveOnlyTheFallback(): void {
		$router = new RouterNode($this->l10n, $this->createMock(IURLGenerator::class));

		$routed = $router->execute(new FlowNodeCall(items: [FlowItems::item(json: ['a' => 1])], config: ['rules' => 'status == open', 'default' => 'other'], context: []));
		$dropped = $router->execute(new FlowNodeCall(items: [FlowItems::item(json: ['a' => 1])], config: ['rules' => 'status == open'], context: []));

		self::assertSame('other', $routed[0][FlowItems::OUTPUT]);
		self::assertSame([], $dropped);
	}//end testRulesThatAreNotAListLeaveOnlyTheFallback()

	public function testTheLegacySwitchPassesItsItemsThroughUntouched(): void {
		$switch = new SwitchNode($this->l10n, $this->createMock(IURLGenerator::class));
		$items = [FlowItems::item(json: ['a' => 1]), FlowItems::item(json: ['a' => 2])];

		self::assertSame($items, $switch->execute(new FlowNodeCall(items: $items, config: ['rules' => [['output' => 'x', 'condition' => false]]], context: [])));
	}//end testTheLegacySwitchPassesItsItemsThroughUntouched()
}//end class
