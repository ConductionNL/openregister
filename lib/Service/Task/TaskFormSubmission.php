<?php

/**
 * The field values an actor submits with a task's form.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-task-forms/spec.md#requirement-a-completion-payload-is-validated-by-the-lifecycle-input-allowlist-and-by-nothing-else
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use OCA\OpenRegister\Db\Task;

/**
 * One submission of a task form: the opened task, the submitted values and
 * who submitted them.
 *
 * @spec openspec/specs/flow-task-forms/spec.md#requirement-a-completion-payload-is-validated-by-the-lifecycle-input-allowlist-and-by-nothing-else
 */
final class TaskFormSubmission {
	/**
	 * The submission.
	 *
	 * @param Task $task The task, already opened for completion.
	 * @param array<string, mixed> $data The submitted field values.
	 * @param string|null $actor The submitting identity.
	 */
	public function __construct(
		public readonly Task $task,
		public readonly array $data,
		public readonly ?string $actor,
	) {
	}//end __construct()
}//end class
