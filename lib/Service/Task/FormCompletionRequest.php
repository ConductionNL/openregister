<?php

/**
 * A request to complete a task together with its form's field values.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

/**
 * The task, its completion answer and the declared field values written to
 * its subject, as {@see TaskFormCompletionInterface::complete()} takes them.
 *
 * @spec openspec/specs/flow-task-forms/spec.md#requirement-a-completion-payload-is-validated-by-the-lifecycle-input-allowlist-and-by-nothing-else
 */
final class FormCompletionRequest {

	/**
	 * Constructor.
	 *
	 * @param string               $uuid   The task uuid.
	 * @param TaskAnswer           $answer The completion outcome, result and comment.
	 * @param array<string, mixed> $data   The declared field values; empty for a form-less completion.
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly TaskAnswer $answer,
		public readonly array $data=[],
	) {
	}//end __construct()
}//end class
