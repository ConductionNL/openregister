<?php

/**
 * LoopNode splits the items into fixed-size batches and refuses a batch size under one.
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
use OCP\WorkflowEngine\IManager;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;
use OCA\OpenRegister\Service\Flow\IFlowNodeTaxonomy;
use OCA\OpenRegister\Service\Flow\Nodes\LoopNode;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\LoopNode
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\ObjectNodeBase
 * @uses   \OCA\OpenRegister\Service\Flow\FlowItems
 * @uses   \OCA\OpenRegister\Service\Flow\FlowItemShape
 */
class LoopNodeBehaviourTest extends TestCase {

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
	 * @return LoopNode
	 */
	private function node(): LoopNode {
		return new LoopNode($this->l10n(), $this->urls());
	}

	/**
	 * The node describes itself as a batch step in the objects palette.
	 *
	 * @return void
	 */
	public function testDescriptor(): void {
		$node = $this->node();
		$this->assertSame('openregister.batch', $node->getId());
		$this->assertSame('Batch items', $node->getDisplayName());
		$this->assertSame('Split the items into fixed-size batches for a step that needs them a slice at a time.', $node->getDescription());
		$this->assertSame('/img/core/actions/play-next.svg', $node->getIcon());
		$this->assertSame(['batchSize'], $node->configKeys());
		$this->assertSame(IFlowNodeTaxonomy::KIND_SERVICE_TASK, $node->getKind());
		$this->assertSame(IFlowNodeTaxonomy::CATEGORY_OBJECTS, $node->getCategory());
		$this->assertTrue($node->isAvailableForScope(IManager::SCOPE_USER));
		$this->assertFalse($node->isAvailableForScope(99));
	}

	/**
	 * A batch size under one, or none, is refused; one passes.
	 *
	 * @return void
	 */
	public function testBatchSizeValidation(): void {
		$node = $this->node();
		foreach ([[], ['batchSize' => 0], ['batchSize' => -3]] as $config) {
			try {
				$node->validateConfig($config);
				$this->fail('accepted '.json_encode($config));
			} catch (UnexpectedValueException $e) {
				$this->assertSame('A loop needs a batch size of one or more.', $e->getMessage());
			}
		}

		$node->validateConfig(['batchSize' => 1]);
		$this->assertSame([], $node->execute(new FlowNodeCall(items: [], config: ['batchSize' => 1], context: [])));
	}

	/**
	 * Five items in batches of two give three batches carrying the item json and first index.
	 *
	 * @return void
	 */
	public function testBatches(): void {
		$items = array_map(static fn (int $i) => ['json' => ['n' => $i]], range(0, 4));
		$out   = $this->node()->execute(new FlowNodeCall(items: $items, config: ['batchSize' => 2], context: []));

		$this->assertSame(
			[
				['batchIndex' => 0, 'batchCount' => 3, 'items' => [['n' => 0], ['n' => 1]]],
				['batchIndex' => 1, 'batchCount' => 3, 'items' => [['n' => 2], ['n' => 3]]],
				['batchIndex' => 2, 'batchCount' => 3, 'items' => [['n' => 4]]],
			],
			array_column($out, 'json')
		);
		$this->assertSame([['item' => 0], ['item' => 2], ['item' => 4]], array_column($out, 'pairedItem'));
		$this->assertSame([[], [], []], array_column($out, 'binary'));
	}

	/**
	 * Without a usable batch size every item is its own batch, and an item without json carries an empty one.
	 *
	 * @return void
	 */
	public function testMissingSizeFallsBackToOne(): void {
		$out = $this->node()->execute(new FlowNodeCall(items: [['json' => ['n' => 1]], ['binary' => []]], config: ['batchSize' => 0], context: []));
		$this->assertSame([[['n' => 1]], [[]]], array_column(array_column($out, 'json'), 'items'));
	}
}
