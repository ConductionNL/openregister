<?php

/**
 * FlowFiringCommitRun
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
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunStep;

/**
 * One firing being committed by {@see FlowRunCommit}: what fired, what the
 * pass releases, and the rows the transaction produced along the way.
 *
 * A run object (ADR-011 Rule 3): built by `commitFiring()`, read and filled by
 * its private helpers, and never handed outside `FlowRunCommit`.
 *
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
 */
final class FlowFiringCommitRun {

	/**
	 * The run row as locked inside the transaction; set once it is locked.
	 *
	 * @var FlowRun|null
	 */
	public ?FlowRun $locked = null;

	/**
	 * The step row the firing appended; set once it is written.
	 *
	 * @var FlowRunStep|null
	 */
	public ?FlowRunStep $step = null;

	/**
	 * Name the firing being committed.
	 *
	 * @param FlowFiring        $firing  What fired, on which stream, with what effect.
	 * @param FlowStreamRelease $release The claims the firing held, the pass token, and whether work stays enabled.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
	 */
	public function __construct(
		public readonly FlowFiring $firing,
		public readonly FlowStreamRelease $release,
	) {
	}//end __construct()
}//end class
