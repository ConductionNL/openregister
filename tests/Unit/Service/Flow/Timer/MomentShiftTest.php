<?php

/**
 * Unit tests for shifting a moment by a date modifier.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Timer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Timer;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Exception\FlowTimerValidationException;
use OCA\OpenRegister\Service\Flow\Timer\MomentShift;
use PHPUnit\Framework\TestCase;

/**
 * A parseable modifier shifts the moment; an unparseable one is refused by name.
 *
 * @covers \OCA\OpenRegister\Service\Flow\Timer\MomentShift
 */
class MomentShiftTest extends TestCase {

	/**
	 * A modifier shifts a fixed moment, leaving the original untouched.
	 *
	 * @return void
	 */
	public function testAModifierShiftsTheMoment(): void {
		$moment = new DateTimeImmutable('2026-09-01 09:00:00', new DateTimeZone('UTC'));

		$this->assertSame('2026-09-04 09:00:00', MomentShift::by($moment, '+3 days')->format('Y-m-d H:i:s'));
		$this->assertSame('2026-09-01 09:00:00', $moment->format('Y-m-d H:i:s'));

	}//end testAModifierShiftsTheMoment()

	/**
	 * An unparseable modifier is refused with its own text in the message.
	 *
	 * PHP 8.3 answers such a modifier with a warning and false; the warning is
	 * silenced for the one call so the refusal is what is asserted.
	 *
	 * @return void
	 */
	public function testAnUnparseableModifierIsRefused(): void {
		$moment = new DateTimeImmutable('2026-09-01 09:00:00', new DateTimeZone('UTC'));

		set_error_handler(static fn (): bool => true);
		try {
			MomentShift::by($moment, 'next blue moon');
			$this->fail('an unparseable modifier was accepted');
		} catch (FlowTimerValidationException $e) {
			$this->assertSame("Date modifier 'next blue moon' is not parseable.", $e->getMessage());
		} finally {
			restore_error_handler();
		}

	}//end testAnUnparseableModifierIsRefused()
}//end class
