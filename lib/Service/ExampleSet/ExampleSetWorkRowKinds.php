<?php

/**
 * OpenRegister Example Set Work Row Kinds
 *
 * The writers of the work an example set puts on its objects: tasks, notes, read states and follows.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * The writers of the work on a set's objects, bundled for the engine kinds.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 */
final readonly class ExampleSetWorkRowKinds {
	/**
	 * Bundle the writers; a writer left out refuses its rows.
	 *
	 * @param ExampleSetTasks|null      $tasks      Writes the tasks a fragment puts on its objects; null refuses them.
	 * @param ExampleSetNotes|null      $notes      Writes the notes a fragment leaves on its objects; null refuses them.
	 * @param ExampleSetReadStates|null $readStates Writes the objects a fragment's accounts opened; null refuses them.
	 * @param ExampleSetWatchers|null   $watchers   Writes the follows a fragment declares; null refuses them.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	public function __construct(
		public ?ExampleSetTasks $tasks=null,
		public ?ExampleSetNotes $notes=null,
		public ?ExampleSetReadStates $readStates=null,
		public ?ExampleSetWatchers $watchers=null,
	) {
	}//end __construct()

	/**
	 * The writers by fragment part; null where this OpenRegister has none.
	 *
	 * @return array<string, ExampleSetEngineRows|null>
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	public function writers(): array {
		return [
			'tasks'      => $this->tasks,
			'notes'      => $this->notes,
			'readStates' => $this->readStates,
			'watchers'   => $this->watchers,
		];
	}//end writers()
}//end class
