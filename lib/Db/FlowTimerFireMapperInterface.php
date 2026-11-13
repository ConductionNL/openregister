<?php

/**
 * FlowTimerFireMapperInterface: what callers outside the component use of FlowTimerFireMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
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

namespace OCA\OpenRegister\Db;

use OCP\DB\Exception as DbException;

/**
 * The rung-fire ledger the flow timer service claims rungs in.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own. Bound to FlowTimerFireMapper.
 */
interface FlowTimerFireMapperInterface {

	/**
	 * CLAIM a rung: insert its ledger row, or report that another pass owns it.
	 *
	 * @param FlowTimerFire $fire The row to insert; its (timer_uuid, rung_key) is the claim.
	 *
	 * @return FlowTimerFire|null The inserted row, or null when the unique index refused it.
	 *
	 * @throws DbException On any failure other than the unique-constraint violation.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
	 */
	public function claim(FlowTimerFire $fire): ?FlowTimerFire;

	/**
	 * Every fire row of a timer, oldest first.
	 *
	 * @param string $timerUuid The timer uuid.
	 *
	 * @return array<int, FlowTimerFire> The fire rows.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
	 */
	public function findByTimer(string $timerUuid): array;
}//end interface
