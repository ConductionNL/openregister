<?php

/**
 * BulkJobReversalInterface: what callers outside the component use of BulkJobReversal.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\BulkJob
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

namespace OCA\OpenRegister\Service\BulkJob;

use InvalidArgumentException;
use OCA\OpenRegister\Db\BulkJob;
use OCA\OpenRegister\Exception\BulkJobRefusedException;

/**
 * Reverse a finished bulk job by replaying its undo records.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface BulkJobReversalInterface {

	/**
	 * Undo a job: a new job, over the same members, writing the prior values.
	 *
	 * Deliberately NOT a rollback. The inverse is an ordinary bulk job whose
	 * selection is the members the original actually wrote and whose
	 * per-member write is the recorded prior value, so there is one execution
	 * path, one ceiling, one preview, one audit shape, and the reversal is
	 * itself reversible (D-1).
	 *
	 * It is created, not committed. The caller previews it like any other job
	 * and commits it, which is what makes the members it will skip readable
	 * before anything is written.
	 *
	 * @param ReversalRequest $request The job to undo, who undoes it and the reason they typed.
	 *
	 * @return BulkJob The previewed reversal.
	 *
	 * @throws BulkJobRefusedException When the job cannot be undone, naming the reason.
	 * @throws InvalidArgumentException When the job names no register or schema, which the create path refuses.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function reverse(ReversalRequest $request): BulkJob;
}//end interface
