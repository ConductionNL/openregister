<?php

/**
 * OpenRegister calendar write-back services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener\Support
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

namespace OCA\OpenRegister\Listener\Support;

use OCA\OpenRegister\Service\Task\TaskCalendarDocumentInterface;
use OCA\OpenRegister\Service\Task\TaskProjectionServiceInterface;

/**
 * The task projection side of a calendar write-back: which task a document
 * projects, and the reconciliation that rebuilds it.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-unauthorized-write-back-is-undone-and-explained
 */
final readonly class TaskVtodoWriteBackServices {
	/**
	 * Bundle the services.
	 *
	 * @param TaskProjectionServiceInterface $projections Failure-isolated reconciliation.
	 * @param TaskCalendarDocumentInterface  $documents   Reads which task a document projects.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-unauthorized-write-back-is-undone-and-explained
	 */
	public function __construct(
		public TaskProjectionServiceInterface $projections,
		public TaskCalendarDocumentInterface $documents,
	) {
	}//end __construct()
}//end class
