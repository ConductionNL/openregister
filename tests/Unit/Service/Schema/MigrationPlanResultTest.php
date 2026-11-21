<?php

/**
 * MigrationPlanResult: a plan outcome is failed exactly when it carries a failure.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schema
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

namespace OCA\OpenRegister\Tests\Unit\Service\Schema;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Schema\MigrationPlanResult;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Schema\MigrationPlanResult
 */
class MigrationPlanResultTest extends TestCase {

	public function testAResultWithoutAFailureIsNotFailedAndAppliedNothingByDefault(): void {
		$result = new MigrationPlanResult(['a' => 1], true);

		$this->assertFalse($result->isFailed());
		$this->assertNull($result->getFailure());
		$this->assertSame([], $result->getApplied());
	}

	public function testAResultWithAFailureIsFailedAndKeepsWhatWasApplied(): void {
		$result = new MigrationPlanResult(['a' => 1], false, 'cast failed', ['rename']);

		$this->assertTrue($result->isFailed());
		$this->assertFalse($result->isChanged());
		$this->assertSame(['a' => 1], $result->getData());
		$this->assertSame(['rename'], $result->getApplied());
	}

	public function testAnEmptyFailureMessageStillCountsAsFailed(): void {
		$this->assertTrue((new MigrationPlanResult([], false, ''))->isFailed());
	}
}
