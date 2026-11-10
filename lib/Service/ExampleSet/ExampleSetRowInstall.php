<?php

/**
 * OpenRegister Example Set Row Install
 *
 * The install loop every kind of engine row shares: for each row a fragment
 * declares, decide whether this run writes it, updates the held one or keeps
 * it, fill and check it, write it, and report and record the outcome.
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

use Closure;

/**
 * Writes the rows of one fragment for one kind of engine row.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 */
final class ExampleSetRowInstall {
	/**
	 * Constructor.
	 *
	 * @param ExampleSetEngineRows $rows    The kind of row: its part, noun, write, update and revert.
	 * @param Closure              $refusal Why a filled row cannot be written in this run, or '': the kind's refusalIn(),
	 *                                      called with the filled fields, the set's accounts and the context.
	 * @param Closure              $fill    The context the kind's rows are filled in: the kind's fillContext().
	 * @param ExampleSetValues     $values  Fills placeholders.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ExampleSetEngineRows $rows,
		/** @var Closure(array<string, mixed>, array<int, string>, array<string, mixed>): string */
		private readonly Closure $refusal,
		/** @var Closure(array<string, mixed>): array<string, mixed> */
		private readonly Closure $fill,
		private readonly ExampleSetValues $values,
	) {
	}//end __construct()

	/**
	 * Write the rows of one fragment that the record does not hold, refresh or keep the held ones.
	 *
	 * @param ExampleSetFragmentWrite $write  The fragment and the context placeholders draw on; `refresh` asks for a refresh.
	 * @param RecordLedger            $ledger The report (gains the outcomes under the part and `dropped`) and the record
	 *                                        (gains the rows written under the part).
	 *
	 * @return bool Whether the record changed.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	public function fragment(ExampleSetFragmentWrite $write, RecordLedger $ledger): bool {
		$context = $write->context;
		$refresh = (($context['refresh'] ?? false) === true && in_array($this->rows->part(), ExampleSetEngineRows::REFRESHED, true) === true);
		$run     = new RowInstallRun(fragment: $write->fragment, context: $context, refresh: $refresh, ledger: $ledger);
		return $this->entries(run: $run);
	}//end fragment()

	/**
	 * Write, update or keep each declared row of the part.
	 *
	 * @param RowInstallRun $run The fragment, the context, whether this run refreshes the part, and the report and
	 *                           record.
	 *
	 * @return bool Whether the record changed.
	 */
	private function entries(RowInstallRun $run): bool {
		$changed = false;
		foreach (($run->fragment[$this->rows->part()] ?? []) as $entry) {
			if ($this->entry(run: $run, entry: $entry) === true) {
				$changed = true;
			}
		}

		return $changed;
	}//end entries()

	/**
	 * Write, update or keep one declared row.
	 *
	 * @param RowInstallRun        $run   The fragment, the context, whether this run refreshes the part, and the
	 *                                    report and record.
	 * @param array<string, mixed> $entry The declared row, `{key, data}`.
	 *
	 * @return bool Whether the record changed.
	 */
	private function entry(RowInstallRun $run, array $entry): bool {
		$step = new RowInstallStep(run: $run, row: $this->row(run: $run, entry: $entry));
		$run->ledger->report[$this->rows->part()][$step->row['name']] = 'kept';
		$mode = $this->mode(step: $step);
		if ($mode === 'skip') {
			return false;
		}

		return $this->keep(step: $step, made: $this->made(step: $step, mode: $mode));
	}//end entry()

	/**
	 * One declared row as the loop handles it: its record name, whether the record holds it and what it holds, and
	 * where it is for messages.
	 *
	 * @param RowInstallRun        $run   The fragment and the record.
	 * @param array<string, mixed> $entry The declared row, `{key, data}`.
	 *
	 * @return array{entry: array<string, mixed>, name: string, held: bool, earlier: array<string, mixed>, where: string}
	 */
	private function row(RowInstallRun $run, array $entry): array {
		$part   = $this->rows->part();
		$record = $run->ledger->record;
		$name   = $run->fragment['app'].'/'.$entry['key'];
		return [
			'entry'   => $entry,
			'name'    => $name,
			'held'    => isset($record[$part][$name]),
			'earlier' => ($record[$part][$name] ?? []),
			'where'   => $run->fragment['file'].': '.$this->rows->noun().' "'.$entry['key'].'"',
		];
	}//end row()

	/**
	 * What this run does with a row: `write` it, `update` the held one where it is, or `skip` a held one.
	 *
	 * @param RowInstallStep $step The run (whether it refreshes this part; its report gains a row that could not be
	 *                             taken away) and the row: whether it is `held`, its `earlier` entry and `where` it is.
	 *
	 * @return string
	 */
	private function mode(RowInstallStep $step): string {
		if ($step->row['held'] === false) {
			return 'write';
		}

		if ($step->run->refresh === true && $this->rows->updatesInPlace() === true) {
			return 'update';
		}

		if ($this->writesAgain(step: $step) === true) {
			return 'write';
		}

		return 'skip';
	}//end mode()

	/**
	 * Whether a row an earlier run wrote is written again: only on `--refresh`, after the old row is taken away; false
	 * (and named under `dropped`) when it could not be taken away, so the old row stays and is not written twice.
	 *
	 * @param RowInstallStep $step The run (whether it refreshes this part, and its report) and the row: its `earlier`
	 *                             entry and `where` it is.
	 *
	 * @return bool
	 */
	private function writesAgain(RowInstallStep $step): bool {
		if ($step->run->refresh === false) {
			return false;
		}

		if ($this->rows->revert(entry: $step->row['earlier']) === 'failed') {
			$step->run->ledger->report['dropped'][] = $step->row['where'].': not refreshed, the earlier one could not be taken away';
			return false;
		}

		return true;
	}//end writesAgain()

	/**
	 * Fill and check a row, then write it or update the held one; why not when it is refused or unresolved.
	 *
	 * @param RowInstallStep $step The run (the fragment, the context and the record's accounts) and the row: its
	 *                             declared `entry` and `earlier` entry.
	 * @param string         $mode `write` or `update`, from mode().
	 *
	 * @return array{error: string, entry: array<string, mixed>|null}
	 */
	private function made(RowInstallStep $step, string $mode): array {
		$run   = $step->run;
		$gaps  = [];
		$data  = (array) $this->values->fill(value: $step->row['entry']['data'], context: ($this->fill)($run->context), gaps: $gaps);
		$error = ($this->refusal)($data, array_keys($run->ledger->record['users']), $run->context);
		if ($gaps !== []) {
			$error = 'cannot resolve '.implode(', ', array_unique($gaps));
		}

		if ($error !== '') {
			return ['error' => $error, 'entry' => null];
		}

		$row = new ExampleSetRow(app: $run->fragment['app'], key: $step->row['entry']['key'], data: $data);
		if ($mode === 'update') {
			return $this->rows->update(entry: $step->row['earlier'], row: $row);
		}

		return $this->rows->write(row: $row);
	}//end made()

	/**
	 * Report a row's outcome and hold what was written.
	 *
	 * @param RowInstallStep                                         $step The run (its report and record) and the row:
	 *                                                                     its record `name`, whether it was `held` and
	 *                                                                     `where` it is.
	 * @param array{error: string, entry: array<string, mixed>|null} $made What made() did.
	 *
	 * @return bool Whether the record changed.
	 */
	private function keep(RowInstallStep $step, array $made): bool {
		$part   = $this->rows->part();
		$row    = $step->row;
		$ledger = $step->run->ledger;
		if ($made['entry'] === null) {
			$ledger->report['dropped'][] = $row['where'].': '.$made['error'];
			$ledger->report[$part][$row['name']] = 'failed';
			return false;
		}

		$ledger->record[$part][$row['name']] = $made['entry'];
		$ledger->report[$part][$row['name']] = ['created', 'refreshed'][(int) $row['held']];

		return true;
	}//end keep()
}//end class
