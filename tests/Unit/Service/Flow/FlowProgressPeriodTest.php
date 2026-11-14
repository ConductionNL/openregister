<?php

/**
 * FlowProgressPeriod: the default window, whole days, and the refusals.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/tasks-progress-report/specs/flow-progress-report/spec.md#requirement-a-flow-reports-its-progress-per-step
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Service\Flow\FlowProgressPeriod;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowProgressPeriod
 */
class FlowProgressPeriodTest extends TestCase {

	private static function now(): DateTime {
		return new DateTime('2026-11-14T12:00:00+00:00');
	}

	public function testNoParametersMeanTheLastNinetyDays(): void {
		$period = new FlowProgressPeriod(from: null, to: '  ', now: self::now());

		$this->assertSame('2026-11-14T12:00:00+00:00', $period->to->format(DATE_ATOM));
		$this->assertSame('2026-08-16T12:00:00+00:00', $period->from->format(DATE_ATOM));
	}

	public function testABareDateCoversTheWholeDay(): void {
		$period = new FlowProgressPeriod(from: '2026-08-01', to: '2026-08-31', now: self::now());

		$this->assertSame('2026-08-01 00:00:00', $period->from->format('Y-m-d H:i:s'));
		$this->assertSame('2026-08-31 23:59:59', $period->to->format('Y-m-d H:i:s'));
	}

	public function testOnlyToShiftsTheDefaultStart(): void {
		$period = new FlowProgressPeriod(from: null, to: '2026-09-30T00:00:00+00:00', now: self::now());

		$this->assertSame('2026-07-02T00:00:00+00:00', $period->from->format(DATE_ATOM));
	}

	public function testExactly366DaysIsAllowed(): void {
		$period = new FlowProgressPeriod(from: '2025-09-01T00:00:00+00:00', to: '2026-09-02T00:00:00+00:00', now: self::now());

		$this->assertSame('2025-09-01', $period->from->format('Y-m-d'));
	}

	public function testALongerWindowIsRefusedNamingFrom(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Parameter "from" is too early: a window spans at most 366 days.');

		new FlowProgressPeriod(from: '2025-01-01', to: '2026-09-01', now: self::now());
	}

	public function testAReversedWindowIsRefusedNamingFrom(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Parameter "from" must not be after "to".');

		new FlowProgressPeriod(from: '2026-10-01', to: '2026-09-01', now: self::now());
	}

	public function testAnUnparsableParameterIsNamed(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Parameter "to" is not a date: soon');

		new FlowProgressPeriod(from: null, to: 'soon', now: self::now());
	}
}
