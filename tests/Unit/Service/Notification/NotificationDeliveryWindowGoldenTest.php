<?php

/**
 * Golden test for NotificationDeliveryWindowService.
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
use InvalidArgumentException;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationDeliveryWindowService;
use OCP\IConfig;
use OCP\IDateTimeZone;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins, against a fixed corpus and fixed instants (never today's date), what
 * the service returns: validateAndNormalize() results and refusals, the
 * isInsideWindow() matrix, resolveTimezone() names with and without a server
 * timezone, and the config round trip of setForUser()/getForUser(). Expected
 * values were recorded from the code before its refactor and live in
 * `fixtures/notification-delivery-window-golden.json`.
 *
 * @coversDefaultClass \OCA\OpenRegister\Notifications\Service\Notification\NotificationDeliveryWindowService
 */
class NotificationDeliveryWindowGoldenTest extends TestCase {

	/**
	 * Path of the golden file.
	 *
	 * @return string
	 */
	public static function goldenPath(): string {
		return __DIR__.'/fixtures/notification-delivery-window-golden.json';
	}//end goldenPath()

	/**
	 * Window bodies fed to validateAndNormalize().
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function bodies(): array {
		return [
			'minimal'          => ['start' => '22:00', 'end' => '07:00'],
			'with-tz'          => ['start' => '09:00', 'end' => '17:30', 'timezone' => 'America/New_York'],
			'no-start'         => ['end' => '07:00'],
			'bad-start'        => ['start' => '24:00', 'end' => '07:00'],
			'int-start'        => ['start' => 900, 'end' => '07:00'],
			'bad-end'          => ['start' => '22:00', 'end' => '7:00'],
			'both-bad'         => ['start' => 'x', 'end' => 'y'],
			'tz-empty'         => ['start' => '22:00', 'end' => '07:00', 'timezone' => ''],
			'tz-int'           => ['start' => '22:00', 'end' => '07:00', 'timezone' => 5],
			'tz-bogus'         => ['start' => '22:00', 'end' => '07:00', 'timezone' => 'Mars/Base'],
			'tz-null'          => ['start' => '22:00', 'end' => '07:00', 'timezone' => null],
			'days-ok'          => ['start' => '22:00', 'end' => '07:00', 'days' => [5, 1, 5, 0, 6]],
			'days-empty'       => ['start' => '22:00', 'end' => '07:00', 'days' => []],
			'days-null'        => ['start' => '22:00', 'end' => '07:00', 'days' => null],
			'days-string'      => ['start' => '22:00', 'end' => '07:00', 'days' => 'mon'],
			'days-out-of-range' => ['start' => '22:00', 'end' => '07:00', 'days' => [1, 7]],
			'days-negative'    => ['start' => '22:00', 'end' => '07:00', 'days' => [-1]],
			'days-string-day'  => ['start' => '22:00', 'end' => '07:00', 'days' => ['1']],
			'tz-bad-and-days'  => ['start' => '22:00', 'end' => '07:00', 'timezone' => 'Nope', 'days' => 'x'],
			'enabled-false'    => ['enabled' => false, 'start' => '22:00', 'end' => '07:00'],
			'same-start-end'   => ['start' => '08:00', 'end' => '08:00', 'timezone' => 'UTC'],
		];
	}//end bodies()

	/**
	 * Stored windows fed to isInsideWindow().
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function windows(): array {
		return [
			'disabled'        => ['enabled' => false, 'start' => '00:00', 'end' => '23:59', 'timezone' => 'UTC'],
			'enabled-missing' => ['start' => '00:00', 'end' => '23:59', 'timezone' => 'UTC'],
			'enabled-truthy'  => ['enabled' => 1, 'start' => '00:00', 'end' => '23:59', 'timezone' => 'UTC'],
			'bad-start'       => ['enabled' => true, 'start' => '25:00', 'end' => '08:00', 'timezone' => 'UTC'],
			'bad-end'         => ['enabled' => true, 'start' => '22:00', 'end' => null, 'timezone' => 'UTC'],
			'zero-length'     => ['enabled' => true, 'start' => '08:00', 'end' => '08:00', 'timezone' => 'UTC'],
			'day-utc'         => ['enabled' => true, 'start' => '09:00', 'end' => '17:00', 'timezone' => 'UTC'],
			'night-utc'       => ['enabled' => true, 'start' => '22:00', 'end' => '07:00', 'timezone' => 'UTC'],
			'night-ams'       => ['enabled' => true, 'start' => '22:00', 'end' => '07:00', 'timezone' => 'Europe/Amsterdam'],
			'day-ny'          => ['enabled' => true, 'start' => '09:00', 'end' => '17:00', 'timezone' => 'America/New_York'],
			'tz-int'          => ['enabled' => true, 'start' => '09:00', 'end' => '17:00', 'timezone' => 3],
			'tz-bogus'        => ['enabled' => true, 'start' => '09:00', 'end' => '17:00', 'timezone' => 'Mars/Base'],
			'tz-absent'       => ['enabled' => true, 'start' => '09:00', 'end' => '17:00'],
			'day-weekdays'    => ['enabled' => true, 'start' => '09:00', 'end' => '17:00', 'timezone' => 'UTC', 'days' => [1, 2, 3, 4, 5]],
			'night-friday'    => ['enabled' => true, 'start' => '22:00', 'end' => '07:00', 'timezone' => 'UTC', 'days' => [5]],
			'night-sunday'    => ['enabled' => true, 'start' => '22:00', 'end' => '07:00', 'timezone' => 'UTC', 'days' => [0]],
			'days-empty'      => ['enabled' => true, 'start' => '22:00', 'end' => '07:00', 'timezone' => 'UTC', 'days' => []],
			'days-string'     => ['enabled' => true, 'start' => '22:00', 'end' => '07:00', 'timezone' => 'UTC', 'days' => 'x'],
			'days-strs'       => ['enabled' => true, 'start' => '09:00', 'end' => '17:00', 'timezone' => 'UTC', 'days' => ['1', '6']],
			'edge-minute'     => ['enabled' => true, 'start' => '16:59', 'end' => '17:00', 'timezone' => 'UTC'],
		];
	}//end windows()

	/**
	 * Fixed instants, chosen around window edges, midnight and a DST change.
	 *
	 * @return array<int, string>
	 */
	public static function instants(): array {
		return [
			'2026-03-06T08:59:00+00:00',
			'2026-03-06T09:00:00+00:00',
			'2026-03-06T16:59:30+00:00',
			'2026-03-06T17:00:00+00:00',
			'2026-03-06T21:59:00+00:00',
			'2026-03-06T22:00:00+00:00',
			'2026-03-07T03:00:00+00:00',
			'2026-03-07T06:59:00+00:00',
			'2026-03-07T07:00:00+00:00',
			'2026-03-07T23:30:00+00:00',
			'2026-03-08T02:00:00+00:00',
			'2026-03-08T22:30:00+00:00',
			'2026-03-09T05:00:00+00:00',
			'2026-03-09T12:00:00+00:00',
			'2026-03-28T21:30:00+00:00',
			'2026-03-29T05:30:00+00:00',
			'2026-03-29T13:30:00+02:00',
		];
	}//end instants()

	/**
	 * A service over an in-memory config, with an optional server timezone.
	 *
	 * @param IDateTimeZone|null   $serverTimezone The server timezone resolver.
	 * @param array<int, string>   $log            Receives every debug line.
	 * @param array<string, mixed> $store          Backing store of the config mock.
	 *
	 * @return NotificationDeliveryWindowService
	 */
	private function service(?IDateTimeZone $serverTimezone, array &$log, array &$store): NotificationDeliveryWindowService {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			static function (string $user, string $app, string $key, $default) use (&$store) {
				return ($store[$user.'/'.$app.'/'.$key] ?? $default);
			}
		);
		$config->method('setUserValue')->willReturnCallback(
			static function (string $user, string $app, string $key, $value) use (&$store): void {
				$store[$user.'/'.$app.'/'.$key] = $value;
			}
		);
		$config->method('deleteUserValue')->willReturnCallback(
			static function (string $user, string $app, string $key) use (&$store): void {
				unset($store[$user.'/'.$app.'/'.$key]);
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			static function ($message) use (&$log): void {
				$log[] = (string) $message;
			}
		);

		return new NotificationDeliveryWindowService($config, $serverTimezone, $logger);
	}//end service()

	/**
	 * A server timezone resolver returning the given zone, or throwing when null.
	 *
	 * @param string|null $zone Zone name, or null for a resolver that throws.
	 *
	 * @return IDateTimeZone
	 */
	private function serverZone(?string $zone): IDateTimeZone {
		$resolver = $this->createMock(IDateTimeZone::class);
		if ($zone === null) {
			$resolver->method('getTimeZone')->willThrowException(new RuntimeException('no zone'));
			return $resolver;
		}

		$resolver->method('getTimeZone')->willReturn(new DateTimeZone($zone));
		return $resolver;
	}//end serverZone()

	/**
	 * Everything the service is pinned on, as one JSON-ready array.
	 *
	 * @return array<string, mixed>
	 */
	public function observe(): array {
		$out = [];
		foreach (['server-ams' => 'Europe/Amsterdam', 'server-throws' => null, 'no-server' => false] as $label => $zone) {
			$log   = [];
			$store = [];
			$server = null;
			if ($zone !== false) {
				$server = $this->serverZone($zone);
			}

			$service = $this->service($server, $log, $store);
			foreach (self::bodies() as $name => $body) {
				try {
					$out['normalize'][$label][$name] = $service->validateAndNormalize($body);
				} catch (InvalidArgumentException $e) {
					$out['normalize'][$label][$name] = 'refused: '.$e->getMessage();
				}
			}

			foreach ([null, '', 'Asia/Tokyo', 'Mars/Base', 'UTC'] as $tzName) {
				$out['timezone'][$label][var_export($tzName, true)] = $service->resolveTimezone($tzName)->getName();
			}

			foreach (self::windows() as $name => $window) {
				$row = '';
				foreach (self::instants() as $instant) {
					$row .= (string) (int) $service->isInsideWindow($window, new DateTimeImmutable($instant));
				}

				$out['inside'][$label][$name] = $row;
			}

			$service->setForUser('alice', ['start' => '22:00', 'end' => '07:00', 'days' => [1, 1]]);
			$out['store'][$label]['after-set'] = $store;
			$out['store'][$label]['get']       = $service->getForUser('alice');
			$service->setForUser('alice', ['enabled' => false]);
			$out['store'][$label]['after-disable'] = $store;
			$service->setForUser('bob', ['start' => '10:00', 'end' => '11:00', 'timezone' => 'UTC']);
			$service->setForUser('bob', null);
			$out['store'][$label]['after-clear'] = $store;
			$store['u/openregister/notification_delivery_window'] = '"corrupt"';
			$out['store'][$label]['get-corrupt'] = $service->getForUser('u');
			$out['store'][$label]['get-missing'] = $service->getForUser('nobody');
			$out['log'][$label] = $log;
		}//end foreach

		return $out;
	}//end observe()

	/**
	 * The service still produces the recorded observations.
	 *
	 * @return void
	 */
	public function testMatchesGolden(): void {
		$golden = json_decode((string) file_get_contents(self::goldenPath()), true);
		$actual = json_decode((string) json_encode($this->observe()), true);
		$this->assertSame($golden, $actual);
	}//end testMatchesGolden()

	/**
	 * The golden pins real outcomes: refusals, normalised bodies and both answers of isInsideWindow().
	 *
	 * @return void
	 */
	public function testGoldenIsNotTrivial(): void {
		$golden = json_decode((string) file_get_contents(self::goldenPath()), true);
		$rows   = implode('', $golden['inside']['server-ams']);
		$this->assertStringContainsString('1', $rows);
		$this->assertStringContainsString('0', $rows);
		$this->assertGreaterThanOrEqual(10, count(array_filter($golden['normalize']['server-ams'], 'is_string')));
		$this->assertGreaterThanOrEqual(5, count(array_filter($golden['normalize']['server-ams'], 'is_array')));
		$this->assertNotSame([], $golden['log']['server-throws']);
	}//end testGoldenIsNotTrivial()
}//end class
