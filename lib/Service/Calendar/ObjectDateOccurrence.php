<?php

/**
 * One declared date of one object, as it is turned into an event.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calendar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/calendar-provider/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calendar;

use DateTimeZone;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The object, its schema, the date declaration and the tenant zone one VEVENT
 * is built from.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calendar
 *
 * @spec openspec/specs/calendar-provider/spec.md
 */
final readonly class ObjectDateOccurrence {

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $object The object.
	 * @param int|string $schemaId The schema the object belongs to.
	 * @param ObjectDateDeclaration $declaration The date declaration.
	 * @param DateTimeZone $timeZone The tenant time zone.
	 *
	 * @return void
	 */
	public function __construct(
		public ObjectEntity $object,
		public int|string $schemaId,
		public ObjectDateDeclaration $declaration,
		public DateTimeZone $timeZone,
	) {

	}//end __construct()
}//end class
