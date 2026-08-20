<?php

declare(strict_types=1);

namespace Unit\Service\Notification;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Service\Notification\NotificationDeliveryWindowService;
use OCP\IConfig;
use OCP\IDateTimeZone;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers the override-only delivery-window (quiet hours) model: get/set
 * round-trip, no-stored-value -> null (backward compat), past-midnight
 * window wrapping, IANA-timezone live evaluation (including a DST
 * transition), and the `OCP\IDateTimeZone` server fallback when a window
 * declares no explicit timezone.
 */
class NotificationDeliveryWindowServiceTest extends TestCase {
	private IConfig&MockObject $config;
	private IDateTimeZone&MockObject $serverTimezone;
	private NotificationDeliveryWindowService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IConfig::class);
		$this->serverTimezone = $this->createMock(IDateTimeZone::class);
		$this->service = new NotificationDeliveryWindowService($this->config, $this->serverTimezone);
	}

	public function testGetForUserReturnsNullWhenNoStoredValue(): void {
		$this->config->method('getUserValue')->willReturn('');

		$this->assertNull($this->service->getForUser('piet'));
	}

	public function testGetForUserReturnsNullForMalformedJson(): void {
		$this->config->method('getUserValue')->willReturn('not-json');

		$this->assertNull($this->service->getForUser('piet'));
	}

	public function testSetForUserThenGetForUserRoundTrips(): void {
		$stored = null;
		$this->config->method('setUserValue')->willReturnCallback(
			function (string $uid, string $app, string $key, string $value) use (&$stored): void {
				$stored = $value;
			}
		);
		$this->config->method('getUserValue')->willReturnCallback(
			function () use (&$stored) {
				return $stored ?? '';
			}
		);

		$this->service->setForUser('medewerker-1', [
			'enabled' => true,
			'start' => '18:00',
			'end' => '08:00',
			'timezone' => 'Europe/Amsterdam',
		]);

		$window = $this->service->getForUser('medewerker-1');

		$this->assertNotNull($window);
		$this->assertTrue($window['enabled']);
		$this->assertSame('18:00', $window['start']);
		$this->assertSame('08:00', $window['end']);
		$this->assertSame('Europe/Amsterdam', $window['timezone']);
	}

	public function testSetForUserWithNullClearsStoredValue(): void {
		$this->config->expects($this->once())
			->method('deleteUserValue')
			->with('jan', 'openregister', 'notification_delivery_window');
		$this->config->expects($this->never())->method('setUserValue');

		$this->service->setForUser('jan', null);
	}

	public function testSetForUserWithEnabledFalseClearsStoredValue(): void {
		$this->config->expects($this->once())->method('deleteUserValue');
		$this->config->expects($this->never())->method('setUserValue');

		$this->service->setForUser('jan', ['enabled' => false]);
	}

	public function testSetForUserRejectsMalformedStart(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->setForUser('jan', ['start' => '9am', 'end' => '17:00']);
	}

	public function testSetForUserRejectsMalformedTimezone(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service->setForUser('jan', ['start' => '18:00', 'end' => '08:00', 'timezone' => 'Not/AZone']);
	}

	public function testSetForUserFallsBackToServerTimezoneWhenAbsent(): void {
		$this->serverTimezone->method('getTimeZone')->willReturn(new DateTimeZone('Europe/Amsterdam'));

		$captured = null;
		$this->config->method('setUserValue')->willReturnCallback(
			function (string $uid, string $app, string $key, string $value) use (&$captured): void {
				$captured = json_decode($value, true);
			}
		);

		$this->service->setForUser('jan', ['start' => '18:00', 'end' => '08:00']);

		$this->assertSame('Europe/Amsterdam', $captured['timezone']);
	}

	public function testIsInsideWindowDisabledWindowNeverActive(): void {
		$window = ['enabled' => false, 'start' => '18:00', 'end' => '08:00', 'timezone' => 'UTC'];
		$now = new DateTimeImmutable('2026-07-12T22:00:00+00:00');

		$this->assertFalse($this->service->isInsideWindow($window, $now));
	}

	public function testIsInsideWindowNonWrappingRange(): void {
		$window = ['enabled' => true, 'start' => '09:00', 'end' => '17:00', 'timezone' => 'UTC'];

		$this->assertTrue(
			$this->service->isInsideWindow($window, new DateTimeImmutable('2026-07-12T12:00:00+00:00'))
		);
		$this->assertFalse(
			$this->service->isInsideWindow($window, new DateTimeImmutable('2026-07-12T20:00:00+00:00'))
		);
	}

	/**
	 * Past-midnight wrapping: 18:00-08:00 must be active both right after
	 * 18:00 and right before 08:00 the NEXT day.
	 */
	public function testIsInsideWindowWrapsPastMidnight(): void {
		$window = ['enabled' => true, 'start' => '18:00', 'end' => '08:00', 'timezone' => 'Europe/Amsterdam'];

		// 22:15 CEST (evening leg).
		$this->assertTrue(
			$this->service->isInsideWindow($window, new DateTimeImmutable('2026-07-12T20:15:00+00:00'))
		);
		// 07:00 CEST the next morning (past-midnight leg).
		$this->assertTrue(
			$this->service->isInsideWindow($window, new DateTimeImmutable('2026-07-13T05:00:00+00:00'))
		);
		// 12:00 CEST — outside the window.
		$this->assertFalse(
			$this->service->isInsideWindow($window, new DateTimeImmutable('2026-07-13T10:00:00+00:00'))
		);
	}

	/**
	 * DST edge: Europe/Amsterdam moves clocks forward on the last Sunday of
	 * March (CET UTC+1 -> CEST UTC+2). A quiet-hours window of 18:00-08:00
	 * must still be evaluated correctly in LOCAL wall-clock time across the
	 * transition, because the service resolves "now" via DateTimeZone (the
	 * PHP tz database), never a precomputed UTC offset.
	 */
	public function testIsInsideWindowIsCorrectAcrossDstSpringForwardTransition(): void {
		$window = ['enabled' => true, 'start' => '18:00', 'end' => '08:00', 'timezone' => 'Europe/Amsterdam'];

		// 2026-03-29 00:30 UTC == 01:30 CET (just BEFORE the 2026 spring-forward,
		// which happens AT 01:00 UTC: 02:00 CET (UTC+1) jumps to 03:00 CEST
		// (UTC+2)). Local wall-clock time is 01:30, inside the window (before
		// 08:00) under the still-active UTC+1 offset.
		$beforeTransition = new DateTimeImmutable('2026-03-29T00:30:00+00:00');
		$this->assertTrue($this->service->isInsideWindow($window, $beforeTransition));

		// 2026-03-29 06:30 UTC, AFTER the spring-forward, so the correct
		// offset is UTC+2 -> local wall-clock 08:30, OUTSIDE the window (past
		// 08:00). A naive implementation that computed the offset once
		// before the transition (UTC+1) would wrongly get local 07:30
		// (still inside) — this only passes when the offset is re-resolved
		// live via PHP's tz database rather than a precomputed instant.
		$afterTransition = new DateTimeImmutable('2026-03-29T06:30:00+00:00');
		$this->assertFalse($this->service->isInsideWindow($window, $afterTransition));
	}

	public function testIsInsideWindowRestrictsToDeclaredDays(): void {
		// Sunday=0 .. Saturday=6. 2026-07-12 is a Sunday.
		$window = [
			'enabled' => true,
			'start' => '18:00',
			'end' => '22:00',
			'timezone' => 'UTC',
			'days' => [1, 2, 3, 4, 5],
		];

		$sunday = new DateTimeImmutable('2026-07-12T19:00:00+00:00');
		$monday = new DateTimeImmutable('2026-07-13T19:00:00+00:00');

		$this->assertFalse($this->service->isInsideWindow($window, $sunday));
		$this->assertTrue($this->service->isInsideWindow($window, $monday));
	}

	public function testResolveTimezoneFallsBackToServerTimezoneOnInvalidName(): void {
		$this->serverTimezone->method('getTimeZone')->willReturn(new DateTimeZone('Europe/Amsterdam'));

		$tz = $this->service->resolveTimezone('Not/AZone');

		$this->assertSame('Europe/Amsterdam', $tz->getName());
	}

	public function testResolveTimezoneFallsBackToUtcWhenNoServerTimezoneInjected(): void {
		$service = new NotificationDeliveryWindowService($this->config, null);

		$this->assertSame('UTC', $service->resolveTimezone(null)->getName());
	}
}
