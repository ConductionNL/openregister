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
			if ($this->carries(object: $object, uuid: $uuid) === true) {
				return true;
			}
		}

		return false;
	}//end isObjectOfSet()

	/**
	 * Whether an object of the set carries the uuid as its id or uuid.
	 *
	 * @param mixed  $object The object, a map when it is one.
	 * @param string $uuid   The uuid.
	 *
	 * @return bool
	 */
	private function carries(mixed $object, string $uuid): bool {
		return is_array($object) === true && in_array($uuid, [($object['id'] ?? null), ($object['uuid'] ?? null)], true) === true;
	}//end carries()

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
		if ($this->isBlank(value: $value) === true) {
			return $field.' is not a date';
		}

		try {
			$moment = new DateTimeImmutable($value);
		} catch (Throwable) {
			return $field.' "'.$value.'" is not a date';
		}

		if ($isEnd === true && $this->beforeDay(moment: $moment, today: ($context['today'] ?? null)) === true) {
			return $field.' "'.$value.'" lies before the install day';
		}

		return '';
	}//end dateRefusal()

	/**
	 * Whether a filled value is no text, or only blanks.
	 *
	 * @param mixed $value The filled value.
	 *
	 * @return bool
	 */
	private function isBlank(mixed $value): bool {
		return is_string($value) === false || trim($value) === '';
	}//end isBlank()

	/**
	 * Whether a moment lies on a day before the install day, in the install day's zone; false without an install day.
	 *
	 * @param DateTimeImmutable $moment The moment.
	 * @param mixed             $today  The install day, or null.
	 *
	 * @return bool
	 */
	private function beforeDay(DateTimeImmutable $moment, mixed $today): bool {
		return $today instanceof DateTimeImmutable === true
			&& $moment->setTimezone($today->getTimezone())->format('Y-m-d') < $today->format('Y-m-d');
	}//end beforeDay()

	/**
	 * Write one row.
	 *
	 * @param ExampleSetRow $row The row: its app, its key in the fragment and its filled fields.
	 *
	 * @return array{error: string, entry: array{uuid: string, runs?: array<string, string>, waitsOn?: array<string, string>}|null}
	 *         The record entry (a flow's also names its keyed runs), or why the write failed.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	abstract public function write(ExampleSetRow $row): array;

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
	 * @param ExampleSetRow        $row   The row: its app, its key in the fragment and its filled fields.
	 *
	 * @return array{error: string, entry: array<string, mixed>|null} The record entry, or why the update failed.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $entry is the contract for the kinds that update
	 *                                                in place; this default writes the row afresh.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-seeded-view-keeps-its-uuid
	 */
	public function update(array $entry, ExampleSetRow $row): array {
		return $this->write(row: $row);
	}//end update()

	/**
	 * The context this part's rows are filled in: the given one, unless a part adds to it.
	 *
	 * @param array<string, mixed> $context The context placeholders draw on.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragments-flow-may-quote-its-own-uuid
	 */
	protected function fillContext(array $context): array {
		return $context;
	}//end fillContext()

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
			$data = (array) $values->fill(value: $entry['data'], context: $this->fillContext(context: $context), gaps: $gaps);
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
	 * @param ExampleSetFragmentWrite $write  The fragment, the context placeholders draw on and their filler.
	 * @param array<string, mixed>    $report The report; gains the outcomes under part() and `dropped`.
	 * @param array<string, mixed>    $record The record; gains the rows written under part().
	 *
	 * @return bool Whether the record changed and is to be written.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 * @spec openspec/specs/example-sets/spec.md#requirement-the-record-lets-go-of-a-held-entry-the-set-no-longer-declares
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ExampleSetRecord::letGoUndeclared() is a pure function of its arguments, shared by every part.
	 */
	public function installFragment(ExampleSetFragmentWrite $write, array &$report, array &$record): bool {
		$fragment = $write->fragment;
		$install  = new ExampleSetRowInstall(rows: $this, refusal: $this->refusalIn(...), fill: $this->fillContext(...), values: $write->values);
		$changed  = $install->fragment(fragment: $fragment, context: $write->context, report: $report, record: $record);
		if (ExampleSetRecord::letGoUndeclared(fragment: $fragment, part: $this->part(), noun: $this->noun(), report: $report, record: $record) === true) {
			$changed = true;
		}

		return $changed;
	}//end installFragment()
}//end class
