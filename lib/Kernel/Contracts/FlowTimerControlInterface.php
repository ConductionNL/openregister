<?php

/**
 * FlowTimerControlInterface: cancel and supersede the business timers of a flow.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Cancels the timers of a run or a subject, supersedes one timer with a new anchor, and moves a
 * subject's timers onto a new record type's calendar. Implemented by FlowTimerService.
 */
interface FlowTimerControlInterface {

	/**
	 * Cancel every OPEN timer a run terminality reaches: the run as subject,
	 * or the run as provenance.
	 *
	 * @param string      $runUuid The run uuid.
	 * @param TimerChange $change  Why, the propagation source recorded as actor, and the clock.
	 *
	 * @return int How many timers were cancelled.
	 */
	public function cancelForRun(string $runUuid, TimerChange $change): int;

	/**
	 * Cancel every OPEN timer of a subject, with a reason. Idempotent; never
	 * deletes; a recorded breach stays recorded. Runs in the CALLER's
	 * transaction so a terminality listener lands it in the same operation.
	 *
	 * @param TimerSubjectRef $subject The subject type and uuid.
	 * @param TimerChange     $change  Why, the propagation source recorded as actor, and the clock.
	 *
	 * @return int How many timers were cancelled.
	 */
	public function cancelForSubject(TimerSubjectRef $subject, TimerChange $change): int;

	/**
	 * The anchoring event moved: mark this timer superseded and arm a
	 * successor from the new anchor, carrying `consumed_value` forward and
	 * inheriting a fire row for every rung still in the past under the NEW
	 * deadline (design D-4). The superseded row never fires.
	 *
	 * @param TimerAnchorMove $move   The timer to supersede and the new instant of its anchoring event.
	 * @param TimerChange     $change Why the anchor moved, the acting identity, and the clock.
	 *
	 * @return FlowTimerInterface The successor.
	 *
	 * @throws \OCA\OpenRegister\Exception\FlowTimerStateException When the timer is not open.
	 */
	public function supersede(TimerAnchorMove $move, TimerChange $change): FlowTimerInterface;

	/**
	 * The subject moved to another record type: move every OPEN timer whose
	 * calendar its record type named onto the calendar the new one names. An
	 * armed timer is superseded with the change's reason and keeps its anchor;
	 * a suspended one is pointed at the new calendar in place. Timers whose
	 * calendar did not come from the record type are not touched.
	 *
	 * @param TimerSubjectRef $subject    The subject type and uuid.
	 * @param string          $recordType The schema the subject now has.
	 * @param TimerChange     $change     Why, the acting identity, and the clock.
	 *
	 * @return int How many timers changed calendar.
	 *
	 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-an-object-can-move-between-registers-and-schemas-without-changing-identity
	 */
	public function recalendarForSubject(TimerSubjectRef $subject, string $recordType, TimerChange $change): int;
}//end interface
