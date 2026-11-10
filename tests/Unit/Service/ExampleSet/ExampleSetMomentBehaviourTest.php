<?php

/**
 * Behaviour tests for ExampleSetMoment: how relative day and moment tokens
 * resolve against a fixed today.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;


use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetMoment;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour of relative day and moment tokens ({{date:..}} / {{datetime:..}}).
 */
class ExampleSetMomentBehaviourTest extends TestCase {

	private static function today(): DateTimeImmutable {
		// Tuesday 13 October 2026, fixed and zoned so no test depends on the clock.
		return new DateTimeImmutable('2026-10-13 12:00', new DateTimeZone('Europe/Amsterdam'));
	}//end today()

	public function testWithoutATodayThereIsNoMoment(): void {
		$this->assertNull((new ExampleSetMoment())->dayOrMoment(['kind' => 'date', 'rest' => '0'], null));
		$this->assertNull((new ExampleSetMoment())->dayOrMoment(['kind' => 'date', 'rest' => '0'], '2026-10-13'));
	}

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public static function moments(): array {
		return [
			'today' => ['date', '0', '2026-10-13'],
			'plus days' => ['date', '+3', '2026-10-16'],
			'minus days' => ['date', '-13', '2026-09-30'],
			'next friday' => ['date', '0@fri', '2026-10-16'],
			'same weekday stays' => ['date', '0@tue', '2026-10-13'],
			'monday after a week' => ['date', '7@mon', '2026-10-26'],
			'sunday wraps' => ['date', '1@sun', '2026-10-18'],
			'local time to utc' => ['datetime', '1 09:30', '2026-10-14T07:30:00+00:00'],
			'weekday and time' => ['datetime', '0@thu 23:59', '2026-10-15T21:59:00+00:00'],
		];
	}//end moments()

	/**
	 * @dataProvider moments
	 */
	public function testResolvesRelativeToToday(string $kind, string $rest, string $expected): void {
		$this->assertSame($expected, (new ExampleSetMoment())->dayOrMoment(['kind' => $kind, 'rest' => $rest], self::today()));
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function malformed(): array {
		return [
			'date with a time' => ['date', '1 09:30'],
			'datetime without a time' => ['datetime', '1'],
			'bad hour' => ['datetime', '1 24:00'],
			'unknown weekday' => ['date', '0@xyz'],
			'too many digits' => ['date', '12345'],
		];
	}//end malformed()

	/**
	 * @dataProvider malformed
	 */
	public function testAMalformedTokenIsNoMoment(string $kind, string $rest): void {
		$this->assertNull((new ExampleSetMoment())->dayOrMoment(['kind' => $kind, 'rest' => $rest], self::today()));
	}
}//end class
