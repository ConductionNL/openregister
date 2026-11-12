<?php

/**
 * Unit tests for FlowScalarText.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowScalarText;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowScalarText
 */
final class FlowScalarTextTest extends TestCase {

	/**
	 * A scalar is trimmed and returned as a string.
	 *
	 * @return void
	 */
	public function testAScalarIsTrimmedToText(): void {
		self::assertSame('hello', FlowScalarText::orNull('  hello  '));
		self::assertSame('42', FlowScalarText::orNull(42));
		self::assertSame('1.5', FlowScalarText::orNull(1.5));
		self::assertSame('1', FlowScalarText::orNull(true));
		self::assertSame('0', FlowScalarText::orNull('0'));
	}//end testAScalarIsTrimmedToText()

	/**
	 * Empty and whitespace-only text reads as null.
	 *
	 * @return void
	 */
	public function testBlankTextIsNull(): void {
		self::assertNull(FlowScalarText::orNull(''));
		self::assertNull(FlowScalarText::orNull(" \t\n "));
		self::assertNull(FlowScalarText::orNull(false));
	}//end testBlankTextIsNull()

	/**
	 * A non-scalar is never coerced to text.
	 *
	 * @return void
	 */
	public function testANonScalarIsNull(): void {
		self::assertNull(FlowScalarText::orNull(null));
		self::assertNull(FlowScalarText::orNull(['a']));
		self::assertNull(FlowScalarText::orNull(new stdClass()));
	}//end testANonScalarIsNull()
}//end class
