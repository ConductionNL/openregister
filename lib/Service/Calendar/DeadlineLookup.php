<?php

/**
 * One request for the date a deadline publishes on.
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

use DateTimeImmutable;

/**
 * The object, declaration, raw value and organisation a deadline resolves from.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calendar
 *
 * @spec openspec/specs/calendar-provider/spec.md
 */
final readonly class DeadlineLookup {

	/**
	 * Constructor.
	 *
	 * @param string $objectUuid The object carrying the deadline.
	 * @param ObjectDateDeclaration $declaration The declaration for the property.
	 * @param DateTimeImmutable|null $rawDate The property's own value, when it has one.
	 * @param string|null $organisation The object's organisation, for calendar resolution.
	 *
	 * @return void
	 */
	public function __construct(
		public string $objectUuid,
		public ObjectDateDeclaration $declaration,
		public ?DateTimeImmutable $rawDate,
		public ?string $organisation,
	) {

	}//end __construct()
}//end class
