<?php

/**
 * What turns boundary data into a task: the builder and the checks on its form, subject and edits.
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
 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

/**
 * Validates and shapes task data before a verb writes it: the builder with
 * its vocabularies, the form reader, the subject locator and the editor.
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-a-task-is-a-first-class-record-not-a-flow-artefact
 */
final class TaskIntake {
	/**
	 * Bundle the intake collaborators.
	 *
	 * @param TaskBuilder $builder Validates and builds a new task from
	 *                             boundary data (the vocabularies live there).
	 * @param TaskFormReader|null $forms Refuses a run-less task's own form
	 *                                   declaration at creation; absent, a
	 *                                   record declaration is stored
	 *                                   unchecked and judged on read.
	 * @param TaskSubjectLocator|null $locator Records the subject's register
	 *                                         and schema when the caller did
	 *                                         not; absent, tasks are written
	 *                                         as given.
	 * @param TaskEdit|null $editor Validates and applies an edit of a task's
	 *                              own fields; absent, a plain one.
	 */
	public function __construct(
		public readonly TaskBuilder $builder,
		public readonly ?TaskFormReader $forms = null,
		public readonly ?TaskSubjectLocator $locator = null,
		public readonly ?TaskEdit $editor = null,
	) {
	}//end __construct()
}//end class
