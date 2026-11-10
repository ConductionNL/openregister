<?php

/**
 * Unit test for CeilingBreach: the wait it names.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Middleware\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Middleware\Exception;

use OCA\OpenRegister\Middleware\Exception\CeilingBreach;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Middleware\Exception\CeilingBreach
 */
class CeilingBreachTest extends TestCase {

	public function testRetryAfterIsTheSecondsUntilTheWindowRolls(): void {
		$this->assertSame(10, (new CeilingBreach(5, 30, 1000, 990))->retryAfter());
	}

	public function testRetryAfterIsNeverLessThanOneSecond(): void {
		$this->assertSame(1, (new CeilingBreach(5, 30, 1000, 1000))->retryAfter());
		$this->assertSame(1, (new CeilingBreach(5, 30, 1000, 2000))->retryAfter());
	}
}//end class
