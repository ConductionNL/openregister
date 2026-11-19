<?php
/**
 * CurrentFlowRunInterface: which flow run, if any, the write being made belongs to.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

/**
 * The uuid of the flow run that is executing now, or null when a person is writing.
 *
 * The object write path (Core) asks, so a run-scoped lock admits the run that took
 * it; the flow engine (Processes) answers from its shared attribution stack and
 * binds that stack to this port in its own registrar.
 */
interface CurrentFlowRunInterface {

	/**
	 * The uuid of the run currently executing, or null outside any run.
	 *
	 * WHY A WRITE GUARD ASKS THIS. A run-scoped lock refuses every caller but
	 * the holding run — the run's own `runAs` user included, which is the
	 * point. A guard that cannot name the caller's run therefore refuses the
	 * run that took the lock: the flow locks a case and is then refused by its
	 * own lock at the next write. This is the seam that answers "which run is
	 * writing", and it is ambient for the same reason attribution is: the
	 * write may be several calls deep inside a leaf app that has never heard
	 * of flows.
	 *
	 * Null outside a run, and null for a hop that is not attributable. Both
	 * read as "a person is writing", which is the FAIL-CLOSED direction: a run
	 * lock refuses a caller with no run uuid.
	 *
	 * @return string|null The run uuid, or null.
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-ownership-is-decided-by-one-predicate
	 */
	public function currentRunUuid(): ?string;
}//end interface
