<?php

/**
 * IterateNode: the first pass seeds the source with the items that entered
 * the loop, later passes with a bare iteration item, and the limit behaviour
 * must be one of the two it knows.
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
use OCA\OpenRegister\Service\Flow\FlowStepDispatcher;
use OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation;
use OCA\OpenRegister\Service\Flow\Nodes\IterateNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use UnexpectedValueException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\IterateNode
 * @uses \OCA\OpenRegister\Service\Flow\FlowItemShape
 * @uses \OCA\OpenRegister\Service\Flow\FlowItems
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\LogicNodeBase
 */
class IterateNodeSeedBehaviourTest extends TestCase {

	private IterateNode $node;

	private FlowStepDispatcher&MockObject $dispatcher;

	protected function setUp(): void {
		parent::setUp();
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$this->dispatcher = $this->createMock(FlowStepDispatcher::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(FlowStepDispatcher::class)->willReturn($this->dispatcher);
		$this->node = new IterateNode(presentation: new FlowNodePresentation(l10n: $l10n, urls: $this->createMock(IURLGenerator::class)), container: $container);
	}//end setUp()

	public function testTheFirstPassSeedsTheSourceWithTheIncomingItemsAndLaterPassesWithABareItem(): void {
		$seeds = [];
		$this->dispatcher->method('dispatch')->willReturnCallback(
			static function (array $step, array $items) use (&$seeds): array {
				if ($step['type'] !== 'src') {
					return $items;
				}

				$seeds[] = $items;
				if (count($seeds) > 2) {
					return [];
				}

				return [FlowItems::item(json: ['page' => count($seeds)])];
			}
		);

		$out = $this->node->execute(
			[FlowItems::item(json: ['cursor' => 'abc'], binary: ['f' => ['name' => 'x']]), FlowItems::item(json: ['cursor' => 'def'])],
			['source' => ['type' => 'src'], 'body' => [['type' => 'body']]],
			[]
		);

		self::assertCount(2, $seeds[0], 'every incoming item seeds the first pass');
		self::assertSame(['cursor' => 'abc', 'iteration' => ['index' => 0, 'first' => true]], $seeds[0][0][FlowItems::JSON]);
		self::assertSame(['f' => ['name' => 'x']], $seeds[0][0][FlowItems::BINARY]);
		self::assertSame('def', $seeds[0][1][FlowItems::JSON]['cursor']);
		self::assertCount(1, $seeds[1]);
		self::assertSame(['iteration' => ['index' => 1, 'first' => false]], $seeds[1][0][FlowItems::JSON]);
		self::assertSame([1, 2], array_map(static fn (array $item): int => $item[FlowItems::JSON]['page'], $out));
	}//end testTheFirstPassSeedsTheSourceWithTheIncomingItemsAndLaterPassesWithABareItem()

	public function testAnUnknownLimitBehaviourIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('A repeat step must either fail or stop when it hits its limit.');

		$this->node->validateConfig(['source' => ['type' => 'src'], 'body' => [['type' => 'b']], 'onLimit' => 'retry']);
	}//end testAnUnknownLimitBehaviourIsRefused()
}//end class
