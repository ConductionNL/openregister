<?php

/**
 * NodeWriteTarget's log context names the node and path, and still logs when the node's id cannot be read.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/unit-test-coverage/specs/unit-test-coverage/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener\Support;

use OCA\OpenRegister\Listener\Support\NodeWriteTarget;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Listener\Support\NodeWriteTarget
 */
class NodeWriteTargetTest extends TestCase {

	public function testTheLogContextNamesTheNodeThePathAndTheFailure(): void {
		$node = $this->createMock(Node::class);
		$node->method('getId')->willReturn(4711);
		$failure = new RuntimeException('disk full');

		$context = (new NodeWriteTarget(node: $node, path: '/admin/files/Open Registers/a.json'))->logContext(failure: $failure);

		self::assertSame(['node' => 4711, 'path' => '/admin/files/Open Registers/a.json', 'exception' => $failure], $context);
	}//end testTheLogContextNamesTheNodeThePathAndTheFailure()

	public function testANodeWhoseIdCannotBeReadIsLoggedWithoutOne(): void {
		$node = $this->createMock(Node::class);
		$node->method('getId')->willThrowException(new NotFoundException('gone'));
		$failure = new RuntimeException('disk full');

		$context = (new NodeWriteTarget(node: $node, path: '/admin/files/a.json'))->logContext(failure: $failure);

		self::assertNull($context['node']);
		self::assertSame('/admin/files/a.json', $context['path']);
		self::assertSame($failure, $context['exception']);
	}//end testANodeWhoseIdCannotBeReadIsLoggedWithoutOne()
}//end class
