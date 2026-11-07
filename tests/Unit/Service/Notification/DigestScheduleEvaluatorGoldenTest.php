<?php

/**
 * Golden test for DigestScheduleEvaluator.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Notification;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Notifications\Service\Notification\DigestScheduleEvaluator;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationDeliveryWindowService;
use OCP\IConfig;
use OCP\IDateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Pins isValidDigestSpec(), lastOccurrence() (on valid blocks only, as its
 * contract says) and isDue() over a fixed corpus
 * of digest blocks and fixed instants (a DST change included, never today's
 * date). Expected values were recorded from the code before its refactor and
 * live in `fixtures/digest-schedule-evaluator-golden.json`.
 *
 * @coversDefaultClass \OCA\OpenRegister\Notifications\Service\Notification\DigestScheduleEvaluator
 */
class DigestScheduleEvaluatorGoldenTest extends TestCase {

	/**
	 * Path of the golden file.
	 *
	 * @return string
	 */
	public static function goldenPath(): string {
		return __DIR__.'/fixtures/digest-schedule-evaluator-golden.json';
	}//end goldenPath()

	/**
	 * Digest blocks.
	 *
	 * @return array<string, mixed>
	 */
	public static function digests(): array {
		return [
			'not-array'        => 'daily',
			'empty'            => [],
			'daily'            => ['schedule' => 'daily', 'at' => '08:30'],
			'daily-utc'        => ['schedule' => 'daily', 'at' => '08:30', 'timezone' => 'UTC'],
			'daily-ny'         => ['schedule' => 'daily', 'at' => '23:59', 'timezone' => 'America/New_York'],
			'daily-bad-tz'     => ['schedule' => 'daily', 'at' => '00:00', 'timezone' => 'Mars/Base'],
			'daily-int-tz'     => ['schedule' => 'daily', 'at' => '00:00', 'timezone' => 5],
			'daily-weekday'    => ['schedule' => 'daily', 'at' => '12:00', 'weekday' => 3],
			'hourly'           => ['schedule' => 'hourly', 'at' => '08:30'],
			'no-at'            => ['schedule' => 'daily'],
			'bad-at'           => ['schedule' => 'daily', 'at' => '8:30'],
			'at-24'            => ['schedule' => 'daily', 'at' => '24:00'],
			'at-int'           => ['schedule' => 'daily', 'at' => 830],
			'weekly-mon'       => ['schedule' => 'weekly', 'at' => '09:00', 'weekday' => 1],
			'weekly-sun-ams'   => ['schedule' => 'weekly', 'at' => '02:30', 'weekday' => 0, 'timezone' => 'Europe/Amsterdam'],
			'weekly-sat-utc'   => ['schedule' => 'weekly', 'at' => '23:00', 'weekday' => 6, 'timezone' => 'UTC'],
			'weekly-no-day'    => ['schedule' => 'weekly', 'at' => '09:00'],
			'weekly-day-7'     => ['schedule' => 'weekly', 'at' => '09:00', 'weekday' => 7],
			'weekly-day-neg'   => ['schedule' => 'weekly', 'at' => '09:00', 'weekday' => -1],
			'weekly-day-str'   => ['schedule' => 'weekly', 'at' => '09:00', 'weekday' => '1'],
			'schedule-int'     => ['schedule' => 1, 'at' => '09:00'],
		];
	}//end digests()

	/**
	 * Fixed instants.
	 *
	 * @return array<int, string>
	 */
	public static function instants(): array {
		return [
			'2026-03-02T08:29:59+00:00',
			'2026-03-02T08:30:00+01:00',
			'2026-03-02T09:00:00+00:00',
			'2026-03-04T12:00:00+00:00',
			'2026-03-07T22:59:00+00:00',
			'2026-03-07T23:00:00+00:00',
			'2026-03-29T00:45:00+00:00',
			'2026-03-29T03:15:00+02:00',
			'2026-03-30T04:00:00+00:00',
		];
	}//end instants()

	/**
	 * Everything pinned, as one JSON-ready array.
	 *
	 * @return array<string, mixed>
	 */
	public function observe(): array {
		$server = $this->createMock(IDateTimeZone::class);
		$server->method('getTimeZone')->willReturn(new DateTimeZone('Europe/Amsterdam'));
		$evaluator = new DigestScheduleEvaluator(new NotificationDeliveryWindowService($this->createMock(IConfig::class), $server));

		$out = [];
		foreach (self::digests() as $name => $digest) {
			$out['valid'][$name] = $evaluator->isValidDigestSpec($digest);
			if (is_array($digest) === false) {
				continue;
			}

			foreach (self::instants() as $instant) {
				$now = new DateTimeImmutable($instant);
				if ($out['valid'][$name] === true) {
					$out['last'][$name][$instant] = $evaluator->lastOccurrence($digest, $now)->format(DATE_ATOM);
				}

				$due = '';
				foreach (['-1 hour', '-25 hours', '-8 days', '+0 seconds', '+1 minute'] as $shift) {
					$due .= (string) (int) $evaluator->isDue($digest, $now->modify($shift), $now);
				}

				$out['due'][$name][$instant] = $due;
			}
		}

		return $out;
	}//end observe()

	/**
	 * The evaluator still produces the recorded observations.
	 *
	 * @return void
	 */
	public function testMatchesGolden(): void {
		$golden = json_decode((string) file_get_contents(self::goldenPath()), true);
		$actual = json_decode((string) json_encode($this->observe()), true);
		$this->assertSame($golden, $actual);
	}//end testMatchesGolden()

	/**
	 * The golden pins both verdicts and real occurrences.
	 *
	 * @return void
	 */
	public function testGoldenIsNotTrivial(): void {
		$golden = json_decode((string) file_get_contents(self::goldenPath()), true);
		$this->assertContains(true, $golden['valid']);
		$this->assertContains(false, $golden['valid']);
		$this->assertSame('2026-03-02T08:30:00+01:00', $golden['last']['daily']['2026-03-02T09:00:00+00:00']);
		$this->assertStringContainsString('0', implode('', $golden['due']['weekly-mon']));
	}//end testGoldenIsNotTrivial()
}//end class
