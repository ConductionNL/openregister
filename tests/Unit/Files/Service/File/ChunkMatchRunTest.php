<?php

/**
 * ChunkMatchRun: the context window cut around one match in a chunk.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Files\Service\File
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

namespace OCA\OpenRegister\Tests\Unit\Files\Service\File;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Files\Service\File\ChunkMatchRun;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Files\Service\File\ChunkMatchRun
 */
class ChunkMatchRunTest extends TestCase {

	public function testTheRunKeepsItsPatternAndStartsWithoutHits(): void {
		$run = new ChunkMatchRun('/needle/i', 6);

		$this->assertSame('/needle/i', $run->pattern);
		$this->assertSame(6, $run->needleLength);
		$this->assertSame([], $run->hits);
	}

	public function testTheContextIsThirtyCharactersEitherSideOfTheMatch(): void {
		$text = str_repeat('a', 40).'NEEDLE'.str_repeat('b', 40);
		$run = new ChunkMatchRun('/NEEDLE/', 6);

		$this->assertSame(str_repeat('a', 30).'NEEDLE'.str_repeat('b', 30), $run->contextAround($text, 40));
	}

	public function testTheContextIsClampedToTheStartAndEndOfTheText(): void {
		$run = new ChunkMatchRun('/x/', 1);

		$this->assertSame('0123456789x', $run->contextAround('0123456789x', 10));
		$this->assertSame('x'.str_repeat('c', 30), $run->contextAround('x'.str_repeat('c', 50), 0));
	}
}
