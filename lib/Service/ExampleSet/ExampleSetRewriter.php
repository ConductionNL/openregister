<?php

/**
 * OpenRegister Example Set Rewriter
 *
 * `example-set:install --refresh`: an object the set wrote in an earlier run
 * is rewritten to the values its declaration gives today. Dates count from
 * the install day, cast, lookups and objects are read as they are now, and
 * every declared field goes over the stored row; a field the declaration does
 * not name stays as it is. Only objects the set's record holds come here, so
 * nothing the set did not write is touched.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-a-refresh-rewrites-every-object-the-set-declares
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * Writes a kept object's declared values over its stored row.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-refresh-rewrites-every-object-the-set-declares
 */
class ExampleSetRewriter {

	/**
	 * A kept object: on `--refresh` (the plan's `refresh`) rewritten to today's declared values, otherwise pointed
	 * at the objects it references now.
	 *
	 * @param array<string, mixed>                $plan       The plan.
	 * @param array<string, mixed>                $run        The `store`, the `fragment`, the declared `object` and the stored `row`.
	 * @param callable(): array<string, mixed>    $resolve    Resolves the declaration today.
	 * @param callable(): array<string, mixed>    $references Refreshes only the references, when not refreshing.
	 * @param array<string, mixed>                $report     The report.
	 * @param array<string, array<string, mixed>> $written    Collects what this run sent, by record key.
	 *
	 * @return array<string, mixed> The object as stored afterwards.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-refresh-rewrites-every-object-the-set-declares
	 */
	public function keep(array $plan, array $run, callable $resolve, callable $references, array &$report, array &$written): array {
		if (($plan['refresh'] ?? false) !== true) {
			return $references();
		}

		return $this->rewrite(run: $run, resolved: $resolve(), report: $report, written: $written);
	}//end keep()

	/**
	 * Rewrite one kept object; the object as stored afterwards, or the row as it was when it could not be written.
	 *
	 * @param array<string, mixed>                $run      The `store`, the `fragment`, the declared `object` and the stored `row`.
	 * @param array<string, mixed>                $resolved The declaration resolved today: `refusal`, `data`, `sent`, and maybe `owner`.
	 * @param array<string, mixed>                $report   The report; `types` counts it as refreshed, `dropped` names a failure.
	 * @param array<string, array<string, mixed>> $written  Collects what this run sent, by record key, for the proof.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-refresh-rewrites-every-object-the-set-declares
	 */
	private function rewrite(array $run, array $resolved, array &$report, array &$written): array {
		$where = $run['fragment']['file'].': object "'.$run['object']['key'].'"';
		if ($resolved['refusal'] !== '') {
			$report['dropped'][] = $where.': not refreshed: '.$resolved['refusal'];
			return $run['row'];
		}

		$rules = $run['store']->writeRules(register: $run['object']['register'], schema: $run['object']['schema']);
		if ($rules['appendOnly'] === true) {
			// A row of an append-only schema is never updated; it stays as written.
			return $run['row'];
		}

		return $this->rewriteWritable(run: [...$run, 'where' => $where, 'rules' => $rules], resolved: $resolved, report: $report, written: $written);
	}//end rewrite()

	/**
	 * Rewrite a kept object its schema lets a write change: send the update, hand it to its owner, record what was
	 * written and count it.
	 *
	 * @param array<string, mixed>                $run      The run of rewrite(), with the object's `where` and the schema's `rules`.
	 * @param array<string, mixed>                $resolved The declaration resolved today.
	 * @param array<string, mixed>                $report   The report.
	 * @param array<string, array<string, mixed>> $written  Collects what this run sent, by record key.
	 *
	 * @return array<string, mixed> The object as stored afterwards, or the row as it was when it could not be written.
	 */
	private function rewriteWritable(array $run, array $resolved, array &$report, array &$written): array {
		$sent = $this->send(run: $run, resolved: $resolved);
		if ($sent['error'] !== '') {
			$report['dropped'][] = $run['where'].': not refreshed: '.$sent['error'];
			return $run['row'];
		}

		$error = $this->handOver(store: $run['store'], object: $run['object'], id: $sent['id'], owner: ($resolved['owner'] ?? null));
		if ($error !== '') {
			$report['dropped'][] = $run['where'].': not handed to its owner: '.$error;
		}

		// A kept field still holds what it held and a lens is no stored value: neither is what this run wrote.
		$written[$run['fragment']['app'].'/'.$run['object']['key']] = $this->wrote(data: $resolved['data'], rules: $run['rules']);

		return $this->counted(run: $run, sent: $sent, report: $report);
	}//end rewriteWritable()

	/**
	 * What a rewrite wrote of a declaration: its data but the fields kept as first written and the lenses.
	 *
	 * @param array<string, mixed>                                                            $data  The declared data, resolved today.
	 * @param array{appendOnly: bool, kept: array<int, string>, lenses: array<int, string>} $rules The schema's write rules.
	 *
	 * @return array<string, mixed>
	 */
	private function wrote(array $data, array $rules): array {
		return array_diff_key($data, array_flip(array_merge($rules['kept'], $rules['lenses'])));
	}//end wrote()

	/**
	 * Send the update a schema lets through, unless the stored row already holds it.
	 *
	 * @param array<string, mixed> $run      The run of rewriteWritable().
	 * @param array<string, mixed> $resolved The declaration resolved today.
	 *
	 * @return array{id: string, unchanged: bool, error: string} The object's id, whether nothing was sent, and why the update failed or ''.
	 */
	private function send(array $run, array $resolved): array {
		$row = $run['row'];
		$id  = (string) ($row['id'] ?? ($row['@self']['id'] ?? ''));
		// A field read back as null was never set: sending it would ask the schema to accept a null it may refuse.
		$set  = array_filter($row, static fn (mixed $value): bool => $value !== null);
		$data = $this->writable(data: array_merge($set, $resolved['sent']), row: $row, rules: $run['rules']);
		if ($this->holds(row: $row, data: $data) === true) {
			return ['id' => $id, 'unchanged' => true, 'error' => ''];
		}

		$error = $run['store']->update(register: $run['object']['register'], schema: $run['object']['schema'], id: $id, data: $data);

		return ['id' => $id, 'unchanged' => false, 'error' => $error];
	}//end send()

	/**
	 * Count a rewritten object as unchanged or refreshed; the object as stored afterwards.
	 *
	 * @param array<string, mixed>                              $run    The run of rewriteWritable().
	 * @param array{id: string, unchanged: bool, error: string} $sent   What send() did.
	 * @param array<string, mixed>                              $report The report; `types` gains the count.
	 *
	 * @return array<string, mixed>
	 */
	private function counted(array $run, array $sent, array &$report): array {
		$object = $run['object'];
		$type   = $object['register'].' '.$object['schema'];
		if ($sent['unchanged'] === true) {
			$report['types'][$type]['unchanged'] = (($report['types'][$type]['unchanged'] ?? 0) + 1);
			return $run['row'];
		}

		$report['types'][$type]['refreshed'] = (($report['types'][$type]['refreshed'] ?? 0) + 1);

		return ($run['store']->get(register: $object['register'], schema: $object['schema'], id: $sent['id']) ?? $run['row']);
	}//end counted()

	/**
	 * Whether the stored row already holds every field of an update, so writing it would change nothing.
	 *
	 * @param array<string, mixed> $row  The object as stored.
	 * @param array<string, mixed> $data The update a refresh would send.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-refresh-writes-only-an-object-whose-values-differ
	 */
	private function holds(array $row, array $data): bool {
		foreach ($data as $field => $value) {
			if (array_key_exists($field, $row) === false || $this->canonical(value: $row[$field]) !== $this->canonical(value: $value)) {
				return false;
			}
		}

		return true;
	}//end holds()

	/**
	 * A value with the keys of every map in it sorted, so two maps that differ only in key order compare equal.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-refresh-writes-only-an-object-whose-values-differ
	 */
	private function canonical(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		$value = array_map(fn (mixed $item): mixed => $this->canonical(value: $item), $value);
		if (array_is_list($value) === false) {
			ksort($value);
		}

		return $value;
	}//end canonical()

	/**
	 * Hand a rewritten object to the owner its declaration names, when another account owns it; '' when done or not needed.
	 *
	 * @param ExampleSetStore      $store  Register access.
	 * @param array<string, mixed> $object The declared object.
	 * @param string               $id     The object's id.
	 * @param string|null          $owner  The declared owner, a cast account; null when it names none.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-declared-object-may-name-its-owner-from-the-cast
	 */
	private function handOver(ExampleSetStore $store, array $object, string $id, ?string $owner): string {
		if ($owner === null || $store->ownerOf(register: $object['register'], schema: $object['schema'], id: $id) === $owner) {
			return '';
		}

		return $store->assignOwner(id: $id, owner: $owner);
	}//end handOver()

	/**
	 * The update a schema lets through: a field kept as first written carries its stored value (or is left out when
	 * nothing is stored), and a lens, read from another object, is left out.
	 *
	 * @param array<string, mixed>                                                            $data  The update.
	 * @param array<string, mixed>                                                            $row   The object as stored.
	 * @param array{appendOnly: bool, kept: array<int, string>, lenses: array<int, string>} $rules The schema's write rules.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-refresh-sends-only-what-the-schema-lets-a-write-change
	 */
	private function writable(array $data, array $row, array $rules): array {
		foreach ($rules['kept'] as $field) {
			unset($data[$field]);
			if (array_key_exists($field, $row) === true) {
				$data[$field] = $row[$field];
			}
		}

		foreach ($rules['lenses'] as $field) {
			unset($data[$field]);
		}

		return $data;
	}//end writable()

	/**
	 * Move the record of a rewritten object to what the rewrite wrote: its
	 * reference values and its `#references` change hold the new values, so the
	 * proof and a later run compare against what is on the instance. What a
	 * field held before the set first changed it (`had`, `old`) stays.
	 *
	 * @param array<string, mixed>                 $record  The record; updated.
	 * @param string                               $name    The record key of the object, `<app>/<key>`.
	 * @param array<string, array<string, mixed>>  $written What this run wrote, by record key.
	 * @param callable(array<string, mixed>): void $save    Keeps the record when it changed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-refresh-keeps-the-record-of-what-it-wrote
	 */
	public function follow(array &$record, string $name, array $written, callable $save): void {
		if (isset($written[$name]) === false) {
			return;
		}

		$refs       = $this->followRefs(record: $record, name: $name, data: $written[$name]);
		$references = $this->followReferences(record: $record, name: $name, data: $written[$name]);
		if ($refs === true || $references === true) {
			$save($record);
		}
	}//end follow()

	/**
	 * Move the record's reference values of an object to what the rewrite wrote.
	 *
	 * @param array<string, mixed> $record The record; updated.
	 * @param string               $name   The record key of the object.
	 * @param array<string, mixed> $data   What this run wrote for it.
	 *
	 * @return bool Whether the record changed.
	 */
	private function followRefs(array &$record, string $name, array $data): bool {
		$changed = false;
		foreach (array_keys((array) ($record['objects'][$name]['refs'] ?? [])) as $field) {
			if (array_key_exists($field, $data) === true && $record['objects'][$name]['refs'][$field] !== $data[$field]) {
				$record['objects'][$name]['refs'][$field] = $data[$field];
				$changed = true;
			}
		}

		return $changed;
	}//end followRefs()

	/**
	 * Move the new values of an object's `#references` change to what the rewrite wrote.
	 *
	 * @param array<string, mixed> $record The record; updated.
	 * @param string               $name   The record key of the object.
	 * @param array<string, mixed> $data   What this run wrote for it.
	 *
	 * @return bool Whether the record changed.
	 */
	private function followReferences(array &$record, string $name, array $data): bool {
		$changed = false;
		foreach ((array) ($record['changes'][$name.'#references']['set'] ?? []) as $index => $set) {
			$path = (string) $set['path'];
			if (array_key_exists($path, $data) === true && $set['new'] !== $data[$path]) {
				$record['changes'][$name.'#references']['set'][$index]['new'] = $data[$path];
				$changed = true;
			}
		}

		return $changed;
	}//end followReferences()
}//end class
