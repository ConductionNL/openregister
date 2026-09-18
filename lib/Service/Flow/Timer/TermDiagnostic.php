<?php

/**
 * What the term engine would do for a date you choose (row Q8.18).
 *
 * The register's note: "Five working-day implementations in `lib/Service/` that
 * disagree with each other, and no surface that evaluates any of them." An
 * administrator cannot explain to a citizen why a term landed where it did,
 * because the only way to see the engine work is to arm a timer and wait.
 *
 * 🔴 IT ARMS NOTHING AND WRITES NOTHING (D-2). No timer, no ledger event, no
 * audit row. That is not a promise this class makes in a comment: it holds a
 * calculator and a calendar and nothing that can write, so there is no mapper,
 * no connection and no dispatcher to reach for.
 *
 * 🔴 AND IT REFUSES TO NARRATE SOMETHING THE ENGINE WOULD NOT DO. The change
 * asks for a `rollToWorkingDay` in the SLA shape, and `SlaCalculator` HAS NO
 * ROLL: neither `add()` nor the arm path moves a landing off a non-working day.
 * A diagnostic that quietly applied one would print a fire moment the engine
 * would never produce, and it would be believed precisely because it is the
 * diagnostic. So a requested roll is refused, naming why, until the roll exists
 * in the engine. That is the whole point of D-1: the same code path, narrated.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Timer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/term-engine-diagnostic/specs/flow-business-timers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Exception\FlowTimerValidationException;

/**
 * Runs the term engine read-only and returns its working.
 *
 * @spec openspec/changes/term-engine-diagnostic/specs/flow-business-timers/spec.md
 */
class TermDiagnostic {

	/**
	 * The roll values a caller may ask for, and which this can honour.
	 *
	 * Only `none` can be honoured today. The other two are accepted as INPUT
	 * so the refusal can name them, rather than reading as an unknown word.
	 *
	 * @var array<int, string>
	 */
	public const ROLLS = ['none', 'next', 'previous'];

	/**
	 * How many rungs a ladder may have.
	 *
	 * @var int
	 */
	public const MAX_RUNGS = 20;

	/**
	 * Constructor.
	 *
	 * @param SlaCalculator $calculator The engine the arm path uses.
	 */
	public function __construct(
		private readonly SlaCalculator $calculator,
	) {
	}//end __construct()

	/**
	 * Explain what arming this SLA against this anchor would compute.
	 *
	 * @param WorkingCalendar   $calendar The resolved calendar.
	 * @param DateTimeInterface $anchor   The anchor moment.
	 * @param array<string, mixed> $sla   `{value, unit, rollToWorkingDay?}`.
	 * @param array<int, mixed> $ladder   Optional rungs, each `{value, unit}`.
	 *
	 * @return array<string, mixed> The fire moment, the walk, the roll, the zone and the rungs.
	 *
	 * @throws FlowTimerValidationException When the SLA, the roll or the ladder is refused.
	 *
	 * @spec openspec/changes/term-engine-diagnostic/specs/flow-business-timers/spec.md
	 */
	public function explain(
		WorkingCalendar $calendar,
		DateTimeInterface $anchor,
		array $sla,
		array $ladder = []
	): array {
		$normalised = $this->calculator->validateSla(sla: $sla);
		$roll = $this->validateRoll(sla: $sla);

		$collector = new WalkCollector();
		$start = DateTimeImmutable::createFromInterface($anchor);

		$firesAt = $this->calculator->add(
			from: $start,
			value: (float)$normalised['value'],
			unit: $normalised['unit'],
			calendar: $calendar,
			collector: $collector
		);

		return [
			'calendar' => $calendar->getSlug(),
			'zone' => $calendar->getTimezone(),
			'anchorAt' => $start->format(DATE_ATOM),
			'sla' => $normalised,
			'roll' => $roll,
			'firesAt' => $firesAt->format(DATE_ATOM),
			'firesOnWorkingDay' => $calendar->isWorkingDay($firesAt),
			'walk' => $collector->walk(),
			'skipped' => $collector->skipped(),
			'examinedDays' => $collector->examinedCount(),
			'walkTruncated' => $collector->isTruncated(),
			'ladder' => $this->rungs(calendar: $calendar, anchor: $start, ladder: $ladder),
		];
	}//end explain()

	/**
	 * The instant each rung of a ladder would fire at.
	 *
	 * Each rung is measured from the ANCHOR, not from the rung before it,
	 * because that is what the escalation ladder does: a rung is a fraction of
	 * the same term, not a term of its own. Measuring cumulatively would put
	 * every rung later than the engine puts it, and the further down the
	 * ladder the wronger it would read.
	 *
	 * @param WorkingCalendar   $calendar The calendar.
	 * @param DateTimeImmutable $anchor   The anchor.
	 * @param array<int, mixed> $ladder   The rungs.
	 *
	 * @return array<int, array<string, mixed>> The rungs with their instants.
	 *
	 * @throws FlowTimerValidationException When a rung is refused.
	 */
	private function rungs(WorkingCalendar $calendar, DateTimeImmutable $anchor, array $ladder): array {
		if (count($ladder) > self::MAX_RUNGS) {
			throw new FlowTimerValidationException(
				message: sprintf('A ladder may have at most %d rungs; %d were given.', self::MAX_RUNGS, count($ladder))
			);
		}

		$rungs = [];
		foreach ($ladder as $index => $rung) {
			$normalised = $this->calculator->validateSla(sla: $rung);
			$firesAt = $this->calculator->add(
				from: $anchor,
				value: (float)$normalised['value'],
				unit: $normalised['unit'],
				calendar: $calendar
			);

			$rungs[] = [
				'rung' => (int)$index,
				'sla' => $normalised,
				'firesAt' => $firesAt->format(DATE_ATOM),
			];
		}

		return $rungs;
	}//end rungs()

	/**
	 * The roll the caller asked for, refused when the engine cannot do it.
	 *
	 * @param array<string, mixed> $sla The submitted SLA.
	 *
	 * @return string The roll in effect, which today is always `none`.
	 *
	 * @throws FlowTimerValidationException When a roll is asked for.
	 */
	private function validateRoll(array $sla): string {
		$roll = ($sla['rollToWorkingDay'] ?? 'none');
		if ($roll === false || $roll === null) {
			$roll = 'none';
		}

		if ($roll === true) {
			$roll = 'next';
		}

		$roll = (string)$roll;
		if (in_array($roll, self::ROLLS, true) === false) {
			throw new FlowTimerValidationException(
				message: sprintf(
					"rollToWorkingDay '%s' is refused: use one of %s.",
					$roll,
					implode(', ', self::ROLLS)
				)
			);
		}

		if ($roll !== 'none') {
			throw new FlowTimerValidationException(
				message: sprintf(
					'rollToWorkingDay "%s" cannot be explained: SlaCalculator has no roll, so the arm path would not apply one. '
					. 'A diagnostic that applied it here would print a moment the engine never produces.',
					$roll
				)
			);
		}

		return $roll;
	}//end validateRoll()
}//end class
