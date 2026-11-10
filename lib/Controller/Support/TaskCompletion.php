<?php

/**
 * OpenRegister task domain
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

use OCA\OpenRegister\Service\Task\TaskFormCompletionInterface;
use OCA\OpenRegister\Service\Task\TaskFormResolverInterface;

/**
 * The domain services of the task API.
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */
final class TaskCompletion {
	/**
	 * Bundle the services.
	 *
	 * @param TaskFormResolverInterface   $forms      Resolves the form a task presents, per read.
	 * @param TaskFormCompletionInterface $completion Completes a task with a form payload,
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
	 */
	public function __construct(
		public readonly TaskFormResolverInterface $forms,
		public readonly TaskFormCompletionInterface $completion,
	) {
	}//end __construct()
}//end class
