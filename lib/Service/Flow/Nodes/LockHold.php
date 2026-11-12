<?php

/**
 * The lock one lock step takes: who holds it, for which run and process, and for how long.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-step-takes-a-lock-or-parks-the-run-and-retries
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCP\IUser;

/**
 * The terms of the lock a {@see LockObjectNode} firing takes on each target.
 *
 * The holder is the run's acting identity, so a lock is always attributable;
 * the run uuid is what a later unlock step or the run's end releases by.
 *
 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-step-takes-a-lock-or-parks-the-run-and-retries
 */
final class LockHold {

	/**
	 * Name the hold.
	 *
	 * @param IUser  $owner    The run's acting identity the lock is taken as.
	 * @param string $runUuid  The run the lock belongs to.
	 * @param string $process  What the lock is for, shown to anyone whose write is refused.
	 * @param int    $duration How long the lock survives if the run never releases it, in seconds.
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-step-takes-a-lock-or-parks-the-run-and-retries
	 */
	public function __construct(
		public readonly IUser $owner,
		public readonly string $runUuid,
		public readonly string $process,
		public readonly int $duration,
	) {
	}//end __construct()
}//end class
