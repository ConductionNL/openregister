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

namespace OCA\OpenRegister\Service\Notification;

use DateTimeImmutable;
use DateTimeZone;
use OCP\IConfig;
use OCP\IDateTimeZone;
use Psr\Log\LoggerInterface;

/**
 * Resolves and stores a user's override-only delivery-window preference,
 * and evaluates whether a given instant falls inside it.
 */
class NotificationDeliveryWindowService
{
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
     * @param IConfig              $config         Nextcloud config for per-user values.
     * @param IDateTimeZone|null   $serverTimezone Server-timezone resolver, used as the
     *                                             fallback when a window declares no `timezone`.
     * @param LoggerInterface|null $logger         Optional logger for diagnostics.
     */
    public function __construct(
        private readonly IConfig $config,
        private readonly ?IDateTimeZone $serverTimezone=null,
        private readonly ?LoggerInterface $logger=null
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
    public function getForUser(string $userId): ?array
    {
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
     * @param string                    $userId The user UID.
     * @param array<string, mixed>|null $window Window body, or null to clear.
     *
     * @return void
     *
     * @throws \InvalidArgumentException When `$window` is present but malformed.
     */
    public function setForUser(string $userId, ?array $window): void
    {
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
     */
    public function validateAndNormalize(array $window): array
    {
        $start = $window['start'] ?? null;
        $end   = $window['end'] ?? null;
        if ($this->isValidTimeOfDay(value: $start) === false) {
            throw new \InvalidArgumentException('"start" must be an "HH:MM" time string.');
        }

        if ($this->isValidTimeOfDay(value: $end) === false) {
            throw new \InvalidArgumentException('"end" must be an "HH:MM" time string.');
        }

        $timezone = $window['timezone'] ?? null;
        if ($timezone !== null) {
            if (is_string($timezone) === false || $timezone === '' || $this->isValidTimezone(value: $timezone) === false) {
                throw new \InvalidArgumentException('"timezone" must be a valid IANA timezone name.');
            }
        }

        $days = null;
        if (isset($window['days']) === true) {
            if (is_array($window['days']) === false) {
                throw new \InvalidArgumentException('"days" must be an array of integers 0-6.');
            }

            $days = [];
            foreach ($window['days'] as $day) {
                if (is_int($day) === false || $day < 0 || $day > 6) {
                    throw new \InvalidArgumentException('"days" entries must be integers 0-6.');
                }

                $days[] = $day;
            }

            $days = array_values(array_unique($days));
        }

        $timezoneName = $this->resolveServerTimezoneName();
        if ($timezone !== null) {
            $timezoneName = (string) $timezone;
        }

        return [
            'enabled'  => true,
            'start'    => (string) $start,
            'end'      => (string) $end,
            'timezone' => $timezoneName,
            'days'     => $days,
        ];

    }//end validateAndNormalize()

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
     * @param DateTimeImmutable    $now    The instant to evaluate (any timezone; re-projected below).
     *
     * @return bool True when `$now` is inside the configured window.
     */
    public function isInsideWindow(array $window, DateTimeImmutable $now): bool
    {
        if (($window['enabled'] ?? false) !== true) {
            return false;
        }

        $start = $window['start'] ?? null;
        $end   = $window['end'] ?? null;
        if ($this->isValidTimeOfDay(value: $start) === false || $this->isValidTimeOfDay(value: $end) === false) {
            return false;
        }

        $tzName = $window['timezone'] ?? null;
        if (is_string($tzName) === false) {
            $tzName = null;
        }

        $tz = $this->resolveTimezone(tzName: $tzName);

        $local = $now->setTimezone($tz);

        $startMinutes = $this->minutesSinceMidnight(time: (string) $start);
        $endMinutes   = $this->minutesSinceMidnight(time: (string) $end);
        $nowMinutes   = ((int) $local->format('G') * 60) + (int) $local->format('i');
        $weekday      = (int) $local->format('w');

        if ($startMinutes === $endMinutes) {
            // Ambiguous zero-length / full-day range — treat as "never
            // active" rather than guessing 24h-always-on.
            return false;
        }

        $days = $window['days'] ?? null;
        if (is_array($days) === true && count($days) > 0) {
            $todayAllowed     = in_array($weekday, $days, true) === true;
            $yesterdayAllowed = in_array((($weekday + 6) % 7), $days, true) === true;

            if ($startMinutes < $endMinutes) {
                return $todayAllowed === true
                    && $nowMinutes >= $startMinutes
                    && $nowMinutes < $endMinutes;
            }

            // Wrapping window: the "today" leg only applies on days the
            // window starts; the "past midnight" leg belongs to the day
            // the window STARTED (yesterday, from now's perspective).
            return ($todayAllowed === true && $nowMinutes >= $startMinutes)
                || ($yesterdayAllowed === true && $nowMinutes < $endMinutes);
        }

        if ($startMinutes < $endMinutes) {
            return $nowMinutes >= $startMinutes && $nowMinutes < $endMinutes;
        }

        // Wrapping window, no day restriction: active either side of midnight.
        return $nowMinutes >= $startMinutes || $nowMinutes < $endMinutes;

    }//end isInsideWindow()

    /**
     * Resolve a `DateTimeZone` from a declared name, falling back to the
     * Nextcloud server timezone (never a hardcoded UTC/Europe/Amsterdam
     * default) when absent or invalid.
     *
     * @param string|null $tzName Declared IANA timezone name, or null.
     *
     * @return DateTimeZone
     */
    public function resolveTimezone(?string $tzName): DateTimeZone
    {
        if ($tzName !== null && $tzName !== '' && $this->isValidTimezone(value: $tzName) === true) {
            try {
                return new DateTimeZone($tzName);
            } catch (\Throwable $e) {
                // Fall through to server default below.
            }
        }

        if ($this->serverTimezone !== null) {
            try {
                return $this->serverTimezone->getTimeZone();
            } catch (\Throwable $e) {
                $this->logger?->debug(
                    '[NotificationDeliveryWindowService] server timezone resolution failed: '.$e->getMessage()
                );
            }
        }

        return new DateTimeZone('UTC');

    }//end resolveTimezone()

    /**
     * Name of the server-default timezone (used to persist a resolved
     * default when a window is saved without an explicit `timezone`).
     *
     * @return string
     */
    private function resolveServerTimezoneName(): string
    {
        return $this->resolveTimezone(tzName: null)->getName();

    }//end resolveServerTimezoneName()

    /**
     * Whether `$value` is a well-formed "HH:MM" 24h time-of-day string.
     *
     * @param mixed $value Candidate value.
     *
     * @return bool
     */
    private function isValidTimeOfDay(mixed $value): bool
    {
        if (is_string($value) === false) {
            return false;
        }

        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value) !== 1) {
            return false;
        }

        return true;

    }//end isValidTimeOfDay()

    /**
     * Whether `$value` names a timezone `DateTimeZone` accepts.
     *
     * @param string $value Candidate IANA timezone name.
     *
     * @return bool
     */
    private function isValidTimezone(string $value): bool
    {
        try {
            new DateTimeZone($value);
            return true;
        } catch (\Throwable $e) {
            return false;
        }

    }//end isValidTimezone()

    /**
     * Parse an "HH:MM" string into minutes since midnight.
     *
     * @param string $time "HH:MM" string (already validated).
     *
     * @return int
     */
    private function minutesSinceMidnight(string $time): int
    {
        [$hours, $minutes] = explode(':', $time);
        return ((int) $hours * 60) + (int) $minutes;

    }//end minutesSinceMidnight()
}//end class
