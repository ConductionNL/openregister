<?php

/**
 * EndNode stops a flow that reaches it, as a plain stop or as an error with a message, and passes when no item arrives.
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
use OCA\OpenRegister\Service\Flow\FlowStop;
use OCA\OpenRegister\Service\Flow\IFlowNodeTaxonomy;
use OCA\OpenRegister\Service\Flow\Nodes\EndNode;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\EndNode
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\LogicNodeBase
 * @uses   \OCA\OpenRegister\Service\Flow\FlowStop
 */
class EndNodeBehaviourTest extends TestCase {

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
	 * @return EndNode
	 */
	private function node(): EndNode {
		return new EndNode($this->l10n(), $this->urls());
	}

	/**
	 * Run the node and return the stop it throws.
	 *
	 * @param array $config The node config.
	 *
	 * @return FlowStop
	 */
	private function stopFor(array $config): FlowStop {
		try {
			$this->node()->execute(new FlowNodeCall(items: [['json' => []]], config: $config, context: []));
		} catch (FlowStop $stop) {
			return $stop;
		}

		$this->fail('EndNode did not stop the flow');
	}

	/**
	 * The node describes itself as the end event of the logic palette.
	 *
	 * @return void
	 */
	public function testDescriptor(): void {
		$node = $this->node();
		$this->assertSame('openregister.end', $node->getId());
		$this->assertSame('End', $node->getDisplayName());
		$this->assertSame('End the flow here, optionally as an error with a message.', $node->getDescription());
		$this->assertSame('/img/core/actions/close.svg', $node->getIcon());
		$this->assertSame(['error', 'message', 'next'], $node->configKeys());
		$this->assertSame(IFlowNodeTaxonomy::KIND_EVENT, $node->getKind());
		$this->assertSame(IFlowNodeTaxonomy::CATEGORY_LOGIC, $node->getCategory());
		$this->assertTrue($node->isAvailableForScope(IManager::SCOPE_ADMIN));
		$this->assertTrue($node->isAvailableForScope(IManager::SCOPE_USER));
		$this->assertFalse($node->isAvailableForScope(99));
	}

	/**
	 * No items reaching the end means nothing to stop.
	 *
	 * @return void
	 */
	public function testNoItemsPassThrough(): void {
		$this->assertSame([], $this->node()->execute(new FlowNodeCall(items: [], config: ['error' => true], context: [])));
	}

	/**
	 * A plain end stops with the default message, not as an error.
	 *
	 * @return void
	 */
	public function testPlainStop(): void {
		$stop = $this->stopFor([]);
		$this->assertSame('Flow stopped', $stop->getMessage());
		$this->assertFalse($stop->isError());
	}

	/**
	 * An error end without a message uses the error default.
	 *
	 * @return void
	 */
	public function testErrorStopDefaultMessage(): void {
		$stop = $this->stopFor(['error' => true, 'message' => '   ']);
		$this->assertSame('Flow stopped with an error', $stop->getMessage());
		$this->assertTrue($stop->isError());
	}

	/**
	 * Only a literal true makes it an error, and a given message is trimmed and kept.
	 *
	 * @return void
	 */
	public function testMessageKeptAndOnlyTrueIsError(): void {
		$stop = $this->stopFor(['error' => 'true', 'message' => '  Rejected  ']);
		$this->assertSame('Rejected', $stop->getMessage());
		$this->assertFalse($stop->isError());
	}
}
