<?php

/**
 * Unit tests for ValidationRun — planning chunks for a schema revalidation.
 *
 * plan() estimates the number of chunks only when there is something to process; chunkLabel() names a chunk as "n/total".
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Object\ValidationRun;
use PHPUnit\Framework\TestCase;

final class ValidationRunTest extends TestCase {

	public function testPlanRoundsTheChunkEstimateUp(): void {
		$run = new ValidationRun(3, 4);
		$run->plan(250, 100);

		$this->assertSame(250, $run->objectsToProcess);
		$this->assertSame(100, $run->chunkSize);
		$this->assertSame(3.0, $run->estimatedChunks);
		$this->assertSame('1/3', $run->chunkLabel(0));
		$this->assertSame('3/3', $run->chunkLabel(200));
	}

	public function testNothingToProcessLeavesTheEstimateAtZero(): void {
		$run = new ValidationRun(3, 4);
		$run->plan(0, 100);

		$this->assertSame(0, $run->estimatedChunks);
		$this->assertSame('1/0', $run->chunkLabel(0));
	}
}
