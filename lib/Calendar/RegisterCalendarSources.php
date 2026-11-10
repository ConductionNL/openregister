<?php

/**
 * RegisterCalendarSources — the collaborators a RegisterCalendar reads through.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Calendar
 * @package  OCA\OpenRegister\Calendar
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/calendar-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Calendar;

use OCA\OpenRegister\Db\SchemaCatalogInterface;
use Psr\Log\LoggerInterface;

/**
 * The shared collaborators of the calendar provider and every RegisterCalendar
 * it builds: schema, register and object lookup, event transformation and logging.
 *
 * @spec openspec/specs/calendar-integration/spec.md
 */
final class RegisterCalendarSources {
	/**
	 * Bundle the collaborators.
	 *
	 * @param SchemaCatalogInterface   $catalog     The schema, register and object mappers.
	 * @param CalendarEventTransformer $transformer Turns an object into a VEVENT array.
	 * @param LoggerInterface          $logger      Logs failed lookups.
	 */
	public function __construct(
		public readonly SchemaCatalogInterface $catalog,
		public readonly CalendarEventTransformer $transformer,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
