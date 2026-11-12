<?php

/**
 * OpenRegister calendar link backfill run
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

/**
 * The running tally of one BackfillCalendarLinksJob run, shared by its per-user helpers.
 *
 * Built by BackfillCalendarLinksJob::run(); it never leaves the job.
 *
 * @spec exclude Disabled-by-default one-time Tier-2 migration backfill, gated behind the
 *       `backfill_calendar_links` app-config flag; not a production capability surface.
 */
final class CalendarLinkBackfillRun {
	/**
	 * Link rows inserted so far.
	 *
	 * @var integer
	 */
	public int $inserted = 0;

	/**
	 * Events skipped so far because their link row already existed.
	 *
	 * @var integer
	 */
	public int $skipped = 0;
}//end class
