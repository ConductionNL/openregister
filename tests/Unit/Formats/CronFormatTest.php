<?php

/**
 * The five-field cron format.
 *
 * The tests that carry weight here are the REFUSALS. A format validator that
 * accepts everything passes every happy-path test anyone writes, and the cost of
 * a wrongly-accepted expression is the quietest failure a value can have: the
 * schedule simply never fires, at 03:00, with no request to answer and nobody
 * watching. The moment it is saved is the only cheap moment to catch it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Formats
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Formats;

use OCA\OpenRegister\Formats\CronFormat;
use PHPUnit\Framework\TestCase;

/**
 * Covers CronFormat::validate().
 */
class CronFormatTest extends TestCase {
	/**
	 * The format under test.
	 *
	 * @var CronFormat
	 */
	private CronFormat $format;

	/**
	 * Build the validator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->format = new CronFormat();
	}//end setUp()

	/**
	 * Expressions every crontab accepts.
	 *
	 * @return array<string, array{string}> The cases.
	 */
	public static function validExpressions(): array {
		return [
			'every minute' => ['* * * * *'],
			'the documented example' => ['0 9 * * 1'],
			'a step' => ['*/15 * * * *'],
			'a list' => ['0 0,12 * * *'],
			'ranges in two fields' => ['0 9-17 * * 1-5'],
			'a fixed date' => ['0 9 1 1 *'],
			'Sunday as 0' => ['0 0 * * 0'],
			'Sunday as 7, which standard cron also accepts' => ['0 0 * * 7'],
			'steps in two fields' => ['*/5 */2 * * *'],
			'a stepped range' => ['0-30/5 * * * *'],
			'surrounding whitespace' => ['  0 9 * * 1  '],
		];
	}//end validExpressions()

	/**
	 * Expressions that must be refused.
	 *
	 * @return array<string, array{mixed}> The cases.
	 */
	public static function invalidExpressions(): array {
		return [
			'empty' => [''],
			'four fields' => ['0 9 * *'],
			'six fields' => ['0 9 * * 1 2'],
			'minute 60 — the range ends at 59' => ['60 * * * *'],
			'hour 24' => ['* 24 * * *'],
			'day-of-month 0 — months start at 1' => ['* * 0 * *'],
			'day-of-month 32' => ['* * 32 * *'],
			'month 13' => ['* * * 13 *'],
			'weekday 8 — 7 is already Sunday' => ['* * * * 8'],
			'@daily, whose support varies by scheduler' => ['@daily'],
			'@reboot' => ['@reboot'],
			'a weekday name' => ['0 9 * * mon'],
			'words' => ['a b c d e'],
			'a trailing comma' => ['0 9 * * 1,'],
			'a backwards range' => ['0 9 * * 5-1'],
			'a zero step, which would never advance' => ['*/0 * * * *'],
			'two steps in one term' => ['0/1/2 * * * *'],
			'a negative number' => ['-1 * * * *'],
			'an integer rather than a string' => [5],
			'null' => [null],
			'an array' => [['0 9 * * 1']],
		];
	}//end invalidExpressions()

	/**
	 * A valid expression is accepted.
	 *
	 * @param string $expression The expression.
	 *
	 * @dataProvider validExpressions
	 *
	 * @return void
	 */
	public function testAcceptsValidExpressions(string $expression): void {
		$this->assertTrue($this->format->validate($expression), $expression);
	}//end testAcceptsValidExpressions()

	/**
	 * An invalid expression is refused.
	 *
	 * @param mixed $expression The expression.
	 *
	 * @dataProvider invalidExpressions
	 *
	 * @return void
	 */
	public function testRefusesInvalidExpressions(mixed $expression): void {
		$this->assertFalse($this->format->validate($expression));
	}//end testRefusesInvalidExpressions()
}//end class
