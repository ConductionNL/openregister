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
	 * @param array<string, mixed> $fragment The fragment.
	 * @param array<string, mixed> $context  The context placeholders draw on; `refresh` asks for a refresh.
	 * @param array<string, mixed> $report   The report; gains the outcomes under the part and `dropped`.
	 * @param array<string, mixed> $record   The record; gains the rows written under the part.
	 *
	 * @return bool Whether the record changed.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	public function fragment(array $fragment, array $context, array &$report, array &$record): bool {
		$part    = $this->rows->part();
		$run     = [
			'fragment' => $fragment,
			'context'  => $context,
			'refresh'  => (($context['refresh'] ?? false) === true && in_array($part, ExampleSetEngineRows::REFRESHED, true) === true),
		];
		$changed = false;
		foreach (($fragment[$part] ?? []) as $entry) {
			if ($this->entry(run: $run, entry: $entry, report: $report, record: $record) === true) {
				$changed = true;
			}
		}

		return $changed;
	}//end fragment()

	/**
	 * Write, update or keep one declared row.
	 *
	 * @param array<string, mixed> $run    The fragment, the context and whether this run refreshes the part.
	 * @param array<string, mixed> $entry  The declared row, `{key, data}`.
	 * @param array<string, mixed> $report The report.
	 * @param array<string, mixed> $record The record.
	 *
	 * @return bool Whether the record changed.
	 */
	private function entry(array $run, array $entry, array &$report, array &$record): bool {
		$row = $this->row(fragment: $run['fragment'], entry: $entry, record: $record);
		$report[$this->rows->part()][$row['name']] = 'kept';
		$mode = $this->mode(row: $row, refresh: $run['refresh'], report: $report);
		if ($mode === 'skip') {
			return false;
		}

		$made = $this->made(run: $run, row: $row, mode: $mode, users: array_keys($record['users']));

		return $this->keep(row: $row, made: $made, report: $report, record: $record);
	}//end entry()

	/**
	 * One declared row as the loop handles it: its record name, whether the record holds it and what it holds, and
	 * where it is for messages.
	 *
	 * @param array<string, mixed> $fragment The fragment.
	 * @param array<string, mixed> $entry    The declared row, `{key, data}`.
	 * @param array<string, mixed> $record   The record.
	 *
	 * @return array{entry: array<string, mixed>, name: string, held: bool, earlier: array<string, mixed>, where: string}
	 */
	private function row(array $fragment, array $entry, array $record): array {
		$part = $this->rows->part();
		$name = $fragment['app'].'/'.$entry['key'];
		return [
			'entry'   => $entry,
			'name'    => $name,
			'held'    => isset($record[$part][$name]),
			'earlier' => ($record[$part][$name] ?? []),
			'where'   => $fragment['file'].': '.$this->rows->noun().' "'.$entry['key'].'"',
		];
	}//end row()

	/**
	 * What this run does with a row: `write` it, `update` the held one where it is, or `skip` a held one.
	 *
	 * @param array<string, mixed> $row     The row: whether it is `held`, its `earlier` entry and `where` it is.
	 * @param bool                 $refresh Whether this run refreshes this part.
	 * @param array<string, mixed> $report  The report; gains a row that could not be taken away.
	 *
	 * @return string
	 */
	private function mode(array $row, bool $refresh, array &$report): string {
		if ($row['held'] === false) {
			return 'write';
		}

		if ($refresh === true && $this->rows->updatesInPlace() === true) {
			return 'update';
		}

		if ($this->writesAgain(refresh: $refresh, row: $row, report: $report) === true) {
			return 'write';
		}

		return 'skip';
	}//end mode()

	/**
	 * Whether a row an earlier run wrote is written again: only on `--refresh`, after the old row is taken away; false
	 * (and named under `dropped`) when it could not be taken away, so the old row stays and is not written twice.
	 *
	 * @param bool                 $refresh Whether this run refreshes this part.
	 * @param array<string, mixed> $row     The row: its `earlier` entry and `where` it is.
	 * @param array<string, mixed> $report  The report.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-refresh-rewrites-every-object-the-set-declares
	 */
	private function writesAgain(bool $refresh, array $row, array &$report): bool {
		if ($refresh === false) {
			return false;
		}

		if ($this->rows->revert(entry: $row['earlier']) === 'failed') {
			$report['dropped'][] = $row['where'].': not refreshed, the earlier one could not be taken away';
			return false;
		}

		return true;
	}//end writesAgain()

	/**
	 * Fill and check a row, then write it or update the held one; why not when it is refused or unresolved.
	 *
	 * @param array<string, mixed> $run   The fragment and the context.
	 * @param array<string, mixed> $row   The row: its declared `entry` and `earlier` entry.
	 * @param string               $mode  `write` or `update`, from mode().
	 * @param array<int, string>   $users The account ids the set creates or adopts.
	 *
	 * @return array{error: string, entry: array<string, mixed>|null}
	 */
	private function made(array $run, array $row, string $mode, array $users): array {
		$gaps  = [];
		$data  = (array) $this->values->fill(value: $row['entry']['data'], context: ($this->fill)($run['context']), gaps: $gaps);
		$error = ($this->refusal)($data, $users, $run['context']);
		if ($gaps !== []) {
			$error = 'cannot resolve '.implode(', ', array_unique($gaps));
		}

		if ($error !== '') {
			return ['error' => $error, 'entry' => null];
		}

		if ($mode === 'update') {
			return $this->rows->update(entry: $row['earlier'], row: new ExampleSetRow(app: $run['fragment']['app'], key: $row['entry']['key'], data: $data));
		}

		return $this->rows->write(row: new ExampleSetRow(app: $run['fragment']['app'], key: $row['entry']['key'], data: $data));
	}//end made()

	/**
	 * Report a row's outcome and hold what was written.
	 *
	 * @param array<string, mixed>                                   $row    The row: its record `name`, whether it was `held` and `where` it is.
	 * @param array{error: string, entry: array<string, mixed>|null} $made   What made() did.
	 * @param array<string, mixed>                                   $report The report.
	 * @param array<string, mixed>                                   $record The record.
	 *
	 * @return bool Whether the record changed.
	 */
	private function keep(array $row, array $made, array &$report, array &$record): bool {
		$part = $this->rows->part();
		if ($made['entry'] === null) {
			$report['dropped'][] = $row['where'].': '.$made['error'];
			$report[$part][$row['name']] = 'failed';
			return false;
		}

		$record[$part][$row['name']] = $made['entry'];
		$report[$part][$row['name']] = ['created', 'refreshed'][(int) $row['held']];

		return true;
	}//end keep()
}//end class
