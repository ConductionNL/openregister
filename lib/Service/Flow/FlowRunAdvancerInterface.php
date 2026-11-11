<?php

/**
 * FlowRunAdvancerInterface: the FlowRunAdvancer as the flow run worker reaches it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
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

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use Throwable;

/**
 * Advances one flow run, typed for the background worker that drains the queue.
 *
 * Declares only what callers outside the Service component use; the rest of the
 * class stays its own. Bound in ContractBindings to the same shared instance.
 */
interface FlowRunAdvancerInterface {

	/**
	 * Advance one run, never letting its failure stop a batch.
	 *
	 * @param FlowRun $run The run to advance.
	 * @param boolean $rethrow Whether to rethrow instead of swallowing. The
	 *                         worker swallows so one poisoned run cannot stop
	 *                         the queue; a synchronous caller wants the error.
	 *
	 * @return FlowRun The run, in whatever state it reached.
	 *
	 * @throws Throwable When $rethrow is true and the run could not be advanced.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 *
	 * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
	 */
	public function advance(FlowRun $run, bool $rethrow = false): FlowRun;
}//end interface
