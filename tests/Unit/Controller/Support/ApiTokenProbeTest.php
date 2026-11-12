<?php

/**
 * Unit test for ApiTokenProbe.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\Support\ApiTokenProbe;
use PHPUnit\Framework\TestCase;

/**
 * The probe holds what it was given, untouched.
 *
 * @covers \OCA\OpenRegister\Controller\Support\ApiTokenProbe
 */
class ApiTokenProbeTest extends TestCase {
	public function testHoldsEveryFieldAsGiven(): void {
		$probe = new ApiTokenProbe(service: 'gitlab', token: 42, testsSavedToken: true, apiUrl: 'https://g/api/v4/');

		$this->assertSame('gitlab', $probe->service);
		$this->assertSame(42, $probe->token);
		$this->assertTrue($probe->testsSavedToken);
		$this->assertSame('https://g/api/v4/', $probe->apiUrl);
	}

	public function testApiUrlDefaultsToNull(): void {
		$probe = new ApiTokenProbe(service: 'github', token: 'ghp_x', testsSavedToken: false);

		$this->assertNull($probe->apiUrl);
		$this->assertFalse($probe->testsSavedToken);
	}
}
