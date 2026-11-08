<?php

/**
 * TermDiagnosticInterface: what callers outside the component use of TermDiagnostic.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Timer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use DateTimeInterface;
use OCA\OpenRegister\Exception\FlowTimerValidationException;

/**
 * The timer term explanation the timer diagnostic endpoint returns.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface TermDiagnosticInterface {

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
	): array;
}//end interface
