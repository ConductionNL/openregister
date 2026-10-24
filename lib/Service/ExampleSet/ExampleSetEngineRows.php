<?php

/**
 * OpenRegister Example Set Engine Rows
 *
 * The rows an example set writes outside the registers, through one of
 * OpenRegister's own services: tasks in the task engine, saved views. Each
 * kind is a fragment part of its own (`tasks`, `views`), checked before
 * anything is written, written once, proved and removed the same way.
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
 * One kind of row an example set writes through an OpenRegister service: the
 * check and install loops are shared, each kind says how one row is refused,
 * written, proved and deleted.
 *
 * @spec openspec/changes/example-set-views/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 */
abstract class ExampleSetEngineRows {
	/**
	 * The parts `--refresh` writes again: a saved view, a task, a note and a read state carry values that age.
	 */
	public const REFRESHED = ['views', 'tasks', 'notes', 'readStates'];

	/**
	 * The fragment part and record part this kind lives in (`tasks`, `views`).
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-set-views/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	abstract public function part(): string;

	/**
	 * The word for one row, in messages (`task`, `view`).
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-set-views/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	abstract public function noun(): string;

	/**
	 * Why a filled row cannot be written, or ''.
	 *
	 * @param array<string, mixed> $data  The filled fields.
	 * @param array<int, string>   $users The account ids the set creates or adopts.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-set-views/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	abstract public function refusal(array $data, array $users): string;

	/**
	 * Write one row.
	 *
	 * @param string               $app  The app that ships the fragment.
	 * @param string               $key  The row's key in the fragment.
	 * @param array<string, mixed> $data The filled fields.
	 *
	 * @return array{error: string, entry: array{uuid: string}|null} The record entry, or why the write failed.
	 *
	 * @spec openspec/changes/example-set-views/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	abstract public function write(string $app, string $key, array $data): array;

	/**
	 * Whether a written row is no longer there.
	 *
	 * @param array<string, mixed> $entry A record entry from write().
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/example-set-views/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	abstract public function lost(array $entry): bool;

	/**
	 * Delete a row the set wrote.
	 *
	 * @param array<string, mixed> $entry A record entry from write().
	 *
	 * @return string `removed` (also when it was already gone) or `failed`.
	 *
	 * @spec openspec/changes/example-set-views/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	abstract public function revert(array $entry): string;

	/**
	 * Check the rows of one fragment: each fills and passes refusal().
	 *
	 * @param array<string, mixed> $fragment The fragment.
	 * @param array<string, mixed> $context  The context placeholders draw on.
	 * @param array<int, string>   $users    The account ids the set creates or adopts.
	 * @param ExampleSetValues     $values   Fills placeholders.
	 *
	 * @return array<int, string> Refusals.
	 *
	 * @spec openspec/changes/example-set-views/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	public function checkFragment(array $fragment, array $context, array $users, ExampleSetValues $values): array {
		$refusals = [];
		foreach (($fragment[$this->part()] ?? []) as $entry) {
			$gaps = [];
			$data = (array) $values->fill(value: $entry['data'], context: $context, gaps: $gaps);
			$why  = $this->refusal(data: $data, users: $users);
			if ($gaps !== []) {
				$why = 'cannot resolve '.implode(', ', array_unique($gaps));
			}

			if ($why !== '') {
				$refusals[] = $fragment['file'].': '.$this->noun().' "'.$entry['key'].'": '.$why;
			}
		}

		return $refusals;
	}//end checkFragment()

	/**
	 * Write the rows of one fragment that the record does not hold.
	 *
	 * @param array<string, mixed> $fragment The fragment.
	 * @param array<string, mixed> $context  The context placeholders draw on.
	 * @param array<string, mixed> $report   The report; gains the outcomes under part() and `dropped`.
	 * @param array<string, mixed> $record   The record; gains the rows written under part().
	 * @param ExampleSetValues     $values   Fills placeholders.
	 *
	 * @return bool Whether the record changed and is to be written.
	 *
	 * @spec openspec/changes/example-set-views/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	public function installFragment(array $fragment, array $context, array &$report, array &$record, ExampleSetValues $values): bool {
		$part    = $this->part();
		$changed = false;
		$refresh = (($context['refresh'] ?? false) === true && in_array($part, self::REFRESHED, true) === true);
		foreach (($fragment[$part] ?? []) as $entry) {
			$name = $fragment['app'].'/'.$entry['key'];
			$report[$part][$name] = 'kept';
			$held    = isset($record[$part][$name]);
			$earlier = ($record[$part][$name] ?? []);
			$where   = $fragment['file'].': '.$this->noun().' "'.$entry['key'].'"';
			if ($held === true && $this->writesAgain(refresh: $refresh, entry: $earlier, where: $where, report: $report) === false) {
				continue;
			}

			$gaps = [];
			$data = (array) $values->fill(value: $entry['data'], context: $context, gaps: $gaps);
			$made = ['error' => $this->refusal(data: $data, users: array_keys($record['users'])), 'entry' => null];
			if ($gaps !== []) {
				$made['error'] = 'cannot resolve '.implode(', ', array_unique($gaps));
			}

			if ($made['error'] === '') {
				$made = $this->write(app: $fragment['app'], key: $entry['key'], data: $data);
			}

			if ($made['entry'] === null) {
				$report['dropped'][]  = $fragment['file'].': '.$this->noun().' "'.$entry['key'].'": '.$made['error'];
				$report[$part][$name] = 'failed';
				continue;
			}

			$record[$part][$name] = $made['entry'];
			$report[$part][$name] = ['created', 'refreshed'][(int) $held];
			$changed = true;
		}//end foreach

		return $changed;
	}//end installFragment()

	/**
	 * Whether a row an earlier run wrote is written again: only on `--refresh`, after the old row is taken away; false
	 * (and named under `dropped`) when it could not be taken away, so the old row stays and is not written twice.
	 *
	 * @param bool                 $refresh Whether this run refreshes this part.
	 * @param array<string, mixed> $entry  The record entry of the row.
	 * @param string               $where  The fragment file and the row, for the report.
	 * @param array<string, mixed> $report The report.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/example-set-refresh/specs/example-sets/spec.md#requirement-a-refresh-rewrites-every-object-the-set-declares
	 */
	private function writesAgain(bool $refresh, array $entry, string $where, array &$report): bool {
		if ($refresh === false) {
			return false;
		}

		if ($this->revert(entry: $entry) === 'failed') {
			$report['dropped'][] = $where.': not refreshed, the earlier one could not be taken away';
			return false;
		}

		return true;
	}//end writesAgain()
}//end class
