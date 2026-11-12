<?php

/**
 * The calendar-change recompute job, by name, for whoever queues it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/calendar-change-recomputes-timers/specs/flow-business-timers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

/**
 * The listener that observes a calendar change queues this constant instead of
 * naming the job class. The value is the job's own ::class string, so the job
 * list stores exactly the same name as before.
 */
interface CalendarRecomputeJobInterface {

	/**
	 * The queued job that recomputes the timers of one changed calendar version.
	 *
	 * @var class-string
	 */
	public const JOB = RecomputeTimersForCalendarJob::class;
}//end interface
