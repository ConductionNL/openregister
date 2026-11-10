<?php

/**
 * The lifecycle transition a task form fires on its subject object.
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

/**
 * Which object a task form transitions, and through which lifecycle action.
 *
 * @spec openspec/specs/flow-task-forms/spec.md#requirement-a-completion-payload-is-validated-by-the-lifecycle-input-allowlist-and-by-nothing-else
 */
final class TaskSubjectTransition {
	/**
	 * The transition.
	 *
	 * @param string $objectUuid The subject object.
	 * @param string $action The lifecycle action the form declares.
	 */
	public function __construct(
		public readonly string $objectUuid,
		public readonly string $action,
	) {
	}//end __construct()
}//end class
