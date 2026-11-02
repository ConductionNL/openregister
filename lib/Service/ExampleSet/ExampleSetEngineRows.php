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
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use DateTimeImmutable;
use Throwable;

/**
 * One kind of row an example set writes through an OpenRegister service: the
 * check and install loops are shared, each kind says how one row is refused,
 * written, proved and deleted.
 *
 * @SuppressWarnings(PHPMD.NumberOfChildren) One child per kind of row an example set writes;
 *                                            a new kind is a new child by design, and the
 *                                            shared loops stay here.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 */
abstract class ExampleSetEngineRows {
	/**
	 * The parts `--refresh` writes again: a saved view, a task, a note, a read state, an export and an appointment carry
	 * values that age, a party link caches its party's name and e-mail address, and a case plan's items move on as
	 * the case is worked (a refresh puts them back in their declared states). A flow is updated where it is, so a
	 * changed declaration reaches it without a new uuid. An email link carries the sender and the details its row
	 * shows, so a changed declaration reaches it. An access link and a grant are made again, so their dates follow
	 * the install day and a link revoked during a demonstration is back.
	 */
	public const REFRESHED = [
		'views',
		'tasks',
		'notes',
		'readStates',
		'exportRuns',
		'parties',
		'appointments',
		'casePlans',
		'flows',
		'emailLinks',
		'accessLinks',
		'grants',
	];

	/**
	 * The fragment part and record part this kind lives in (`tasks`, `views`).
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	abstract public function part(): string;

	/**
	 * The word for one row, in messages (`task`, `view`).
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
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
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	abstract public function refusal(array $data, array $users): string;

	/**
	 * Why a filled row cannot be written in this run, or ''; a kind that checks a row against the set's objects or
	 * cast overrides this, every other kind answers refusal().
	 *
	 * @param array<string, mixed> $data    The filled fields.
	 * @param array<int, string>   $users   The account ids the set creates or adopts.
	 * @param array<string, mixed> $context The context placeholders draw on: `objects`, `cast`, `today`.
	 *
	 * @return string
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $context is the contract for the kinds that override this.
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-access-links-on-its-objects
	 */
	protected function refusalIn(array $data, array $users, array $context): string {
		return $this->refusal(data: $data, users: $users);
	}//end refusalIn()

	/**
	 * Whether a uuid names an object this fragment writes (or an earlier fragment of the set wrote).
	 *
	 * @param mixed                $uuid    The filled value.
	 * @param array<string, mixed> $context The context; `objects` holds the set's objects by key.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-access-links-on-its-objects
	 */
	protected function isObjectOfSet(mixed $uuid, array $context): bool {
		if (is_string($uuid) === false || $uuid === '') {
			return false;
		}

		foreach ((array) ($context['objects'] ?? []) as $object) {
			if (is_array($object) === true && in_array($uuid, [($object['id'] ?? null), ($object['uuid'] ?? null)], true) === true) {
				return true;
			}
		}

		return false;
	}//end isObjectOfSet()

	/**
	 * Why a date field cannot be used, or '': not a date, or (for an end) before the install day.
	 *
	 * @param string               $field   The field name.
	 * @param mixed                $value   The filled value.
	 * @param array<string, mixed> $context The context; `today` is the install day.
	 * @param bool                 $isEnd   Whether the date is an end, which may not lie before the install day.
	 *
	 * @return string
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) An end and a start differ in one rule only; both callers name it.
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-access-links-on-its-objects
	 */
	protected function dateRefusal(string $field, mixed $value, array $context, bool $isEnd): string {
		if (is_string($value) === false || trim($value) === '') {
			return $field.' is not a date';
		}

		try {
			$moment = new DateTimeImmutable($value);
		} catch (Throwable) {
			return $field.' "'.$value.'" is not a date';
		}

		$today = ($context['today'] ?? null);
		if ($isEnd === true && $today instanceof DateTimeImmutable === true
			&& $moment->format('Y-m-d') < $today->setTimezone($moment->getTimezone())->format('Y-m-d')
		) {
			return $field.' "'.$value.'" lies before the install day';
		}

		return '';
	}//end dateRefusal()

	/**
	 * Write one row.
	 *
	 * @param string               $app  The app that ships the fragment.
	 * @param string               $key  The row's key in the fragment.
	 * @param array<string, mixed> $data The filled fields.
	 *
	 * @return array{error: string, entry: array{uuid: string}|null} The record entry, or why the write failed.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	abstract public function write(string $app, string $key, array $data): array;

	/**
	 * Whether a written row is no longer there.
	 *
	 * @param array<string, mixed> $entry A record entry from write().
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	abstract public function lost(array $entry): bool;

	/**
	 * Delete a row the set wrote.
	 *
	 * @param array<string, mixed> $entry A record entry from write().
	 *
	 * @return string `removed` (also when it was already gone) or `failed`.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	abstract public function revert(array $entry): string;

	/**
	 * Whether a refresh updates a held row where it is instead of taking it away and writing it again.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-seeded-view-keeps-its-uuid
	 */
	public function updatesInPlace(): bool {
		return false;
	}//end updatesInPlace()

	/**
	 * Update a held row where it is; a kind that updatesInPlace() overrides this.
	 *
	 * @param array<string, mixed> $entry The record entry of the earlier write.
	 * @param string               $app   The app that ships the fragment.
	 * @param string               $key   The row's key in the fragment.
	 * @param array<string, mixed> $data  The filled fields.
	 *
	 * @return array{error: string, entry: array<string, mixed>|null} The record entry, or why the update failed.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $entry is the contract for the kinds that update
	 *                                                in place; this default writes the row afresh.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-seeded-view-keeps-its-uuid
	 */
	public function update(array $entry, string $app, string $key, array $data): array {
		return $this->write(app: $app, key: $key, data: $data);
	}//end update()

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
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	public function checkFragment(array $fragment, array $context, array $users, ExampleSetValues $values): array {
		$refusals = [];
		foreach (($fragment[$this->part()] ?? []) as $entry) {
			$gaps = [];
			$data = (array) $values->fill(value: $entry['data'], context: $context, gaps: $gaps);
			$why  = $this->refusalIn(data: $data, users: $users, context: $context);
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
	 * Write the rows of one fragment that the record does not hold, and let go of the held ones it no longer declares.
	 *
	 * @param array<string, mixed> $fragment The fragment.
	 * @param array<string, mixed> $context  The context placeholders draw on.
	 * @param array<string, mixed> $report   The report; gains the outcomes under part() and `dropped`.
	 * @param array<string, mixed> $record   The record; gains the rows written under part().
	 * @param ExampleSetValues     $values   Fills placeholders.
	 *
	 * @return bool Whether the record changed and is to be written.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 * @spec openspec/specs/example-sets/spec.md#requirement-the-record-lets-go-of-a-held-entry-the-set-no-longer-declares
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ExampleSetRecord::letGoUndeclared() is a pure function of its arguments, shared by every part.
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
			$mode    = $this->mode(held: $held, refresh: $refresh, earlier: $earlier, where: $where, report: $report);
			if ($mode === 'skip') {
				continue;
			}

			$gaps = [];
			$data = (array) $values->fill(value: $entry['data'], context: $context, gaps: $gaps);
			$made = ['error' => $this->refusalIn(data: $data, users: array_keys($record['users']), context: $context), 'entry' => null];
			if ($gaps !== []) {
				$made['error'] = 'cannot resolve '.implode(', ', array_unique($gaps));
			}

			if ($made['error'] === '') {
				$made = $this->writeOrUpdate(mode: $mode, earlier: $earlier, app: $fragment['app'], key: $entry['key'], data: $data);
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

		if (ExampleSetRecord::letGoUndeclared(fragment: $fragment, part: $part, noun: $this->noun(), report: $report, record: $record) === true) {
			$changed = true;
		}

		return $changed;
	}//end installFragment()

	/**
	 * What this run does with a row: `write` it, `update` the held one where it is, or `skip` a held one.
	 *
	 * @param bool                 $held    Whether the record holds the row.
	 * @param bool                 $refresh Whether this run refreshes this part.
	 * @param array<string, mixed> $earlier The record entry of the earlier write.
	 * @param string               $where   The row, for messages.
	 * @param array<string, mixed> $report  The report; gains a row that could not be taken away.
	 *
	 * @return string
	 */
	private function mode(bool $held, bool $refresh, array $earlier, string $where, array &$report): string {
		if ($held === false) {
			return 'write';
		}

		if ($refresh === true && $this->updatesInPlace() === true) {
			return 'update';
		}

		if ($this->writesAgain(refresh: $refresh, entry: $earlier, where: $where, report: $report) === true) {
			return 'write';
		}

		return 'skip';
	}//end mode()

	/**
	 * Write a row, or update the held one where it is.
	 *
	 * @param string               $mode    `write` or `update`, from mode().
	 * @param array<string, mixed> $earlier The record entry of the earlier write.
	 * @param string               $app     The app that ships the fragment.
	 * @param string               $key     The row's key in the fragment.
	 * @param array<string, mixed> $data    The filled fields.
	 *
	 * @return array{error: string, entry: array<string, mixed>|null}
	 */
	private function writeOrUpdate(string $mode, array $earlier, string $app, string $key, array $data): array {
		if ($mode === 'update') {
			return $this->update(entry: $earlier, app: $app, key: $key, data: $data);
		}

		return $this->write(app: $app, key: $key, data: $data);
	}//end writeOrUpdate()

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
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-refresh-rewrites-every-object-the-set-declares
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
