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
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * Tasks and saved views of an example set, together.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 */
class ExampleSetEngineKinds {
	/**
	 * The parts written after a fragment's objects and before its changes, so a change may quote one
	 * (`{{flow:key.uuid}}`, `{{accessLink:key.url}}`).
	 */
	public const BEFORE_CHANGES = ['flows', 'accessLinks', 'grants'];

	/**
	 * Constructor; a writer a bundle leaves out refuses its rows.
	 *
	 * @param ExampleSetWorkRowKinds    $work    Writes the tasks, notes, read states and follows.
	 * @param ExampleSetOwnRowKinds     $own     Writes the saved views, flows and case plans.
	 * @param ExampleSetLinkRowKinds    $links   Writes the party links, email links, access links and grants.
	 * @param ExampleSetAppAndHistoryRowKinds $elsewhere Writes the rows in other apps and in the history.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ExampleSetWorkRowKinds $work=new ExampleSetWorkRowKinds(),
		private readonly ExampleSetOwnRowKinds $own=new ExampleSetOwnRowKinds(),
		private readonly ExampleSetLinkRowKinds $links=new ExampleSetLinkRowKinds(),
		private readonly ExampleSetAppAndHistoryRowKinds $elsewhere=new ExampleSetAppAndHistoryRowKinds(),
	) {
	}//end __construct()

	/**
	 * Check the tasks and views of one fragment.
	 *
	 * @param ExampleSetFragmentWrite $write The fragment, the context placeholders draw on and their filler.
	 * @param array<int, string>      $users The account ids the set creates or adopts.
	 *
	 * @return array<int, string> Refusals, also for each row of a kind this OpenRegister cannot write.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	public function check(ExampleSetFragmentWrite $write, array $users): array {
		$fragment         = $write->fragment;
		$refusals         = $this->addressRefusals(fragment: $fragment);
		$context          = $write->context;
		$context['tasks'] = $write->values->tasksOf(fragment: $fragment, record: null);
		$write            = new ExampleSetFragmentWrite(fragment: $fragment, context: $context, values: $write->values);
		return array_merge($refusals, $this->kindRefusals(write: $write, users: $users));
	}//end check()

	/**
	 * Each kind's refusals of one fragment's rows, in the order of the kinds; one per row of a kind this
	 * OpenRegister cannot write.
	 *
	 * @param ExampleSetFragmentWrite $write The fragment, the context placeholders draw on (with its `tasks`) and their
	 *                                       filler.
	 * @param array<int, string>      $users The account ids the set creates or adopts.
	 *
	 * @return array<int, string>
	 */
	private function kindRefusals(ExampleSetFragmentWrite $write, array $users): array {
		$refusals = [];
		foreach ($this->kinds() as $part => [$rows]) {
			if ($rows === null) {
				$refusals = array_merge($refusals, $this->absentRefusals(fragment: $write->fragment, part: $part));
				continue;
			}

			$refusals = array_merge(
				$refusals,
				$rows->checkFragment(
					fragment: $write->fragment,
					check: new ExampleSetFragmentCheck(context: $write->context, users: $users, values: $write->values)
				)
			);
		}

		return $refusals;
	}//end kindRefusals()

	/**
	 * One refusal per row of a part this OpenRegister has no writer for.
	 *
	 * @param array<string, mixed> $fragment The fragment.
	 * @param string               $part     The part.
	 *
	 * @return array<int, string>
	 */
	private function absentRefusals(array $fragment, string $part): array {
		$noun     = $this->kinds()[$part][1];
		$refusals = [];
		foreach (($fragment[$part] ?? []) as $entry) {
			$refusals[] = $fragment['file'].': '.$noun.' "'.$entry['key'].'": this OpenRegister cannot write '.$part;
		}

		return $refusals;
	}//end absentRefusals()

	/**
	 * Where a fragment quotes an access link's address (`{{accessLink:key.url}}`) other than as the text of a change's
	 * `set` field: only there does the record keep it as a fingerprint, so anywhere else it is refused.
	 *
	 * @param array<string, mixed> $fragment The fragment.
	 *
	 * @return array<int, string> One refusal per part that quotes it elsewhere.
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-change-may-quote-an-access-link-of-its-fragment
	 */
	private function addressRefusals(array $fragment): array {
		$parts = array_diff_key($fragment, ['app' => true, 'file' => true, 'dir' => true, 'changes' => true]);
		foreach ((array) ($fragment['changes'] ?? []) as $index => $change) {
			$parts['changes'][$index] = $this->withoutSetText(change: (array) $change);
		}

		$refusals = [];
		foreach ($parts as $part => $declared) {
			if ($this->quotesAddress(value: $declared) === true) {
				$refusals[] = $fragment['file'].': '.$part.' quotes an access link address ({{accessLink:key.url}}); '
					.'only a change may, as the text of a set field';
			}
		}

		return $refusals;
	}//end addressRefusals()

	/**
	 * A change without the text of its `set` fields, the one place an access link's address may be quoted.
	 *
	 * @param array<string, mixed> $change The declared change.
	 *
	 * @return array<string, mixed> The change; `set` keeps only its fields that are not text.
	 */
	private function withoutSetText(array $change): array {
		$kept = array_diff_key($change, ['set' => true]);
		foreach ((array) ($change['set'] ?? []) as $path => $value) {
			if (is_string($value) === false) {
				$kept['set'][$path] = $value;
			}
		}

		return $kept;
	}//end withoutSetText()

	/**
	 * Whether a declared value holds `{{accessLink:key.url}}` anywhere inside it.
	 *
	 * @param mixed $value The declared value.
	 *
	 * @return bool
	 */
	private function quotesAddress(mixed $value): bool {
		if (is_array($value) === true) {
			return $this->anyQuotesAddress(values: $value);
		}

		return is_string($value) === true && preg_match('/\{\{\s*accessLink:[^}]*\.url\s*\}\}/', $value) === 1;
	}//end quotesAddress()

	/**
	 * Whether any key or item of a declared list or map holds `{{accessLink:key.url}}`.
	 *
	 * @param array<mixed> $values The declared list or map.
	 *
	 * @return bool
	 */
	private function anyQuotesAddress(array $values): bool {
		foreach ($values as $key => $item) {
			if ($this->quotesAddress(value: $key) === true || $this->quotesAddress(value: $item) === true) {
				return true;
			}
		}

		return false;
	}//end anyQuotesAddress()

	/**
	 * Write the rows of one fragment that the record does not hold, but for the parts installBeforeChanges() wrote:
	 * the tasks and views among them.
	 *
	 * @param ExampleSetFragmentWrite $write  The fragment, the context placeholders draw on and their filler.
	 * @param RecordLedger            $ledger The report (gains `tasks`, `views` and `dropped`) and the record (gains
	 *                                        the rows written), held by reference.
	 *
	 * @return bool Whether the record changed and is to be written.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	public function install(ExampleSetFragmentWrite $write, RecordLedger $ledger): bool {
		$changed = false;
		foreach ($this->kinds() as $part => [$rows]) {
			// Written earlier, by installBeforeChanges(), so a change may quote one.
			if (in_array($part, self::BEFORE_CHANGES, true) === true) {
				continue;
			}

			if ($rows?->installFragment(write: $write, ledger: $ledger) === true) {
				$changed = true;
			}

			// The parts after the tasks may quote one (`{{task:key.uuid}}`).
			if ($part === 'tasks') {
				$write = $write->withTasksOf(record: $ledger->record);
			}
		}

		return $changed;
	}//end install()

	/**
	 * Write the flows, access links and grants of one fragment that the record does not hold, before its changes, so
	 * a change may quote one (`{{flow:key.uuid}}`, `{{accessLink:key.url}}`).
	 *
	 * @param ExampleSetFragmentWrite $write  The fragment, the context placeholders draw on and their filler.
	 * @param RecordLedger            $ledger The report (gains `flows`, `accessLinks` and `grants`) and the record
	 *                                        (gains the rows written), held by reference.
	 *
	 * @return bool Whether the record changed and is to be written.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-change-may-quote-a-flow-of-its-fragment
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-change-may-quote-an-access-link-of-its-fragment
	 */
	public function installBeforeChanges(ExampleSetFragmentWrite $write, RecordLedger $ledger): bool {
		$changed = false;
		$kinds   = $this->kinds();
		foreach (self::BEFORE_CHANGES as $part) {
			if ($kinds[$part][0]?->installFragment(write: $write, ledger: $ledger) === true) {
				$changed = true;
			}
		}

		return $changed;
	}//end installBeforeChanges()

	/**
	 * The access links a fragment declares, by key, for `{{accessLink:key.id|uuid|url}}`: as minted, or stand-ins
	 * for the check (`$record` null).
	 *
	 * @param array<string, mixed>      $fragment The fragment.
	 * @param array<string, mixed>|null $record   The set's record, or null for the check.
	 *
	 * @return array<string, array{id: int, uuid: string, url: string}> None when this OpenRegister cannot mint links.
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-change-may-quote-an-access-link-of-its-fragment
	 */
	public function accessLinksOf(array $fragment, ?array $record): array {
		return ($this->links->accessLinks?->placeholders(fragment: $fragment, record: $record) ?? []);
	}//end accessLinksOf()

	/**
	 * Read back the tasks and views the record holds.
	 *
	 * @param array<string, mixed> $report The report so far.
	 * @param array<string, mixed> $record The record as written.
	 *
	 * @return array<string, mixed> The report with each row that is gone under `missing`.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
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
	 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-the-tasks-a-set-wrote
	 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-the-views-a-set-wrote
	 */
	public function delete(array &$record, array &$report, callable $written): void {
		$this->deleteHeld(ledger: new RecordLedger(report: $report, record: $record), written: $written);
	}//end delete()

	/**
	 * Delete the rows the record holds, newest first.
	 *
	 * @param RecordLedger $ledger  The record, which loses each row deleted, and the report, which gains counts and
	 *                              failures; held by reference.
	 * @param callable     $written Called after each deletion, so the record is kept.
	 *
	 * @return void
	 */
	private function deleteHeld(RecordLedger $ledger, callable $written): void {
		foreach ($this->kinds() as $part => [$rows, $noun]) {
			foreach (array_reverse(($ledger->record[$part] ?? []), true) as $name => $entry) {
				$failure = $this->failure(outcome: $rows?->revert(entry: $entry), part: $part);
				if ($failure !== null) {
					$ledger->report['failed'][] = $noun.' '.$name.$failure;
					continue;
				}

				unset($ledger->record[$part][$name]);
				$ledger->report['removed'][$part] = (($ledger->report['removed'][$part] ?? 0) + 1);
				$written($ledger->record);
			}
		}
	}//end deleteHeld()

	/**
	 * What follows a row's name under `failed` when deleting it did not take it away, or null when it is gone.
	 *
	 * @param string|null $outcome What the part's writer's revert() answered; null when this OpenRegister has none.
	 * @param string      $part    The part.
	 *
	 * @return string|null
	 */
	private function failure(?string $outcome, string $part): ?string {
		if ($outcome === null) {
			return ': this OpenRegister cannot delete '.$part;
		}

		if ($outcome === 'failed') {
			return '';
		}

		return null;
	}//end failure()

	/**
	 * Each kind by its part, in the order its rows are checked, written, proved and deleted: the writer (null when
	 * absent) and the word for one row. The parts after the tasks may quote one (`{{task:key.uuid}}`).
	 *
	 * @return array<string, array{0: ExampleSetEngineRows|null, 1: string}>
	 */
	private function kinds(): array {
		$writers = ($this->work->writers() + $this->own->writers() + $this->links->writers()
			+ $this->elsewhere->writers());
		$kinds   = [];
		foreach (ExampleSetFragmentShape::KEYED_ROWS as $part => $noun) {
			$kinds[$part] = [$writers[$part], $noun];
			// The archival setting is a part too, written right after the exports, but not a list of keyed rows.
			if ($part === 'exportRuns') {
				$kinds['archival'] = [$writers['archival'], 'archival setting'];
			}
		}

		return $kinds;
	}//end kinds()
}//end class
