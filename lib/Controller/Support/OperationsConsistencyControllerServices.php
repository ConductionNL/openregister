<?php

/**
 * OpenRegister operations-consistency controller services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Service\Operations\ConsistencyCheckServiceInterface;
use OCA\OpenRegister\Service\Operations\ConsistencyRepairServiceInterface;
use OCP\IUserSession;

/**
 * The services of OperationsConsistencyController, bundled so its constructor stays small.
 *
 * @spec openspec/specs/operations-console/spec.md#requirement-the-instance-checks-its-own-data-and-repairs-it-as-a-separate-act-req-aoc-005
 */
final class OperationsConsistencyControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ConsistencyCheckServiceInterface  $check  The read-only consistency check.
	 * @param ConsistencyRepairServiceInterface $repair The repair, as a separate act.
	 * @param IUserSession                      $userSession The administrator acting.
	 */
	public function __construct(
		public readonly ConsistencyCheckServiceInterface $check,
		public readonly ConsistencyRepairServiceInterface $repair,
		public readonly IUserSession $userSession,
	) {
	}//end __construct()
}//end class
