<?php

/**
 * TaskNotificationRulesInterface: the public surface of TaskNotificationRules, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Notifications
 * @package  OCA\OpenRegister\Notifications\Service\Notification
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

namespace OCA\OpenRegister\Notifications\Service\Notification;

use OCA\OpenRegister\Db\Schema;

/**
 * Every public method of TaskNotificationRules. Callers depend on this interface, not on the class.
 */
interface TaskNotificationRulesInterface {

	/**
	 * The transition action the write-back gate records on a refusal, so the
	 * refusal notice is a rule like every other task notification.
	 */
	public const ACTION_WRITE_BACK_REFUSED = 'write-back-refused';

	/**
	 * A synthetic Schema carrying the rules, for dispatchWithSchema().
	 *
	 * @return Schema The synthetic schema.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-task-lifecycle-delivery-is-automatic-and-declarative
	 */
	public function buildSchema(): Schema;
}//end interface
