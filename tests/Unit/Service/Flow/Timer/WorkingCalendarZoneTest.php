<?php

/**
 * The zone a working calendar counts its days in.
 *
 * A calendar date is not an instant. "The term ends on 2 June" becomes a
 * moment only once somebody says where midnight is, and until now nothing in
 * the timer vocabulary said. The server's own zone answered by default, so the
 * same calendar counted different days on two servers and neither reported it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Timer;

use OCA\OpenRegister\Exception\FlowTimerValidationException;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use PHPUnit\Framework\TestCase;

/**
 * The zone, its default, and what it refuses.
 *
 * @covers \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar
 */
class WorkingCalendarZoneTest extends TestCase {
	/**
	 * The seeded Dutch calendar counts Dutch days.
	 *
	 * Asserted against the descriptor rather than a literal in the test, so a
	 * seed that loses the field fails here instead of quietly counting UTC
	 * days for a Dutch organisation.
	 *
	 * @return void
	 */
	public function testTheSeededDutchCalendarCountsDutchDays(): void {
		$this->assertSame(
			'Europe/Amsterdam',
			WorkingCalendar::fromArray(definition: WorkingCalendarTest::nlNational())->getTimezone()
		);
	}

	/**
	 * A calendar that declares no zone counts UTC days.
	 *
	 * UTC and not the server's: `date_default_timezone` is whatever the
	 * instance happens to be set to, so falling back to it makes the same
	 * calendar answer differently on two servers.
	 *
	 * @return void
	 */
	public function testTheDefaultIsUtcAndNotTheServers(): void {
		$definition = WorkingCalendarTest::nlNational();
		unset($definition['timezone']);

		// THE SERVER IS MOVED FIRST, on purpose. Run on a box that is already
		// on UTC, an assertion of 'UTC' passes whether the default is the
		// constant or the server's setting, so it proves nothing about the
		// branch it is aimed at. Pointing the process somewhere else makes
		// the two answers different.
		$was = date_default_timezone_get();
		date_default_timezone_set('Pacific/Auckland');
		try {
			$this->assertSame('UTC', WorkingCalendar::fromArray(definition: $definition)->getTimezone());
		} finally {
			date_default_timezone_set($was);
		}
	}

	/**
	 * A zone that is not an IANA name is refused by name.
	 *
	 * @return void
	 */
	public function testAZoneThatDoesNotResolveIsRefused(): void {
		$this->expectException(FlowTimerValidationException::class);
		$this->expectExceptionMessageMatches('/timezone/');

		WorkingCalendar::fromArray(
			definition: array_merge(WorkingCalendarTest::nlNational(), ['timezone' => 'CET+1'])
		);
	}

	/**
	 * A zone that IS an IANA name is taken as written.
	 *
	 * The control for the refusal above: a validator that threw on everything
	 * would pass that test and make every calendar unbuildable.
	 *
	 * @return void
	 */
	public function testARealZoneIsAccepted(): void {
		$this->assertSame(
			'Pacific/Auckland',
			WorkingCalendar::fromArray(
				definition: array_merge(WorkingCalendarTest::nlNational(), ['timezone' => 'Pacific/Auckland'])
			)->getTimezone()
		);
	}
}//end class
