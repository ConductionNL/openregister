<?php

/**
 * Golden test for SharePrincipalDeriver::validEntries().
 *
 * Pins which share entries are kept (allowed) and which are dropped (refused),
 * and the exact shape of a kept entry, so the check can be split without a
 * malformed entry ever widening access.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Sharing;

use OCA\OpenRegister\Service\Sharing\SharePrincipalDeriver;
use PHPUnit\Framework\TestCase;

/**
 * Kept and dropped share entries, pinned whole.
 */
class SharePrincipalValidEntriesGoldenTest extends TestCase {

	public function testOnlyWellFormedEntriesAreKeptInOrder(): void {
		$this->assertSame(
			[
				['type' => 'user', 'id' => 'anna', 'permission' => 'write'],
				['type' => 'group', 'id' => 'vth', 'permission' => null],
				['type' => 'user', 'id' => 'bob', 'permission' => null],
			],
			(new SharePrincipalDeriver())->validEntries(
				[
					['type' => 'user', 'id' => ' anna ', 'permission' => 'write', 'extra' => 1],
					'not an entry',
					['type' => 'team', 'id' => 'x'],
					['type' => 'group', 'id' => 'vth'],
					['type' => 'user', 'id' => '   '],
					['type' => 'user', 'id' => 7],
					['id' => 'nobody'],
					['type' => 'user', 'id' => 'bob', 'permission' => ['read']],
				]
			)
		);
	}//end testOnlyWellFormedEntriesAreKeptInOrder()

	public function testANonListGrantsNothing(): void {
		$this->assertSame([], (new SharePrincipalDeriver())->validEntries('anna'));
		$this->assertSame([], (new SharePrincipalDeriver())->validEntries(null));
	}//end testANonListGrantsNothing()
}//end class
