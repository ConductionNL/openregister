<?php

/**
 * TaskObjectAdapters: presents a task to the notification dispatcher as the object it notifies about.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Task;

/**
 * The task listeners hand one task at a time to the annotation dispatcher, which takes an
 * ObjectEntity. The adapter is built here, so code outside the Notifications component names
 * only ObjectEntity.
 */
final class TaskObjectAdapters {

	/**
	 * The task, presented as an object.
	 *
	 * @param Task|null               $task    The task.
	 * @param TaskAdapterOptions|null $options The task's inbox row and the extra fields the rules read.
	 *
	 * @return ObjectEntity
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-task-lifecycle-delivery-is-automatic-and-declarative
	 */
	public static function forTask(?Task $task = null, ?TaskAdapterOptions $options = null): ObjectEntity {
		$options ??= new TaskAdapterOptions();

		return new TaskObjectAdapter(task: $task, row: $options->row, extra: $options->extra);
	}//end forTask()
}//end class
