<?php

/**
 * OpenRegister NotificationDeliveryWindowService
 *
 * Override-only storage + live evaluation for a user's global delivery
 * window (quiet hours). Mirrors NotificationPreferenceService's
 * override-only, zero-migration `IConfig::setUserValue` pattern: a single
 * JSON value per user under app `openregister`, key
 * `notification_delivery_window`. A user who has never configured a window
 * has no stored value — `getForUser()` returns null and the dispatcher
 * treats that as "never queue this user for quiet hours", which is exactly
 * today's (pre-change) immediate-dispatch behaviour.
 *
 * The window shape is `{enabled, start: "HH:MM", end: "HH:MM", timezone,
 * days?: [0-6]}`. `timezone` is an IANA name (e.g. "Europe/Amsterdam"),
 * never a UTC offset, so DST transitions are handled by PHP's tz database
 * rather than by this code (see
 * openspec/changes/notification-delivery-windows/design.md "Timezone
 * handling"). `days`, when present, restricts the window to the listed
 * weekdays using PHP's `DateTime::format('w')` convention (0=Sunday .. 6=
 * Saturday).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/notification-delivery-windows/design.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCP\IConfig;
use OCP\IDateTimeZone;
use Psr\Log\LoggerInterface;

/**
 * Resolves and stores a user's override-only delivery-window preference,
 * and evaluates whether a given instant falls inside it.
 */
class NotificationDeliveryWindowService {
	/**
	 * App id used for the user-config namespace.
	 */
	private const APP_NAME = 'openregister';

	/**
	 * Per-user app-config key holding the JSON-encoded window.
	 */
	private const CONFIG_KEY = 'notification_delivery_window';

	/**
	 * Constructor.
	 *
	 * @param IConfig $config Nextcloud config for per-user values.
	 * @param IDateTimeZone|null $serverTimezone Server-timezone resolver, used as the
	 *                                           fallback when a window declares no `timezone`.
	 * @param LoggerInterface|null $logger Optional logger for diagnostics.
	 */
	public function __construct(
		private readonly IConfig $config,
		private readonly ?IDateTimeZone $serverTimezone = null,
		private readonly ?LoggerInterface $logger = null,
	) {
	}//end __construct()

	/**
	 * Read a user's stored delivery-window preference. Returns null when no
	 * value is stored (zero-migration "no window configured" case) or when
	 * the stored value is malformed — never throws for a corrupt row.
	 *
	 * @param string $userId The user UID.
	 *
	 * @return array<string, mixed>|null The decoded window, or null when none/invalid.
	 */
	public function getForUser(string $userId): ?array {
		$raw = $this->config->getUserValue($userId, self::APP_NAME, self::CONFIG_KEY, '');
		if ($raw === '') {
			return null;
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			return null;
		}

		return $decoded;
	}//end getForUser()

	/**
	 * Write or clear a user's delivery-window preference. Passing null (or
	 * `{enabled: false}`) clears the stored value so the user is never
	 * queued for quiet hours (zero-migration fall-through).
	 *
	 * @param string $userId The user UID.
	 * @param array<string, mixed>|null $window Window body, or null to clear.
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException When `$window` is present but malformed.
	 */
	public function setForUser(string $userId, ?array $window): void {
		if ($window === null || ($window['enabled'] ?? true) === false) {
			$this->config->deleteUserValue($userId, self::APP_NAME, self::CONFIG_KEY);
			return;
		}

		$clean = $this->validateAndNormalize(window: $window);

		$this->config->setUserValue($userId, self::APP_NAME, self::CONFIG_KEY, json_encode($clean));

	}//end setForUser()

	/**
	 * Validate a window body and return its normalized form.
	 *
	 * @param array<string, mixed> $window Raw window body.
	 *
	 * @return array{enabled: bool, start: string, end: string, timezone: string, days: array<int, int>|null}
	 *
	 * @throws \InvalidArgumentException When `start`/`end`/`timezone` are malformed.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-users-must-be-able-to-manage-their-notification-preferences
	 */
	public function validateAndNormalize(array $window): array {
		$this->assertWindowTimes(window: $window);
		$timezone = ($window['timezone'] ?? null);
		$days     = $this->normalizedDays(days: ($window['days'] ?? null));
		// The server zone is resolved even when the window declares its own.
		$serverZone = $this->resolveServerTimezoneName();

		return [
			'enabled' => true,
			'start' => (string)$window['start'],
			'end' => (string)$window['end'],
			'timezone' => ($timezone ?? $serverZone),
			'days' => $days,
		];

	}//end validateAndNormalize()

	/**
	 * Refuse a window whose `start`, `end` or `timezone` is malformed, in that order.
	 *
	 * @param array<string, mixed> $window Raw window body.
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException When a value is malformed.
	 */
	private function assertWindowTimes(array $window): void {
		$this->assertTimeOfDay(window: $window, key: 'start');
		$this->assertTimeOfDay(window: $window, key: 'end');
		$this->assertTimezone(timezone: ($window['timezone'] ?? null));
	}//end assertWindowTimes()

	/**
	 * Refuse a window whose `start` or `end` is not an "HH:MM" time string.
	 *
	 * @param array<string, mixed> $window Raw window body.
	 * @param string               $key    `start` or `end`.
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException When the value is malformed.
	 */
	private function assertTimeOfDay(array $window, string $key): void {
		if ($this->isValidTimeOfDay(value: ($window[$key] ?? null)) === false) {
			throw new InvalidArgumentException(sprintf('"%s" must be an "HH:MM" time string.', $key));
		}
	}//end assertTimeOfDay()

	/**
	 * Refuse a declared `timezone` that is not a valid IANA name; absent is fine.
	 *
	 * @param mixed $timezone The declared timezone, null when absent.
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException When the value is malformed.
	 */
	private function assertTimezone(mixed $timezone): void {
		if ($timezone === null) {
			return;
		}

		if (is_string($timezone) === false || $timezone === '' || $this->isValidTimezone(value: $timezone) === false) {
			throw new InvalidArgumentException('"timezone" must be a valid IANA timezone name.');
		}
	}//end assertTimezone()

	/**
	 * The declared `days` as a de-duplicated list, null when absent.
	 *
	 * @param mixed $days The declared days.
	 *
	 * @return array<int, int>|null
	 *
	 * @throws \InvalidArgumentException When `days` is not a list of integers 0-6.
	 */
	private function normalizedDays(mixed $days): ?array {
		if ($days === null) {
			return null;
		}

		if (is_array($days) === false) {
			throw new InvalidArgumentException('"days" must be an array of integers 0-6.');
		}

		$this->assertWeekdays(days: $days);

		return array_values(array_unique($days));
	}//end normalizedDays()

	/**
	 * Refuse a `days` list with an entry that is not an integer 0-6.
	 *
	 * @param array<int|string, mixed> $days The declared days.
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException When an entry is not a weekday number.
	 */
	private function assertWeekdays(array $days): void {
		foreach ($days as $day) {
			if ($this->isWeekday(day: $day) === false) {
				throw new InvalidArgumentException('"days" entries must be integers 0-6.');
			}
		}
	}//end assertWeekdays()

	/**
	 * Whether a value is a weekday number, 0 to 6.
	 *
	 * @param mixed $day Candidate value.
	 *
	 * @return bool
	 */
	private function isWeekday(mixed $day): bool {
		return is_int($day) === true && $day >= 0 && $day <= 6;
	}//end isWeekday()

	/**
	 * Whether `$now` falls inside `$window` — the recipient is currently in
	 * quiet hours. Resolves `now` in the window's declared `timezone`
	 * (falling back to the Nextcloud server timezone when absent), so DST
	 * shifts are handled by PHP's tz database rather than by a precomputed
	 * instant (see design.md "Timezone handling").
	 *
	 * Correctly wraps past-midnight ranges (e.g. `18:00`-`08:00`): active
	 * from 18:00 through 23:59 AND from 00:00 through 07:59.
	 *
	 * @param array<string, mixed> $window Decoded window (as returned by getForUser()).
	 * @param DateTimeImmutable $now The instant to evaluate (any timezone; re-projected below).
	 *
	 * @return bool True when `$now` is inside the configured window.
	 */
	public function isInsideWindow(array $window, DateTimeImmutable $now): bool {
		if ($this->isEvaluable(window: $window) === false) {
			return false;
		}

		$local = $now->setTimezone($this->resolveTimezone(tzName: $this->declaredTimezone(window: $window)));
		$span  = new DeliveryWindowSpan(window: $window);

		return $span->covers(local: $local);
	}//end isInsideWindow()

	/**
	 * Whether a stored window is enabled and has a valid `start` and `end`.
	 *
	 * @param array<string, mixed> $window Decoded window.
	 *
	 * @return bool
	 */
	private function isEvaluable(array $window): bool {
		return ($window['enabled'] ?? false) === true
			&& $this->isValidTimeOfDay(value: ($window['start'] ?? null)) === true
			&& $this->isValidTimeOfDay(value: ($window['end'] ?? null)) === true;
	}//end isEvaluable()

	/**
	 * The window's declared timezone name, null when absent or not a string.
	 *
	 * @param array<string, mixed> $window Decoded window.
	 *
	 * @return string|null
	 */
	private function declaredTimezone(array $window): ?string {
		$tzName = ($window['timezone'] ?? null);
		if (is_string($tzName) === false) {
			return null;
		}

		return $tzName;
	}//end declaredTimezone()

	/**
	 * Resolve a `DateTimeZone` from a declared name, falling back to the
	 * Nextcloud server timezone (never a hardcoded UTC/Europe/Amsterdam
	 * default) when absent or invalid.
	 *
	 * @param string|null $tzName Declared IANA timezone name, or null.
	 *
	 * @return DateTimeZone
	 */
	public function resolveTimezone(?string $tzName): DateTimeZone {
		return ($this->declaredZone(tzName: $tzName) ?? $this->serverZone() ?? new DateTimeZone('UTC'));
	}//end resolveTimezone()

	/**
	 * The declared zone, null when absent or not a name `DateTimeZone` accepts.
	 *
	 * @param string|null $tzName Declared IANA timezone name, or null.
	 *
	 * @return DateTimeZone|null
	 */
	private function declaredZone(?string $tzName): ?DateTimeZone {
		if ($tzName === null || $tzName === '') {
			return null;
		}

		return $this->zoneNamed(tzName: $tzName);
	}//end declaredZone()

	/**
	 * The zone of this name, null when `DateTimeZone` does not accept it.
	 *
	 * @param string $tzName IANA timezone name.
	 *
	 * @return DateTimeZone|null
	 */
	private function zoneNamed(string $tzName): ?DateTimeZone {
		try {
			return new DateTimeZone($tzName);
		} catch (\Throwable $e) {
			return null;
		}
	}//end zoneNamed()

	/**
	 * The Nextcloud server zone, null when there is no resolver or it fails.
	 *
	 * @return DateTimeZone|null
	 */
	private function serverZone(): ?DateTimeZone {
		if ($this->serverTimezone === null) {
			return null;
		}

		try {
			return $this->serverTimezone->getTimeZone();
		} catch (\Throwable $e) {
			$this->logger?->debug('[NotificationDeliveryWindowService] server timezone resolution failed: '.$e->getMessage());
			return null;
		}
	}//end serverZone()

	/**
	 * Name of the server-default timezone (used to persist a resolved
	 * default when a window is saved without an explicit `timezone`).
	 *
	 * @return string
	 */
	private function resolveServerTimezoneName(): string {
		return $this->resolveTimezone(tzName: null)->getName();
	}//end resolveServerTimezoneName()

	/**
	 * Whether `$value` is a well-formed "HH:MM" 24h time-of-day string.
	 *
	 * @param mixed $value Candidate value.
	 *
	 * @return bool
	 */
	private function isValidTimeOfDay(mixed $value): bool {
		return is_string($value) === true && preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value) === 1;
	}//end isValidTimeOfDay()

	/**
	 * Whether `$value` names a timezone `DateTimeZone` accepts.
	 *
	 * @param string $value Candidate IANA timezone name.
	 *
	 * @return bool
	 */
	private function isValidTimezone(string $value): bool {
		try {
			new DateTimeZone($value);
			return true;
		} catch (\Throwable $e) {
			return false;
		}

	}//end isValidTimezone()
}//end class
