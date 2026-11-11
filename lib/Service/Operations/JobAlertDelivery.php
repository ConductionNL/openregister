<?php

/**
 * JobAlertDelivery: the collaborators that deliver a failure alert.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Operations
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/operations-console/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Operations;

use OCP\IGroupManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Where a failure alert goes, who receives it, and where a failed delivery is reported.
 *
 * @spec openspec/specs/operations-console/spec.md#requirement-a-recurring-jobs-schedule-is-administered-and-failures-raise-an-alert-req-aoc-003
 */
final readonly class JobAlertDelivery {

	/**
	 * Bundle the collaborators.
	 *
	 * @param INotificationManager $notifications Where the alert is delivered.
	 * @param IGroupManager        $groups        Resolves who the administrators are.
	 * @param LoggerInterface      $logger        Where a failed delivery is reported.
	 */
	public function __construct(
		public INotificationManager $notifications,
		public IGroupManager $groups,
		public LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
