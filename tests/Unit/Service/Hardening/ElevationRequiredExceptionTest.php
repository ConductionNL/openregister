<?php

/**
 * The refusal an administration call without a fresh sign-in carries.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Hardening
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Hardening;

use OCA\OpenRegister\Service\Hardening\ElevationRequiredException;
use PHPUnit\Framework\TestCase;

/**
 * The refusal names the elevation period and flags itself for the client.
 *
 * @covers \OCA\OpenRegister\Service\Hardening\ElevationRequiredException
 */
final class ElevationRequiredExceptionTest extends TestCase {

	/**
	 * The body tells the client to re-authenticate and for how long elevation lasts.
	 *
	 * @return void
	 */
	public function testTheBodyFlagsElevationAndCarriesThePeriod(): void {
		$refusal = new ElevationRequiredException(periodSeconds: 600);

		$this->assertSame(600, $refusal->getPeriodSeconds());
		$this->assertSame(
			[
				'error' => 'Administration needs a fresh sign-in. Confirm your password, then try again.',
				'elevationRequired' => true,
				'periodSeconds' => 600,
			],
			$refusal->toArray()
		);
	}//end testTheBodyFlagsElevationAndCarriesThePeriod()
}//end class
