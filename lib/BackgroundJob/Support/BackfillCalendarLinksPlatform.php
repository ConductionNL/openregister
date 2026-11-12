<?php

/**
 * OpenRegister BackfillCalendarLinksPlatform
 *
 * The app config and logger the calendar link backfill is gated and reports with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec exclude Disabled-by-default one-time Tier-2 migration backfill; not a production capability surface.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob\Support;

use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * The app config and logger the calendar link backfill is gated and reports with.
 *
 * @spec exclude Disabled-by-default one-time Tier-2 migration backfill; not a production capability surface.
 */
final class BackfillCalendarLinksPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param IAppConfig      $appConfig App config (for the flag).
	 * @param LoggerInterface $logger    Logger.
	 *
	 * @spec exclude Disabled-by-default one-time Tier-2 migration backfill; not a production capability surface.
	 */
	public function __construct(
		public readonly IAppConfig $appConfig,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
