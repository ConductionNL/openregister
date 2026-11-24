<?php

/**
 * DeliveryWindowSpan: whether a local time falls inside a delivery window.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/unit-test-coverage/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Service\Notification;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTimeImmutable;
use OCA\OpenRegister\Notifications\Service\Notification\DeliveryWindowSpan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\DeliveryWindowSpan
 */
class DeliveryWindowSpanTest extends TestCase {

	/**
	 * 2026-11-16 is a Monday (weekday 1).
	 *
	 * @return array<string, array{array<string, mixed>, string, bool}>
	 */
	public static function windows(): array {
		return [
			'same-day window, inside'           => [['start' => '09:00', 'end' => '17:00'], '2026-11-16 09:00', true],
			'same-day window, last minute'      => [['start' => '09:00', 'end' => '17:00'], '2026-11-16 16:59', true],
			'same-day window, end is exclusive' => [['start' => '09:00', 'end' => '17:00'], '2026-11-16 17:00', false],
			'same-day window, before start'     => [['start' => '09:00', 'end' => '17:00'], '2026-11-16 08:59', false],
			'empty window covers nothing'       => [['start' => '10:30', 'end' => '10:30'], '2026-11-16 10:30', false],
			'wrapped window, evening'           => [['start' => '22:00', 'end' => '06:00'], '2026-11-16 23:15', true],
			'wrapped window, early morning'     => [['start' => '22:00', 'end' => '06:00'], '2026-11-16 05:59', true],
			'wrapped window, daytime gap'       => [['start' => '22:00', 'end' => '06:00'], '2026-11-16 12:00', false],
			'wrapped window, end exclusive'     => [['start' => '22:00', 'end' => '06:00'], '2026-11-16 06:00', false],
			'listed weekday is covered'         => [['start' => '09:00', 'end' => '17:00', 'days' => [1, 2]], '2026-11-16 10:00', true],
			'unlisted weekday is not covered'   => [['start' => '09:00', 'end' => '17:00', 'days' => [2]], '2026-11-16 10:00', false],
			'days compared strictly'            => [['start' => '09:00', 'end' => '17:00', 'days' => ['1']], '2026-11-16 10:00', false],
			'non-array days means every day'    => [['start' => '09:00', 'end' => '17:00', 'days' => 'mon'], '2026-11-16 10:00', true],
			'wrapped tail belongs to start day' => [['start' => '22:00', 'end' => '06:00', 'days' => [0]], '2026-11-16 03:00', true],
			'wrapped tail of an unlisted day'   => [['start' => '22:00', 'end' => '06:00', 'days' => [1]], '2026-11-16 03:00', false],
			'wrapped evening of a listed day'   => [['start' => '22:00', 'end' => '06:00', 'days' => [1]], '2026-11-16 22:00', true],
			'saturday tail wraps to sunday'     => [['start' => '22:00', 'end' => '06:00', 'days' => [6]], '2026-11-15 01:00', true],
		];
	}

	/**
	 * A window covers exactly the expected local times.
	 *
	 * @param array<string, mixed> $window   The delivery window.
	 * @param string               $local    The local time.
	 * @param bool                 $expected Whether the window covers it.
	 *
	 * @return void
	 */
	#[DataProvider('windows')]
	public function testCovers(array $window, string $local, bool $expected): void {
		$span = new DeliveryWindowSpan($window);

		$this->assertSame($expected, $span->covers(new DateTimeImmutable($local)));
	}
}
