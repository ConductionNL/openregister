<?php

/**
 * Unit tests for ExampleSetBackgroundRun — the background-job nesting guard.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\ExampleSet\ExampleSetBackgroundRun;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ExampleSetBackgroundRunTest extends TestCase {

	public function testNothingIsOpenOutsideARun(): void {
		$this->assertFalse(ExampleSetBackgroundRun::isOpen());
	}

	public function testTheRunIsOpenWhileItsCallableRunsAndItsResultIsReturned(): void {
		$seen = ExampleSetBackgroundRun::inBackgroundJob(static fn (): array => ['open' => ExampleSetBackgroundRun::isOpen()]);

		$this->assertSame(['open' => true], $seen);
		$this->assertFalse(ExampleSetBackgroundRun::isOpen());
	}

	public function testNestedRunsStayOpenUntilTheOuterOneEnds(): void {
		$states = ExampleSetBackgroundRun::inBackgroundJob(
			static function (): array {
				$inner = ExampleSetBackgroundRun::inBackgroundJob(static fn (): bool => ExampleSetBackgroundRun::isOpen());
				return [$inner, ExampleSetBackgroundRun::isOpen()];
			}
		);

		$this->assertSame([true, true], $states);
		$this->assertFalse(ExampleSetBackgroundRun::isOpen());
	}

	public function testAThrowingRunStillCloses(): void {
		try {
			ExampleSetBackgroundRun::inBackgroundJob(static function (): never {
				throw new RuntimeException('boom');
			});
			$this->fail('The exception must propagate.');
		} catch (RuntimeException $e) {
			$this->assertSame('boom', $e->getMessage());
		}

		$this->assertFalse(ExampleSetBackgroundRun::isOpen());
	}
}
