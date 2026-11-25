<?php

/**
 * A migration pack request refused with a code and a message for the user.
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

use InvalidArgumentException;
use OCA\OpenRegister\Exception\MigrationPackRefusedException;
use OCA\OpenRegister\Exception\MigrationPackRequestException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MigrationPackRequestException::class)]
#[CoversClass(MigrationPackRefusedException::class)]
final class MigrationPackRequestExceptionTest extends TestCase {

	public function testItCarriesTheCodeAsMessageAndTheUserMessage(): void {
		$refusal = new MigrationPackRequestException(errorCode: 'pack_missing', userMessage: 'Upload a migration pack first.');

		self::assertSame('pack_missing', $refusal->getErrorCode());
		self::assertSame('pack_missing', $refusal->getMessage());
		self::assertSame('Upload a migration pack first.', $refusal->getUserMessage());
	}

	public function testARequestRefusalHasNoFieldErrorsAndIsAnInvalidArgument(): void {
		$refusal = new MigrationPackRequestException(errorCode: 'pack_missing', userMessage: 'x');

		self::assertSame([], $refusal->getErrors());
		self::assertInstanceOf(MigrationPackRefusedException::class, $refusal);
		self::assertInstanceOf(InvalidArgumentException::class, $refusal);
	}
}
