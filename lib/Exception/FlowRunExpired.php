<?php

/**
 * A flow run used up its runtime budget and was stopped.
 *
 * Distinct from an ordinary step failure: nothing was wrong with the work, the
 * run simply ran out of the time it was allowed. It is also distinct from the
 * stale reaper's verdict — the reaper speaks about a run it believes is DEAD,
 * this is thrown by the executor that is still very much alive and is choosing
 * to stop. Keeping the two apart is what stopped the engine reporting a run as
 * abandoned while that same run went on working for another twenty minutes.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-flow-stale-runs/specs/flow-stale-runs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use RuntimeException;

/**
 * Thrown at a checkpoint once the run's deadline has passed.
 */
class FlowRunExpired extends RuntimeException
{

}//end class
