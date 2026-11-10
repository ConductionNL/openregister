<?php

/**
 * CasePlanItemTimersInterface: what callers outside the Service component ask of CasePlanItemTimers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Event\FlowTimerFiredEvent;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerAct;

/**
 * Plan-item deadline timers, as the case item timer listener uses them. Only
 * the methods that caller calls.
 */
interface CasePlanItemTimersInterface {

	/**
	 * The item a fired timer asks to terminate.
	 *
	 * @param FlowTimerFiredEvent $event The fired timer.
	 *
	 * @return string|null The item uuid, or null.
	 */
	public function itemToTerminate(FlowTimerFiredEvent $event): ?string;

	/**
	 * Arm an item's timers as it enters a state.
	 *
	 * @param CaseItem     $item The item.
	 * @param FlowTimerAct $act  The identity that moved it and the clock.
	 *
	 * @return integer How many timers were armed.
	 */
	public function onEntered(CaseItem $item, FlowTimerAct $act): int;

	/**
	 * Cancel an item's timers as it ends.
	 *
	 * @param CaseItem $item The item.
	 *
	 * @return integer How many timers were cancelled.
	 */
	public function onTerminal(CaseItem $item): int;
}//end interface
