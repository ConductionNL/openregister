<?php
/**
 * NotificationRunRecorderInterface: records one notification rule evaluation.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Notifications
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/automation-run-records/design.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Notifications;

/**
 * Stores the automation run record of one notification rule evaluation.
 *
 * The notification dispatcher (Notifications) reports; the automation record
 * writer (Processes) stores and is bound to this contract in its own registrar.
 *
 * @spec openspec/changes/automation-run-records/design.md
 */
interface NotificationRunRecorderInterface {

	/**
	 * Record one evaluated rule. A failure is logged, never thrown.
	 *
	 * @param NotificationRun $run The evaluated rule.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/automation-run-records/design.md
	 */
	public function recordNotificationRun(NotificationRun $run): void;
}//end interface
