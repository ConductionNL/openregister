<?php

/**
 * Unit test for FlowItemShape's provenance: a member keeps the input index it
 * names (as a map or a bare integer), otherwise takes its fallback.
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

use OCA\OpenRegister\Service\Flow\FlowItemShape;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowItemShape
 */
class FlowItemShapePairingTest extends TestCase {

	/**
	 * A bare integer pairing is kept, a map pairing is read, and a member without one takes its list index.
	 *
	 * @return void
	 */
	public function testAMemberKeepsThePairingItNames(): void {
		$items = FlowItemShape::normalise(value: [
			['json' => ['a' => 1], 'pairedItem' => 4],
			['json' => ['b' => 2], 'pairedItem' => ['item' => '2']],
			['json' => ['c' => 3], 'pairedItem' => 'first'],
			'junk',
		]);

		$this->assertSame([['item' => 4], ['item' => 2], ['item' => 2]], array_column($items, 'pairedItem'));
		$this->assertSame([['a' => 1], ['b' => 2], ['c' => 3]], array_column($items, 'json'));
	}//end testAMemberKeepsThePairingItNames()

	/**
	 * A bare record becomes one item paired with the given input, and nothing becomes no items.
	 *
	 * @return void
	 */
	public function testARecordIsOneItemAndNothingIsNone(): void {
		$this->assertSame(
			[['json' => ['a' => 1, 'output' => 'yes'], 'binary' => [], 'pairedItem' => ['item' => 7], 'output' => 'yes']],
			FlowItemShape::normalise(value: ['a' => 1, 'output' => 'yes'], fromItemIndex: 7)
		);
		$this->assertSame([], FlowItemShape::normalise(value: 'text'));
		$this->assertSame([], FlowItemShape::normalise(value: []));
	}//end testARecordIsOneItemAndNothingIsNone()
}//end class
