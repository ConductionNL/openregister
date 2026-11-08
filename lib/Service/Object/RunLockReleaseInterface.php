<?php

/**
 * RunLockReleaseInterface: releasing the object locks a flow run holds.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
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

namespace OCA\OpenRegister\Service\Object;

use DateTime;

/**
 * What the flow run worker and the run-terminal listener need of RunLockRegistry.
 * Bound to RunLockRegistry in ContractBindings, so it resolves to the same shared instance.
 */
interface RunLockReleaseInterface {

	/**
	 * Release every lock a run holds. Idempotent.
	 *
	 * @param string $runUuid The run whose locks are released.
	 *
	 * @return int How many locks were released.
	 */
	public function releaseRunLocks(string $runUuid): int;

	/**
	 * Release locks whose holding run is terminal, gone, or expired.
	 *
	 * @param DateTime $now   The sweep's clock.
	 * @param int      $limit Batch ceiling.
	 *
	 * @return int How many locks were released.
	 */
	public function sweepOrphaned(DateTime $now, int $limit = 100): int;
}//end interface
