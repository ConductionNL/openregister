<?php

/**
 * An extension request and a measuring span: the rationale and positive
 * amount an extension requires, the reason it writes to the ledger, and the
 * span's immutable bounds and description.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Timer;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use DateTime;
use OCA\OpenRegister\Exception\FlowTimerValidationException;
use OCA\OpenRegister\Service\Flow\Timer\CalendarSpan;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerExtension;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use PHPUnit\Framework\TestCase;

/**
 * FlowTimerExtension and CalendarSpan.
 */
class FlowTimerExtensionBehaviourTest extends TestCase {

	public function testAValidExtensionPassesAndNamesItsRationaleAndAmount(): void {
		$extension = new FlowTimerExtension(5, 'businessDays', '  Awaiting documents', 'Awb 4:15');

		$extension->assertValid();

		$this->assertSame('  Awaiting documents (+5 businessDays)', $extension->reason());
	}//end testAValidExtensionPassesAndNamesItsRationaleAndAmount()

	public function testABlankRationaleIsRefused(): void {
		$this->expectException(FlowTimerValidationException::class);
		$this->expectExceptionMessage('An extension requires a non-empty rationale.');

		(new FlowTimerExtension(5, 'hours', '   ', ''))->assertValid();
	}//end testABlankRationaleIsRefused()

	public function testAZeroAmountIsRefused(): void {
		$this->expectException(FlowTimerValidationException::class);
		$this->expectExceptionMessage('An extension amount must be positive; got 0.');

		(new FlowTimerExtension(0, 'hours', 'why', ''))->assertValid();
	}//end testAZeroAmountIsRefused()

	public function testANegativeAmountIsRefused(): void {
		$this->expectException(FlowTimerValidationException::class);
		$this->expectExceptionMessage('An extension amount must be positive; got -2.');

		(new FlowTimerExtension(-2, 'hours', 'why', ''))->assertValid();
	}//end testANegativeAmountIsRefused()

	public function testASpanCopiesItsBoundsAndDescribesThem(): void {
		$calendar = WorkingCalendar::fromArray(['slug' => 'c', 'workingWeekdays' => [1, 2, 3, 4, 5], 'hoursPerWorkingDay' => 8]);
		$from = new DateTime('2026-01-05T09:00:00+00:00');
		$span = new CalendarSpan($from, new DateTime('2026-01-07T17:00:00+00:00'), $calendar);
		$from->modify('+1 year');

		$this->assertSame(
			'Measuring the elapsed time between 2026-01-05T09:00:00+00:00 and 2026-01-07T17:00:00+00:00',
			$span->describe('the elapsed time')
		);
	}//end testASpanCopiesItsBoundsAndDescribesThem()
}//end class
