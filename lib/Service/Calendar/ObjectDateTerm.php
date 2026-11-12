<?php

/**
 * OpenRegister ObjectDateTerm
 *
 * How a declared deadline is computed and announced.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calendar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calendar;

/**
 * How a declared deadline is computed and announced: the alarm before it,
 * the flow timer it reads its date from and the working calendar it rolls
 * against. Groups the term half of an {@see ObjectDateDeclaration} so its
 * constructor stays small.
 *
 * @spec openspec/specs/calendar-provider/spec.md#requirement-schema-calendar-configuration
 */
final class ObjectDateTerm {
	/**
	 * Constructor.
	 *
	 * @param int|null $alarmOffsetDays Days before a deadline to warn, when any.
	 * @param string|null $timerPurpose The flow-timer purpose a deadline reads its date from.
	 * @param string|null $calendarSlug The working calendar a deadline resolves against.
	 */
	public function __construct(
		public readonly ?int $alarmOffsetDays,
		public readonly ?string $timerPurpose,
		public readonly ?string $calendarSlug,
	) {
	}//end __construct()
}//end class
