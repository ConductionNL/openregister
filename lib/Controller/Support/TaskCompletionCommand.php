<?php

/**
 * OpenRegister TaskCompletionCommand
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

use OCA\OpenRegister\Service\Task\TaskAnswer;

/**
 * A completion of one task as it was sent: the task, the answer and the
 * form field values (not yet checked to be an object).
 *
 * @spec openspec/specs/flow-task-forms/spec.md#requirement-a-completion-payload-is-validated-by-the-lifecycle-input-allowlist-and-by-nothing-else
 */
final class TaskCompletionCommand {
	/**
	 * Describe the completion.
	 *
	 * @param string     $uuid   The task uuid
	 * @param TaskAnswer $answer The outcome, result text and comment
	 * @param mixed      $data   The form field values as sent, or null
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly TaskAnswer $answer,
		public readonly mixed $data,
	) {
	}//end __construct()
}//end class
