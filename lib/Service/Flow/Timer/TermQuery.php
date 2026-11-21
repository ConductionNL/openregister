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
	 * @param ResolvedWorkingCalendar|null $resolution How the calendar was resolved, when it was; null for a definition given as is.
	 *
	 * @spec openspec/changes/term-engine-diagnostic/specs/flow-business-timers/spec.md
	 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-working-calendar-resolves-per-record-type-unit-and-instance-req-wca-005
	 */
	public function __construct(
		public readonly WorkingCalendar $calendar,
		public readonly DateTimeInterface $anchor,
		public readonly array $sla,
		public readonly array $ladder = [],
		public readonly ?ResolvedWorkingCalendar $resolution = null,
	) {

	}//end __construct()

	/**
	 * A query on a resolved calendar, so the diagnostic names how it was resolved.
	 *
	 * @param ResolvedWorkingCalendar $resolution The resolved calendar and its provenance.
	 * @param DateTimeInterface       $anchor     The anchor moment.
	 * @param array<string, mixed>    $sla        `{value, unit, rollToWorkingDay?}`.
	 * @param array<int, mixed>       $ladder     Optional rungs.
	 *
	 * @return self The query.
	 *
	 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-working-calendar-resolves-per-record-type-unit-and-instance-req-wca-005
	 */
	public static function resolved(ResolvedWorkingCalendar $resolution, DateTimeInterface $anchor, array $sla, array $ladder = []): self {
		return new self(calendar: $resolution->calendar, anchor: $anchor, sla: $sla, ladder: $ladder, resolution: $resolution);
	}//end resolved()

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
