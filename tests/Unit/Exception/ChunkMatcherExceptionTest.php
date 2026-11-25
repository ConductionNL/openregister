<?php

/**
 * A chunk match refused with a machine-readable reason.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Exception
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/unit-test-coverage/specs/unit-test-coverage/spec.md#requirement-test-all-exception-and-format-classes
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Exception;

use OCA\OpenRegister\Exception\ChunkMatcherException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChunkMatcherException::class)]
final class ChunkMatcherExceptionTest extends TestCase {

	public function testItCarriesTheReasonAndTheMessage(): void {
		$refusal = new ChunkMatcherException(reason: ChunkMatcherException::REASON_VALUE_TOO_LONG, message: 'The value is longer than 4096 characters.');

		self::assertSame('value_too_long', $refusal->getReason());
		self::assertSame('The value is longer than 4096 characters.', $refusal->getMessage());
	}

	public function testTheMessageIsOptional(): void {
		$refusal = new ChunkMatcherException(reason: ChunkMatcherException::REASON_REGEX_COMPILE_FAILURE);

		self::assertSame('regex_compile_failure', $refusal->getReason());
		self::assertSame('', $refusal->getMessage());
	}
}
