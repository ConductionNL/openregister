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

use DateTimeInterface;

/**
 * Cancels the timers of a run or a subject and supersedes one timer with a new anchor. Implemented by FlowTimerService.
 */
interface FlowTimerControlInterface {

	/**
	 * Cancel every OPEN timer a run terminality reaches: the run as subject,
	 * or the run as provenance.
	 *
	 * @param string $runUuid The run uuid.
	 * @param string $reason Why, recorded on each timer.
	 * @param string|null $actor The propagation source recorded as actor.
	 * @param DateTimeInterface|null $now The clock.
	 *
	 * @return int How many timers were cancelled.
	 */
	public function cancelForRun(string $runUuid, string $reason, ?string $actor, ?DateTimeInterface $now = null): int;

	/**
	 * Cancel every OPEN timer of a subject, with a reason. Idempotent; never
	 * deletes; a recorded breach stays recorded. Runs in the CALLER's
	 * transaction so a terminality listener lands it in the same operation.
	 *
	 * @param string $subjectType The subject type.
	 * @param string $subjectUuid The subject uuid.
	 * @param string $reason Why, recorded on each timer.
	 * @param string|null $actor The propagation source recorded as actor.
	 * @param DateTimeInterface|null $now The clock.
	 *
	 * @return int How many timers were cancelled.
	 */
	public function cancelForSubject(
		string $subjectType,
		string $subjectUuid,
		string $reason,
		?string $actor,
		?DateTimeInterface $now = null,
	): int;

	/**
	 * The anchoring event moved: mark this timer superseded and arm a
	 * successor from the new anchor, carrying `consumed_value` forward and
	 * inheriting a fire row for every rung still in the past under the NEW
	 * deadline (design D-4). The superseded row never fires.
	 *
	 * @param string $uuid The timer to supersede.
	 * @param DateTimeInterface $anchorEventAt The new instant of the anchoring event.
	 * @param string $reason Why the anchor moved.
	 * @param string|null $actor The acting identity.
	 * @param DateTimeInterface|null $now The clock.
	 *
	 * @return FlowTimerInterface The successor.
	 *
	 * @throws \OCA\OpenRegister\Exception\FlowTimerStateException When the timer is not open.
	 */
	public function supersede(
		string $uuid,
		DateTimeInterface $anchorEventAt,
		string $reason,
		?string $actor,
		?DateTimeInterface $now = null,
	): FlowTimerInterface;
}//end interface
