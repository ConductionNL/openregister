<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Serializer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Serializer;

use OCA\OpenRegister\Service\Serializer\RegisterExpansion;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Serializer\RegisterExpansion
 */
class RegisterExpansionTest extends TestCase {

	public function testNoExtendExpandsNothing(): void {
		$expansion = RegisterExpansion::fromExtend([]);

		$this->assertFalse($expansion->schemas);
		$this->assertNull($expansion->counts);
	}

	public function testSchemasAndStatsExpandWithCounts(): void {
		$expansion = RegisterExpansion::fromExtend(['schemas', '@self.stats', 'unknown'], [10 => ['total' => 2]]);

		$this->assertTrue($expansion->schemas);
		$this->assertSame(2, $expansion->counts?->totalFor(10));
	}

	public function testStatsWithoutCountsStillAttachZero(): void {
		$expansion = RegisterExpansion::fromExtend(['schemas', '@self.stats']);

		$this->assertSame(0, $expansion->counts?->totalFor(10));
	}

	public function testSchemasAloneAttachNoCounts(): void {
		$expansion = RegisterExpansion::fromExtend(['schemas'], [10 => ['total' => 2]]);

		$this->assertTrue($expansion->schemas);
		$this->assertNull($expansion->counts);
	}
}//end class
