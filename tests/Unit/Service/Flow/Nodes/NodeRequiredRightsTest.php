<?php

/**
 * Unit test: the right each built-in powerful step declares.
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
 *
 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteNode;
use OCA\OpenRegister\Service\Flow\Nodes\SendEmailNode;
use OCA\OpenRegister\Service\Flow\Nodes\SendNotificationNode;
use OCA\OpenRegister\Service\Flow\Nodes\SendTalkMessageNode;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Each messaging step needs its own right; the object write needs one only to delete.
 *
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\SendEmailNode
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\SendNotificationNode
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\SendTalkMessageNode
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteNode
 */
final class NodeRequiredRightsTest extends TestCase {

	/**
	 * One node of a class, built without running its constructor.
	 *
	 * The rights a node declares are its own constants and read no collaborator,
	 * so the real class is used rather than a double that would assert nothing.
	 *
	 * @param string $class The node class.
	 *
	 * @return object The node.
	 */
	private function node(string $class): object {
		return (new ReflectionClass($class))->newInstanceWithoutConstructor();
	}//end node()

	/**
	 * Every messaging step declares its right, for any configuration.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-a-flow-step-type-can-require-a-named-right
	 */
	public function testEachMessagingStepDeclaresItsRight(): void {
		$expected = [
			SendEmailNode::class => 'flow.node.send-email',
			SendNotificationNode::class => 'flow.node.send-notification',
			SendTalkMessageNode::class => 'flow.node.send-talk-message',
		];

		foreach ($expected as $class => $right) {
			$node = $this->node(class: $class);
			$this->assertSame($right, $node->requiredRight(config: []), $class);
			$this->assertSame($right, $node->requiredRight(config: ['recipients' => ['bob']]), $class.' with a configuration');
			$this->assertSame([$right => $node->declaredRights()[$right]], $node->declaredRights(), $class.' declares exactly its right');
			$this->assertNotSame('', $node->declaredRights()[$right], $class.' describes the right');
		}
	}//end testEachMessagingStepDeclaresItsRight()

	/**
	 * The object write needs a right to DELETE and none for the other operations.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-a-flow-step-type-can-require-a-named-right
	 */
	public function testTheObjectWriteNeedsARightOnlyToDelete(): void {
		$node = $this->node(class: ObjectWriteNode::class);

		$this->assertSame('flow.node.object-delete', $node->requiredRight(config: ['operation' => 'delete']));
		$this->assertSame('flow.node.object-delete', $node->requiredRight(config: ['operation' => ' delete ']), 'the operation is trimmed');
		$this->assertNull($node->requiredRight(config: ['operation' => 'update']));
		$this->assertNull($node->requiredRight(config: ['operation' => 'upsert']));
		// A step with no operation creates, which needs no right.
		$this->assertNull($node->requiredRight(config: []));
		$this->assertSame(['flow.node.object-delete'], array_keys($node->declaredRights()));
	}//end testTheObjectWriteNeedsARightOnlyToDelete()
}//end class
