<?php

/**
 * OpenRegister AppHost: maps a manifest `interval` to a cron expression.
 *
 * Flows run on cron only. The mapping is exact or absent: an interval no cron
 * expression fires at exactly would change how often the work runs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\AppHost\Scheduling
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Scheduling;

/**
 * Turns seconds into the cron expression firing at exactly that interval.
 *
 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
 */
final class IntervalCron {
	private const MINUTE = 60;
	private const HOUR = 3600;
	private const DAY = 86400;
	private const WEEK = 604800;

	/**
	 * The intervals a fixed daily or weekly expression fires at, by seconds.
	 */
	private const CALENDAR = [
		self::WEEK => '0 0 * * 0',
		self::DAY  => '0 0 * * *',
	];

	/**
	 * The cron expression for an interval, or null when none fires at exactly it.
	 *
	 * @param int $seconds The interval in seconds.
	 *
	 * @return string|null The five-field cron expression.
	 *
	 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
	 */
	public function cronFor(int $seconds): ?string {
		if (isset(self::CALENDAR[$seconds]) === true) {
			return self::CALENDAR[$seconds];
		}

		if ($seconds % self::HOUR === 0) {
			return $this->hourly(field: $this->field(count: intdiv($seconds, self::HOUR), cycle: 24));
		}

		if ($seconds % self::MINUTE === 0) {
			return $this->minutely(field: $this->field(count: intdiv($seconds, self::MINUTE), cycle: 60));
		}

		return null;
	}//end cronFor()

	/**
	 * The cron field stepping by a count that divides its cycle, or null.
	 *
	 * @param int $count The step.
	 * @param int $cycle The units in the field's cycle.
	 *
	 * @return string|null The field.
	 */
	private function field(int $count, int $cycle): ?string {
		if ($count === 1) {
			return '*';
		}

		if ($count >= $cycle || $cycle % $count !== 0) {
			return null;
		}

		return '*/' . $count;
	}//end field()

	/**
	 * A cron expression stepping through the hours.
	 *
	 * @param string|null $field The hour field.
	 *
	 * @return string|null The expression.
	 */
	private function hourly(?string $field): ?string {
		if ($field === null) {
			return null;
		}

		return '0 ' . $field . ' * * *';
	}//end hourly()

	/**
	 * A cron expression stepping through the minutes.
	 *
	 * @param string|null $field The minute field.
	 *
	 * @return string|null The expression.
	 */
	private function minutely(?string $field): ?string {
		if ($field === null) {
			return null;
		}

		return $field . ' * * * *';
	}//end minutely()
}//end class
