<?php

/**
 * What every node this app ships actually declares.
 *
 * 🔑 THE ASSIGNMENTS ARE PINNED, NOT SPOT-CHECKED. Twenty-seven nodes each
 * declare a kind and a category, and the interesting failure is not "a method
 * is missing" — the interface catches that — but "somebody changed what a step
 * IS". A `switch` that stops being a `gateway` changes what a BPMN export says
 * about the process, and nothing else in the suite would notice.
 *
 * The table below IS the review the change asked for, made mechanical: a new
 * node fails here until somebody writes down what kind of thing it is, and a
 * changed assignment fails until somebody changes it here too, deliberately.
 *
 * ⚠️ THE NODES ARE BUILT WITHOUT THEIR CONSTRUCTORS. `getKind()` and
 * `getCategory()` return a constant and read no state, so an instance with no
 * dependencies answers exactly as a real one does. Constructing them for real
 * would mean mocking l10n, url generators and mappers for 27 classes to ask
 * each of them one question that cannot depend on any of it.
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
 *
 * @spec openspec/changes/flow-node-taxonomy/specs/flow-node-taxonomy/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\IFlowNodeTaxonomy;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Every shipped node's declared kind and category.
 *
 * 🔑 `@covers` PER NODE, NOT `@coversNothing`. The first draft used the latter
 * and the coverage guard was right to still refuse the change: a test that
 * attributes no coverage leaves 54 new methods reading as untested, which is
 * indistinguishable from not having written it.
 *
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\AwaitSignalNode
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\DecisionTableNode
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\EndNode
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\ExplodeNode
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\FilterNode
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\FlowStateNode
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\IterateNode
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\LockObjectNode
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\LoopNode
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\MapNode
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\MergeNode
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\ObjectReadNode
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteNode
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\PortalTaskNode
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\RouterNode
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\SendEmailNode
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\SendNotificationNode
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\SendTalkMessageNode
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\SetFieldsNode
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\SubFlowNode
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\SwitchNode
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\TriggerManualNode
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\TriggerObjectNode
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\TriggerScheduleNode
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\UnlockObjectNode
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\UserTaskNode
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\WaitNode
 */
final class FlowNodeDeclaredTaxonomyTest extends TestCase {

	/**
	 * Where the node classes live.
	 *
	 * @var string
	 */
	private const NODE_DIR = __DIR__ . '/../../../../lib/Service/Flow/Nodes';

	/**
	 * What each node declares: class => [kind, category].
	 *
	 * @return array<string, array{0: string, 1: string}> The expected table.
	 */
	private function expected(): array {
		return [
			// Ways in. An `event` is what BPMN calls a start, whatever fires it.
			'TriggerObjectNode' => ['event', 'triggers'],
			'TriggerManualNode' => ['event', 'triggers'],
			'TriggerScheduleNode' => ['event', 'triggers'],

			// People. `await-signal` is a receiveTask by kind and `human` by
			// category on purpose: it is the machine half of a pair whose other
			// half is "Ask a person".
			'UserTaskNode' => ['userTask', 'human'],
			'PortalTaskNode' => ['userTask', 'human'],
			'AwaitSignalNode' => ['receiveTask', 'human'],

			// Objects. Calling the register is a serviceTask; that it is OUR
			// register is the category's business, not the kind's.
			'ObjectReadNode' => ['serviceTask', 'objects'],
			'ObjectWriteNode' => ['serviceTask', 'objects'],
			'LockObjectNode' => ['serviceTask', 'objects'],
			'UnlockObjectNode' => ['serviceTask', 'objects'],
			'LoopNode' => ['serviceTask', 'objects'],

			// Logic. Branches and joins are gateways; reshaping is a scriptTask.
			'SwitchNode' => ['gateway', 'logic'],
			'RouterNode' => ['gateway', 'logic'],
			'MergeNode' => ['gateway', 'logic'],
			'FilterNode' => ['scriptTask', 'logic'],
			'IterateNode' => ['scriptTask', 'logic'],
			'ExplodeNode' => ['scriptTask', 'logic'],
			'MapNode' => ['scriptTask', 'logic'],
			'SetFieldsNode' => ['scriptTask', 'logic'],
			'FlowStateNode' => ['scriptTask', 'logic'],
			'WaitNode' => ['event', 'logic'],
			'EndNode' => ['event', 'logic'],
			'SubFlowNode' => ['subProcess', 'logic'],
			'DecisionTableNode' => ['businessRuleTask', 'logic'],

			// Messaging.
			'SendEmailNode' => ['sendTask', 'messaging'],
			'SendNotificationNode' => ['sendTask', 'messaging'],
			'SendTalkMessageNode' => ['sendTask', 'messaging'],
		];
	}//end expected()

	/**
	 * Every node class this app ships, by short name.
	 *
	 * @return array<int, string> The class short names.
	 */
	private function shippedNodes(): array {
		$names = [];
		foreach ((scandir(self::NODE_DIR) ?: []) as $file) {
			if (str_ends_with($file, 'Node.php') === false) {
				continue;
			}

			$names[] = substr($file, 0, -4);
		}

		sort($names);

		return $names;
	}//end shippedNodes()

	/**
	 * 🔴 EVERY SHIPPED NODE IS IN THE TABLE, AND THE TABLE HAS NOTHING ELSE.
	 *
	 * A new node with no entry fails here rather than shipping as `other`,
	 * which is what an unreviewed node would otherwise become — visible, but
	 * only to whoever opens the palette.
	 *
	 * @return void
	 */
	public function testEveryShippedNodeIsAccountedFor(): void {
		$this->assertSame(
			$this->shippedNodes(),
			$this->sorted(array_keys($this->expected())),
			'a node added without an entry here has not been reviewed; one removed leaves a stale row'
		);
	}//end testEveryShippedNodeIsAccountedFor()

	/**
	 * Each node declares exactly what the table says.
	 *
	 * @return void
	 */
	public function testEachNodeDeclaresWhatTheTableSays(): void {
		foreach ($this->expected() as $short => [$kind, $category]) {
			$class = 'OCA\\OpenRegister\\Service\\Flow\\Nodes\\' . $short;
			$this->assertTrue(class_exists($class), $short . ' should exist');

			$node = (new ReflectionClass($class))->newInstanceWithoutConstructor();

			$this->assertInstanceOf(
				IFlowNodeTaxonomy::class,
				$node,
				$short . ' must declare its taxonomy'
			);
			$this->assertSame($kind, $node->getKind(), $short . ' declares the wrong kind');
			$this->assertSame($category, $node->getCategory(), $short . ' declares the wrong category');
		}
	}//end testEachNodeDeclaresWhatTheTableSays()

	/**
	 * Every declared value is in the closed vocabulary.
	 *
	 * A typo would otherwise be served as the node wrote it until the registry
	 * quietly replaced it with the default, which is a fallback nobody reads.
	 *
	 * @return void
	 */
	public function testEveryDeclaredValueIsInItsVocabulary(): void {
		foreach ($this->expected() as $short => [$kind, $category]) {
			$this->assertContains($kind, IFlowNodeTaxonomy::KINDS, $short . ' declares an unknown kind');
			$this->assertContains(
				$category,
				IFlowNodeTaxonomy::CATEGORIES,
				$short . ' declares an unknown category'
			);
		}
	}//end testEveryDeclaredValueIsInItsVocabulary()

	/**
	 * A sorted copy.
	 *
	 * @param array<int, string> $values The values.
	 *
	 * @return array<int, string> Sorted.
	 */
	private function sorted(array $values): array {
		sort($values);

		return $values;
	}//end sorted()
}//end class
