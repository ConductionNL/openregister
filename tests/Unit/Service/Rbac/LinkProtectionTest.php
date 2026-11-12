<?php

/**
 * Link protection sets only the password and expiry the caller gave.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Service\Rbac\LinkProtection;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\LinkProtection
 */
class LinkProtectionTest extends TestCase {

	public function testAGivenPasswordAndExpiryAreBothApplied(): void {
		$share = $this->createMock(IShare::class);
		$share->expects($this->once())->method('setPassword')->with('s3cret');
		$share->expects($this->once())
			->method('setExpirationDate')
			->with($this->callback(static fn (DateTime $date): bool => $date->format('Y-m-d') === '2030-05-01'));

		(new LinkProtection('s3cret', '2030-05-01'))->applyTo($share);
	}

	public function testNullOrBlankValuesTouchNothing(): void {
		foreach ([[null, null], ['', '  '], ["\t", null]] as [$password, $expiration]) {
			$share = $this->createMock(IShare::class);
			$share->expects($this->never())->method('setPassword');
			$share->expects($this->never())->method('setExpirationDate');

			(new LinkProtection($password, $expiration))->applyTo($share);
		}
	}

	public function testAnExpiryAloneLeavesThePasswordAlone(): void {
		$share = $this->createMock(IShare::class);
		$share->expects($this->never())->method('setPassword');
		$share->expects($this->once())->method('setExpirationDate');

		(new LinkProtection(null, '2031-01-01'))->applyTo($share);
	}

	public function testAnUnreadableExpiryIsRefusedAfterThePasswordIsSet(): void {
		$share = $this->createMock(IShare::class);
		$share->expects($this->once())->method('setPassword')->with('pw');
		$share->expects($this->never())->method('setExpirationDate');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Could not read that expiry date');

		(new LinkProtection('pw', 'not a date at all'))->applyTo($share);
	}
}
