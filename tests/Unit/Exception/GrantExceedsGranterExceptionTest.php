<?php

/**
 * A grant refused because it exceeds the granter's own rights.
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

use OCA\OpenRegister\Exception\GrantExceedsGranterException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GrantExceedsGranterException::class)]
final class GrantExceedsGranterExceptionTest extends TestCase {

	public function testTheBodyNamesTheErrorAndTheExcess(): void {
		$excess = ['delete' => ['schema-7'], 'update' => ['schema-9']];
		$refusal = new GrantExceedsGranterException(excess: $excess);

		self::assertSame(['error' => 'grant-exceeds-granter', 'excess' => $excess], $refusal->body());
		self::assertSame($excess, $refusal->getExcess());
		self::assertSame('The grant gives rights the granter does not hold.', $refusal->getMessage());
	}

	public function testAnEmptyExcessStaysEmpty(): void {
		self::assertSame(['error' => GrantExceedsGranterException::CODE, 'excess' => []], (new GrantExceedsGranterException(excess: []))->body());
	}
}
