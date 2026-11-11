<?php

/**
 * TaskFormResolverInterface: what callers outside the Service component ask of TaskFormResolver.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
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

namespace OCA\OpenRegister\Service\Task;

use OCA\OpenRegister\Kernel\Contracts\TaskInterface;

/**
 * Resolving the form a task renders, as the task controller uses it. Only the
 * method that caller calls.
 */
interface TaskFormResolverInterface {

	/**
	 * Describe the form a task renders and whether its checklist is required.
	 *
	 * @param TaskInterface $task The task.
	 *
	 * @return array{form: array<string, mixed>|null, requireChecklist: bool} The description.
	 */
	public function describe(TaskInterface $task): array;
}//end interface
