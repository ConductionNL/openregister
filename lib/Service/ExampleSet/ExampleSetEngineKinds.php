<?php

/**
 * OpenRegister Example Set Engine Kinds
 *
 * The kinds of row an example set writes outside the registers (tasks, saved
 * views), handled together by the installer and the remover: checked,
 * written, proved and deleted the same way. A kind this OpenRegister cannot
 * write refuses the rows that declare it, so nothing is skipped silently.
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
 * @spec openspec/changes/example-set-views/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * Tasks and saved views of an example set, together.
 *
 * @spec openspec/changes/example-set-views/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 */
class ExampleSetEngineKinds {
	/**
	 * Constructor.
	 *
	 * @param ExampleSetTasks|null $tasks Writes the tasks a fragment puts on its objects; null refuses them.
	 * @param ExampleSetViews|null $views Writes the saved views a fragment declares; null refuses them.
	 * @param ExampleSetWatchers|null $watchers Writes the follows a fragment declares; null refuses them.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ?ExampleSetTasks $tasks=null,
		private readonly ?ExampleSetViews $views=null,
		private readonly ?ExampleSetWatchers $watchers=null,
	) {
	}//end __construct()

	/**
	 * Check the tasks and views of one fragment.
	 *
	 * @param array<string, mixed> $fragment The fragment.
	 * @param array<string, mixed> $context  The context placeholders draw on.
	 * @param array<int, string>   $users    The account ids the set creates or adopts.
	 * @param ExampleSetValues     $values   Fills placeholders.
	 *
	 * @return array<int, string> Refusals, also for each row of a kind this OpenRegister cannot write.
	 *
	 * @spec openspec/changes/example-set-views/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	public function check(array $fragment, array $context, array $users, ExampleSetValues $values): array {
		$refusals = [];
		foreach ($this->kinds() as $part => [$rows, $noun]) {
			if ($rows === null) {
				foreach (($fragment[$part] ?? []) as $entry) {
					$refusals[] = $fragment['file'].': '.$noun.' "'.$entry['key'].'": this OpenRegister cannot write '.$part;
				}

				continue;
			}

			$refusals = array_merge($refusals, $rows->checkFragment(fragment: $fragment, context: $context, users: $users, values: $values));
		}

		return $refusals;
	}//end check()

	/**
	 * Write the tasks and views of one fragment that the record does not hold.
	 *
	 * @param array<string, mixed> $fragment The fragment.
	 * @param array<string, mixed> $context  The context placeholders draw on.
	 * @param array<string, mixed> $report   The report; gains `tasks`, `views` and `dropped`.
	 * @param array<string, mixed> $record   The record; gains the rows written.
	 * @param ExampleSetValues     $values   Fills placeholders.
	 *
	 * @return bool Whether the record changed and is to be written.
	 *
	 * @spec openspec/changes/example-set-views/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	public function install(array $fragment, array $context, array &$report, array &$record, ExampleSetValues $values): bool {
		$changed = false;
		foreach ($this->kinds() as [$rows]) {
			if ($rows?->installFragment(fragment: $fragment, context: $context, report: $report, record: $record, values: $values) === true) {
				$changed = true;
			}
		}

		return $changed;
	}//end install()

	/**
	 * Read back the tasks and views the record holds.
	 *
	 * @param array<string, mixed> $report The report so far.
	 * @param array<string, mixed> $record The record as written.
	 *
	 * @return array<string, mixed> The report with each row that is gone under `missing`.
	 *
	 * @spec openspec/changes/example-set-views/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	public function prove(array $report, array $record): array {
		foreach ($this->kinds() as $part => [$rows, $noun]) {
			foreach (($record[$part] ?? []) as $name => $entry) {
				if ($rows !== null && $rows->lost(entry: $entry) === true) {
					$report['missing'][] = $noun.' '.$name;
				}
			}
		}

		return $report;
	}//end prove()

	/**
	 * Delete the tasks and views the record holds, newest first.
	 *
	 * @param array<string, mixed> $record  The record; loses each row deleted.
	 * @param array<string, mixed> $report  The report; gains counts and failures.
	 * @param callable             $written Called after each deletion, so the record is kept.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-tasks/specs/example-sets/spec.md#requirement-remove-deletes-the-tasks-a-set-wrote
	 * @spec openspec/changes/example-set-views/specs/example-sets/spec.md#requirement-remove-deletes-the-views-a-set-wrote
	 */
	public function delete(array &$record, array &$report, callable $written): void {
		foreach ($this->kinds() as $part => [$rows, $noun]) {
			foreach (array_reverse(($record[$part] ?? []), true) as $name => $entry) {
				if ($rows === null) {
					$report['failed'][] = $noun.' '.$name.': this OpenRegister cannot delete '.$part;
					continue;
				}

				if ($rows->revert(entry: $entry) === 'failed') {
					$report['failed'][] = $noun.' '.$name;
					continue;
				}

				unset($record[$part][$name]);
				$report['removed'][$part] = (($report['removed'][$part] ?? 0) + 1);
				$written($record);
			}
		}
	}//end delete()

	/**
	 * Each kind by its part: the writer (null when absent) and the word for one row.
	 *
	 * @return array<string, array{0: ExampleSetEngineRows|null, 1: string}>
	 */
	private function kinds(): array {
		return ['tasks' => [$this->tasks, 'task'], 'views' => [$this->views, 'view'], 'watchers' => [$this->watchers, 'watcher']];
	}//end kinds()
}//end class
