<?php

/**
 * ExplodeNode turns a list on each item into one item per entry, keeping or dropping the record, and refuses a missing path or a non-list.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;
use OCA\OpenRegister\Service\Flow\IFlowNodeTaxonomy;
use OCA\OpenRegister\Service\Flow\Nodes\ExplodeNode;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\ExplodeNode
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\LogicNodeBase
 * @uses   \OCA\OpenRegister\Service\Flow\FlowItems
 * @uses   \OCA\OpenRegister\Service\Flow\FlowItemShape
 */
class ExplodeNodeBehaviourTest extends TestCase {

	/**
	 * An IL10N that formats like the real one, so messages are asserted verbatim.
	 *
	 * @return IL10N
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params=[]) => vsprintf($text, $params));
		return $l10n;
	}

	/**
	 * An IURLGenerator that echoes the app and image it was asked for.
	 *
	 * @return IURLGenerator
	 */
	private function urls(): IURLGenerator {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willReturnCallback(static fn (string $app, string $image) => '/img/'.$app.'/'.$image);
		return $urls;
	}

	/**
	 * Build the node under test.
	 *
	 * @return ExplodeNode
	 */
	private function node(): ExplodeNode {
		return new ExplodeNode($this->l10n(), $this->urls());
	}

	/**
	 * The node describes itself as a script task in the logic palette.
	 *
	 * @return void
	 */
	public function testDescriptor(): void {
		$node = $this->node();
		$this->assertSame('openregister.explode', $node->getId());
		$this->assertSame('Explode', $node->getDisplayName());
		$this->assertSame('Turn a list on the item into one item per entry, so later steps act on each.', $node->getDescription());
		$this->assertSame('/img/openregister/app-dark.svg', $node->getIcon());
		$this->assertSame(['path', 'as', 'keepRecord'], $node->configKeys());
		$this->assertSame(IFlowNodeTaxonomy::KIND_SCRIPT_TASK, $node->getKind());
	}

	/**
	 * A blank path is refused by validation and by execute.
	 *
	 * @return void
	 */
	public function testBlankPathIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('An explode step needs the path of a list.');
		$this->node()->execute(new FlowNodeCall(items: [['json' => ['a' => [1]]]], config: ['path' => '  '], context: []));
	}

	/**
	 * A set path validates.
	 *
	 * @return void
	 */
	public function testSetPathValidates(): void {
		$node = $this->node();
		$node->validateConfig(['path' => 'lines']);
		$this->assertSame([], $node->execute(new FlowNodeCall(items: [], config: ['path' => 'lines'], context: [])));
	}

	/**
	 * Each entry becomes an item under "item", carrying the record and the source index.
	 *
	 * @return void
	 */
	public function testExplodesKeepingTheRecord(): void {
		$out = $this->node()->execute(new FlowNodeCall(
			items: [['json' => ['id' => 'a', 'order' => ['lines' => ['x', 'y']]]], ['json' => ['id' => 'b', 'order' => ['lines' => ['z']]]]],
			config: ['path' => ' order.lines '],
			context: []
		));

		$this->assertSame(
			[
				[['id' => 'a', 'order' => ['lines' => ['x', 'y']], 'item' => 'x'], ['item' => 0]],
				[['id' => 'a', 'order' => ['lines' => ['x', 'y']], 'item' => 'y'], ['item' => 0]],
				[['id' => 'b', 'order' => ['lines' => ['z']], 'item' => 'z'], ['item' => 1]],
			],
			array_map(static fn (array $i) => [$i['json'], $i['pairedItem']], $out)
		);
	}

	/**
	 * With keepRecord false only the entry is carried, under the chosen key.
	 *
	 * @return void
	 */
	public function testExplodesWithoutTheRecord(): void {
		$out = $this->node()->execute(new FlowNodeCall(items: [['json' => ['id' => 'a', 'lines' => [1, 2]]]], config: ['path' => 'lines', 'as' => 'line', 'keepRecord' => false], context: []));
		$this->assertSame([['line' => 1], ['line' => 2]], array_column($out, 'json'));
	}

	/**
	 * A missing path, or a path through a scalar, yields nothing for that item.
	 *
	 * @return void
	 */
	public function testMissingPathYieldsNothing(): void {
		$out = $this->node()->execute(new FlowNodeCall(items: [['json' => ['other' => 1]], ['json' => ['order' => 'scalar']], []], config: ['path' => 'order.lines'], context: []));
		$this->assertSame([], $out);
	}

	/**
	 * A path that ends on a scalar is refused, naming the path.
	 *
	 * @return void
	 */
	public function testNonListIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('The explode path "lines" is not a list.');
		$this->node()->execute(new FlowNodeCall(items: [['json' => ['lines' => 'abc']]], config: ['path' => 'lines'], context: []));
	}
}
