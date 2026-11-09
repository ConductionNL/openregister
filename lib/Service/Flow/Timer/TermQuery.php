<?php

/**
 * The question the term diagnostic answers: this SLA, from this anchor, on
 * this calendar, with these escalation rungs.
 *
 * The four always travel together from the diagnostic endpoint into
 * {@see TermDiagnostic::explain()} and on to its rungs, so they are one value.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/term-engine-diagnostic/specs/flow-business-timers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * A calendar, an anchor moment, an SLA and an optional ladder.
 *
 * @spec openspec/changes/term-engine-diagnostic/specs/flow-business-timers/spec.md
 */
final class TermQuery {

	/**
	 * Constructor.
	 *
	 * @param WorkingCalendar      $calendar The resolved calendar.
	 * @param DateTimeInterface    $anchor   The anchor moment.
	 * @param array<string, mixed> $sla      `{value, unit, rollToWorkingDay?}`.
	 * @param array<int, mixed>    $ladder   Optional rungs, each `{value, unit}`.
	 *
	 * @spec openspec/changes/term-engine-diagnostic/specs/flow-business-timers/spec.md
	 */
	public function __construct(
		public readonly WorkingCalendar $calendar,
		public readonly DateTimeInterface $anchor,
		public readonly array $sla,
		public readonly array $ladder = [],
	) {

	}//end __construct()

	/**
	 * The anchor as an immutable instant, which every measurement starts from.
	 *
	 * @return DateTimeImmutable The anchor.
	 *
	 * @spec openspec/changes/term-engine-diagnostic/specs/flow-business-timers/spec.md
	 */
	public function start(): DateTimeImmutable {
		return DateTimeImmutable::createFromInterface($this->anchor);
	}//end start()
}//end class
