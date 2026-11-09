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
	 * @param Task|null            $task  The task.
	 * @param array<string, mixed> $row   The task's inbox row.
	 * @param array<string, mixed> $extra Extra fields the rules read.
	 *
	 * @return ObjectEntity
	 */
	public static function forTask(?Task $task = null, array $row = [], array $extra = []): ObjectEntity {
		return new TaskObjectAdapter(task: $task, row: $row, extra: $extra);
	}//end forTask()
}//end class
