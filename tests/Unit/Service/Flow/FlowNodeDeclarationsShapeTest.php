<?php

/**
 * Unit test for FlowNodeDeclarations' shape refusals: a node that is no
 * object, a node without an id and a duplicated id are refused by index or id.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Flow\FlowNodeDeclarations;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowNodeDeclarations
 */
class FlowNodeDeclarationsShapeTest extends TestCase {

	/**
	 * The refusal extract() makes, or '' when it accepts.
	 *
	 * @param array<int, mixed> $nodes The nodes.
	 *
	 * @return string
	 */
	private function refusal(array $nodes): string {
		try {
			(new FlowNodeDeclarations())->extract(flow: ['nodes' => $nodes]);
		} catch (InvalidArgumentException $e) {
			return $e->getMessage();
		}

		return '';
	}//end refusal()

	/**
	 * A node that is no object is refused by its index.
	 *
	 * @return void
	 */
	public function testANodeThatIsNoObjectIsRefused(): void {
		$this->assertSame('Flow node at index 1 is not an object.', $this->refusal(nodes: [['id' => 'a', 'type' => 'x.a'], 'b']));
	}//end testANodeThatIsNoObjectIsRefused()

	/**
	 * A node with a blank id is refused by its index, and a repeated id by the id.
	 *
	 * @return void
	 */
	public function testBlankAndRepeatedIdsAreRefused(): void {
		$this->assertSame('Flow node at index 0 has no id.', $this->refusal(nodes: [['id' => '  ', 'type' => 'x.a']]));
		$this->assertSame('Flow declares node id "a" more than once.', $this->refusal(nodes: [['id' => 'a', 'type' => 'x.a'], ['id' => ' a ', 'type' => 'x.b']]));
	}//end testBlankAndRepeatedIdsAreRefused()
}//end class
