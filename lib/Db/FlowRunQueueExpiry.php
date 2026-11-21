<?php

/**
 * Which queued runs an expiry pass fails, and what it records on them.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-queue-fairness/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;

/**
 * One expiry pass over the run queue: the cut-off, the recorded reason, the batch cap.
 *
 * A run still queued since before `before` is failed with `reason` as its
 * error; at most `limit` runs are read per pass.
 *
 * @spec openspec/specs/flow-queue-fairness/spec.md
 */
final readonly class FlowRunQueueExpiry {
	/**
	 * Constructor.
	 *
	 * @param DateTime $before Runs queued before this moment are stale.
	 * @param string   $reason The error recorded on each expired run.
	 * @param integer  $limit  Maximum runs to expire in one pass.
	 *
	 * @return void
	 */
	public function __construct(
		public DateTime $before,
		public string $reason,
		public int $limit = 500,
	) {
	}//end __construct()
}//end class
