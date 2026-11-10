<?php

/**
 * AppHost scheduling: interval to cron mapping tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Scheduling
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Scheduling;

use OCA\OpenRegister\AppHost\Scheduling\IntervalCron;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleCadence;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleCall;
use OCA\OpenRegister\AppHost\Scheduling\ScheduleDescriptor;
use PHPUnit\Framework\TestCase;

/**
 * An interval maps to the cron firing at exactly it, or to nothing.
 *
 * @covers \OCA\OpenRegister\AppHost\Scheduling\IntervalCron
 * @covers \OCA\OpenRegister\AppHost\Scheduling\ScheduleDescriptor
 */
class IntervalCronTest extends TestCase {
	/**
	 * @dataProvider exactIntervals
	 */
	public function testAnExactIntervalMaps(int $seconds, string $cron): void {
		$this->assertSame($cron, (new IntervalCron())->cronFor($seconds));
	}

	/**
	 * Intervals a cron expression fires at exactly.
	 *
	 * @return array<string, array{0: int, 1: string}>
	 */
	public static function exactIntervals(): array {
		return [
			'one minute' => [60, '* * * * *'],
			'five minutes' => [300, '*/5 * * * *'],
			'half an hour' => [1800, '*/30 * * * *'],
			'one hour' => [3600, '0 * * * *'],
			'six hours' => [21600, '0 */6 * * *'],
			'twelve hours' => [43200, '0 */12 * * *'],
			'one day' => [86400, '0 0 * * *'],
			'one week' => [604800, '0 0 * * 0'],
		];
	}

	/**
	 * @dataProvider inexactIntervals
	 */
	public function testAnIntervalWithNoExactCronIsRefused(int $seconds): void {
		$this->assertNull((new IntervalCron())->cronFor($seconds));
	}

	/**
	 * Intervals no cron expression fires at exactly.
	 *
	 * @return array<string, array{0: int}>
	 */
	public static function inexactIntervals(): array {
		return [
			'under a minute' => [30],
			'not whole minutes' => [90],
			'seven minutes' => [420],
			'ninety minutes' => [5400],
			'five hours' => [18000],
			'two days' => [172800],
			'two weeks' => [1209600],
		];
	}

	public function testADeclaredCronIsUsedAsIs(): void {
		$schedule = new ScheduleDescriptor('s', new ScheduleCadence(null, '0 6 * * 1-5'), new ScheduleCall('a'));

		$this->assertSame('0 6 * * 1-5', $schedule->cronExpression());
	}

	public function testAnIntervalScheduleAnswersWithItsCron(): void {
		$schedule = new ScheduleDescriptor('s', new ScheduleCadence(86400, null), new ScheduleCall('a'));

		$this->assertSame('0 0 * * *', $schedule->cronExpression());
	}
}
