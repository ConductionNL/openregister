<?php

/**
 * RegisterSlugProbe: a probe of candidate slugs turned into a resolution.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/unit-test-coverage/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Contract\RegisterSlugResolution;
use OCA\OpenRegister\Service\RegisterSlugProbe;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\RegisterSlugProbe
 * @uses   \OCA\OpenRegister\Contract\RegisterSlugResolution
 */
class RegisterSlugProbeTest extends TestCase {

	public function testTheFirstMatchBecomesTheSlugAndTheProbeTheCandidates(): void {
		$resolution = (new RegisterSlugProbe('zaken', ['zaken', 'cases']))->resolved(RegisterSlugResolution::AMBIGUOUS, ['cases', 'zaken']);

		$this->assertSame('zaken', $resolution->canonical);
		$this->assertSame('cases', $resolution->slug);
		$this->assertSame(['zaken', 'cases'], $resolution->candidates);
		$this->assertSame(['cases', 'zaken'], $resolution->matched);
		$this->assertTrue($resolution->isAmbiguous());
		$this->assertTrue($resolution->isResolved());
	}

	public function testNoMatchIsAnUnresolvedResolution(): void {
		$resolution = (new RegisterSlugProbe('zaken', ['zaken']))->resolved(RegisterSlugResolution::ABSENT, []);

		$this->assertNull($resolution->slug);
		$this->assertFalse($resolution->isResolved());
		$this->assertTrue($resolution->isAbsent());
	}
}
