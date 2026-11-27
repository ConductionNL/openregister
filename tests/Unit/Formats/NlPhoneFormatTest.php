<?php

/**
 * Unit tests for NlPhoneFormat (or-form-and-journey-registry task 4.2).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Formats
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

namespace OCA\OpenRegister\Tests\Unit\Formats;

use OCA\OpenRegister\Formats\NlPhoneFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A Dutch number is nine significant digits reached through a `0` trunk or the
 * `+31` / `0031` country code; a person's spaces, hyphens and parentheses are
 * ignored. The `06` mobile and `+31` forms are pinned.
 *
 * @covers \OCA\OpenRegister\Formats\NlPhoneFormat
 *
 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-named-formats-must-replace-the-app-local-validator-forks
 */
class NlPhoneFormatTest extends TestCase {

	/**
	 * The format under test.
	 *
	 * @var NlPhoneFormat
	 */
	private NlPhoneFormat $format;

	protected function setUp(): void {
		$this->format = new NlPhoneFormat();
	}//end setUp()

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function accepted(): array {
		return [
			'06 mobile' => ['0612345678'],
			'another 06 mobile' => ['0687654321'],
			'landline with area code' => ['0201234567'],
			'+31 mobile' => ['+31612345678'],
			'0031 mobile' => ['0031612345678'],
			'+31 with spaces' => ['+31 6 1234 5678'],
			'06 with hyphens' => ['06-1234-5678'],
			'with parentheses' => ['(06) 12345678'],
		];
	}//end accepted()

	#[DataProvider('accepted')]
	public function testADutchNumberIsAccepted(mixed $value): void {
		$this->assertTrue($this->format->validate($value), var_export($value, true) . ' should be a Dutch number');
	}//end testADutchNumberIsAccepted()

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function refused(): array {
		return [
			'too short' => ['061234567'],
			'too long' => ['06123456789'],
			'a zero as first significant digit' => ['0012345678'],
			'letters' => ['06abc45678'],
			'a foreign country code' => ['+49612345678'],
			'no trunk or country code' => ['612345678'],
			'empty' => [''],
			'only separators' => ['-- ()'],
			'not a string or int' => [1.5],
			'an array' => [['0612345678']],
		];
	}//end refused()

	#[DataProvider('refused')]
	public function testANonDutchNumberIsRefused(mixed $value): void {
		$this->assertFalse($this->format->validate($value), var_export($value, true) . ' should not be a Dutch number');
	}//end testANonDutchNumberIsRefused()
}//end class
