<?php

/**
 * A text-service call refused because its disclosure could not be recorded.
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

use OCA\OpenRegister\Exception\TextDisclosureNotRecordedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(TextDisclosureNotRecordedException::class)]
final class TextDisclosureNotRecordedExceptionTest extends TestCase {

	public function testTheMessageNamesTheServiceThatWasNotCalled(): void {
		$refusal = new TextDisclosureNotRecordedException(service: 'presidio');

		self::assertSame('The text disclosure record could not be written, so the call to presidio was not made', $refusal->getMessage());
		self::assertSame(0, $refusal->getCode());
		self::assertNull($refusal->getPrevious());
	}

	public function testTheWriteFailureIsKeptAsThePrevious(): void {
		$cause = new RuntimeException('disk full');

		self::assertSame($cause, (new TextDisclosureNotRecordedException(service: 'openai', previous: $cause))->getPrevious());
	}
}
