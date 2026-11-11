<?php

/**
 * A place claim a dead worker pass left behind.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob\Support;

use DateTime;
use OCA\OpenRegister\Db\FlowClaim;

/**
 * One abandoned claim as the claim reaper handles it: the claim, the run and
 * branch it held, the step it was taken at, and the error the branch and run
 * are failed with.
 *
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-branch-abandoned-by-a-crashed-worker-must-be-recovered-and-must-not-be-silently-re-run
 */
final class AbandonedFlowClaim {

	/**
	 * The pass clock.
	 *
	 * @var DateTime
	 */
	public readonly DateTime $now;

	/**
	 * The run the claim belongs to.
	 *
	 * @var string
	 */
	public readonly string $runUuid;

	/**
	 * The stream (branch) the claim held, '' for none.
	 *
	 * @var string
	 */
	public readonly string $branch;

	/**
	 * The step the claim was taken at, '' for none.
	 *
	 * @var string
	 */
	public readonly string $transition;

	/**
	 * The error the branch and run are failed with.
	 *
	 * @var string
	 */
	public readonly string $error;

	/**
	 * Constructor.
	 *
	 * @param FlowClaim   $claim The abandoned claim.
	 * @param FlowReapRun $reap  The reap it was found by.
	 */
	public function __construct(
		public readonly FlowClaim $claim,
		FlowReapRun $reap,
	) {
		$this->now = $reap->now;
		$this->runUuid = (string)$claim->getRunUuid();
		$this->branch = (string)($claim->getStreamId() ?? '');
		$this->transition = (string)($claim->getTransition() ?? '');
		$this->error = sprintf(
			'Abandoned branch: worker pass "%s" took stream "%s" at step "%s" and never finished it '
			. '(no commit for over %d minutes). The step may have performed its side effect; '
			. 'it was NOT re-run.',
			(string)$claim->getOwner(),
			$this->branch,
			$this->transition,
			$reap->minutes
		);
	}//end __construct()
}//end class
