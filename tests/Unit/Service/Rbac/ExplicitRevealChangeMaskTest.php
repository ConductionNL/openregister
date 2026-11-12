<?php

/**
 * Tests for ExplicitRevealChangeMask.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\ExplicitRevealChangeMask;
use PHPUnit\Framework\TestCase;

/**
 * Masking of one history change, diff or plain.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\ExplicitRevealChangeMask
 */
class ExplicitRevealChangeMaskTest extends TestCase {

	/**
	 * A plain scalar keeps only its tail.
	 *
	 * @return void
	 */
	public function testPlainScalarIsMasked(): void {
		$this->assertSame('*****6789', ExplicitRevealChangeMask::maskChange(change: '123456789'));
	}//end testPlainScalarIsMasked()

	/**
	 * A plain list has no tail and becomes five stars; null stays null.
	 *
	 * @return void
	 */
	public function testPlainArrayAndNull(): void {
		$this->assertSame('*****', ExplicitRevealChangeMask::maskChange(change: ['a', 'b']));
		$this->assertNull(ExplicitRevealChangeMask::maskChange(change: null));
	}//end testPlainArrayAndNull()

	/**
	 * Both sides of a diff are masked independently.
	 *
	 * @return void
	 */
	public function testBothSidesOfADiffAreMasked(): void {
		$this->assertSame(
			['old' => '*****6789', 'new' => '*****', 'note' => 'kept'],
			ExplicitRevealChangeMask::maskChange(change: ['old' => '123456789', 'new' => ['x' => 1], 'note' => 'kept'])
		);
	}//end testBothSidesOfADiffAreMasked()

	/**
	 * A diff with one side null keeps the null and masks the other side.
	 *
	 * @return void
	 */
	public function testNullSideOfADiffIsKept(): void {
		$this->assertSame(
			['old' => null, 'new' => '*****'],
			ExplicitRevealChangeMask::maskChange(change: ['old' => null, 'new' => 'short'])
		);
		$this->assertSame(['new' => '*****cret'], ExplicitRevealChangeMask::maskChange(change: ['new' => 'topsecret']));
	}//end testNullSideOfADiffIsKept()
}//end class
