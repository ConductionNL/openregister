<?php

/**
 * OpenRegister NotificationDigestValidator
 *
 * Schema-save validation for the `digest` block of a notification rule.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use DateTimeZone;

/**
 * Validates the optional fixed-time `digest` schedule block of a notification rule.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class NotificationDigestValidator {

	/**
	 * Every diagnostic this class reports: fault => [error code, sprintf format].
	 */
	private const FAULTS = [
		'digest-malformed'              => ['notification-digest-malformed', 'Notification "%s" `digest` must be an object.'],
		'digest-and-coalesce'           => [
			'notification-digest-and-coalesce-mutually-exclusive',
			'Notification "%s" declares both a rolling `coalesce` window and a fixed-time '
			.'`digest` schedule; a rule may declare at most one batching mechanism.',
		],
		'digest-bad-schedule'           => [
			'notification-digest-bad-schedule',
			'Notification "%s" `digest.schedule` must be "daily" or "weekly".',
		],
		'digest-bad-time'               => ['notification-digest-bad-time', 'Notification "%s" `digest.at` must be an "HH:MM" time string.'],
		'digest-bad-timezone'           => [
			'notification-digest-bad-timezone',
			'Notification "%s" `digest.timezone` must be a valid IANA timezone name.',
		],
		'digest-weekday-not-allowed'    => [
			'notification-digest-weekday-not-allowed',
			'Notification "%s" `digest.weekday` is only allowed when `schedule` is "weekly".',
		],
		'digest-weekly-missing-weekday' => [
			'notification-digest-weekly-missing-weekday',
			'Notification "%s" `digest.weekday` (0-6) is required when `schedule` is "weekly".',
		],
	];

	/**
	 * One error as the annotation reports it.
	 *
	 * @param string $fault A key of self::FAULTS.
	 * @param string $name  The rule key the format interpolates.
	 *
	 * @return array{code: string, message: string}
	 */
	private function fault(string $fault, string $name): array {
		[$code, $format] = self::FAULTS[$fault];

		return ['code' => $code, 'message' => sprintf($format, $name)];
	}//end fault()

	/**
	 * Errors for a declared `digest` block, and for pairing it with a `coalesce` window.
	 *
	 * @param array<string, mixed> $spec The rule declaration; it declares `digest`.
	 * @param string               $name Notification name (for diagnostics).
	 *
	 * @return array<int, array{code: string, message: string}>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function validate(array $spec, string $name): array {
		if (is_array($spec['digest']) === false) {
			return [$this->fault(fault: 'digest-malformed', name: $name)];
		}

		$errors = $this->digestErrors(digest: $spec['digest'], name: $name);
		// A rule picks one "hold and batch" mechanism, not both.
		if (is_array($spec['coalesce'] ?? null) === true) {
			$errors[] = $this->fault(fault: 'digest-and-coalesce', name: $name);
		}

		return $errors;
	}//end validate()

	/**
	 * Errors for a `digest` block: schedule, time, weekday and timezone, in that order.
	 *
	 * @param array<string, mixed> $digest The digest block.
	 * @param string               $name   Notification name (for diagnostics).
	 *
	 * @return array<int, array{code: string, message: string}>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function digestErrors(array $digest, string $name): array {
		$errors = [];
		if (in_array(($digest['schedule'] ?? null), ['daily', 'weekly'], true) === false) {
			$errors[] = $this->fault(fault: 'digest-bad-schedule', name: $name);
		}

		if ($this->isDigestTime(time: ($digest['at'] ?? null)) === false) {
			$errors[] = $this->fault(fault: 'digest-bad-time', name: $name);
		}

		$errors = array_merge($errors, $this->digestWeekdayErrors(digest: $digest, name: $name));
		if (isset($digest['timezone']) === true && $this->isValidTimezone(timezone: $digest['timezone']) === false) {
			$errors[] = $this->fault(fault: 'digest-bad-timezone', name: $name);
		}

		return $errors;
	}//end digestErrors()

	/**
	 * Whether a value is an `HH:MM` 24h time string.
	 *
	 * @param mixed $time The declared `digest.at`.
	 *
	 * @return bool
	 */
	private function isDigestTime(mixed $time): bool {
		return is_string($time) === true && preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time) === 1;
	}//end isDigestTime()

	/**
	 * Errors for `digest.weekday`: required (0-6) for a weekly schedule, forbidden otherwise.
	 *
	 * @param array<string, mixed> $digest The digest block.
	 * @param string               $name   Notification name (for diagnostics).
	 *
	 * @return array<int, array{code: string, message: string}>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function digestWeekdayErrors(array $digest, string $name): array {
		$hasWeekday = array_key_exists('weekday', $digest);
		if (($digest['schedule'] ?? null) !== 'weekly') {
			if ($hasWeekday === false) {
				return [];
			}

			return [$this->fault(fault: 'digest-weekday-not-allowed', name: $name)];
		}

		if ($hasWeekday === true && $this->isWeekday(weekday: $digest['weekday']) === true) {
			return [];
		}

		return [$this->fault(fault: 'digest-weekly-missing-weekday', name: $name)];
	}//end digestWeekdayErrors()

	/**
	 * Whether a value is a weekday number, 0 to 6.
	 *
	 * @param mixed $weekday The declared `digest.weekday`.
	 *
	 * @return bool
	 */
	private function isWeekday(mixed $weekday): bool {
		return is_int($weekday) === true && $weekday >= 0 && $weekday <= 6;
	}//end isWeekday()

	/**
	 * Whether a value is a timezone name `DateTimeZone` accepts.
	 *
	 * @param mixed $timezone The raw value.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function isValidTimezone(mixed $timezone): bool {
		if (is_string($timezone) === false || $timezone === '') {
			return false;
		}

		try {
			new DateTimeZone($timezone);
			return true;
		} catch (\Throwable $e) {
			return false;
		}
	}//end isValidTimezone()
}//end class
