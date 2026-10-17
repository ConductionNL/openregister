<?php

/**
 * OpenRegister Example Set Installer
 *
 * Installs an example set gathered by ExampleSetCatalogue. First a check pass
 * resolves every placeholder and lookup, checks the cast and validates every
 * object against its real schema; when that pass refuses anything, nothing is
 * written. Then the write pass creates what the install record does not hold
 * yet, keeping the record after every step, and finally every created object
 * is read back and compared with what was sent.
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
 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Checks, installs and proves an example set.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) One install flow: check, accounts, objects, proof.
 * @SuppressWarnings(PHPMD.TooManyMethods)            Each part of a fragment has its own check and write step.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)    One collaborator per part of a fragment, each small and separately tested.
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)    The constructor takes one collaborator per part of a fragment.
 *
 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
 */
class ExampleSetInstaller {
	/**
	 * Constructor.
	 *
	 * @param ExampleSetStore         $store         Register access.
	 * @param ExampleSetPeople        $people        The cast's and the service accounts' accounts and groups.
	 * @param ExampleSetValues        $values        Fills placeholders.
	 * @param ExampleSetRecord        $record        What an install made.
	 * @param ExampleSetProof         $proof         Compares sent with stored.
	 * @param ExampleSetPrerequisites $prerequisites Registers and organisations before the check, and the base context.
	 * @param ExampleSetChanges       $changes       Changes objects the set did not create.
	 * @param ExampleSetFiles         $files         Attaches files to objects.
	 * @param ExampleSetAppConfig     $appConfig     Writes a fragment's own app config.
	 * @param ExampleSetUserPreferences $userPreferences Writes a fragment's own app's preferences of the set's accounts.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ExampleSetStore $store,
		private readonly ExampleSetPeople $people,
		private readonly ExampleSetValues $values,
		private readonly ExampleSetRecord $record,
		private readonly ExampleSetProof $proof,
		private readonly ExampleSetPrerequisites $prerequisites,
		private readonly ExampleSetChanges $changes,
		private readonly ExampleSetFiles $files,
		private readonly ExampleSetAppConfig $appConfig,
		private readonly ExampleSetUserPreferences $userPreferences,
	) {
	}//end __construct()

	/**
	 * Check a set without writing any of its accounts, groups or objects.
	 *
	 * A register the set requires that is not on the instance is imported
	 * first, because its schemas are what the objects are checked against;
	 * the import is recorded, and remove takes it away again.
	 *
	 * An account id the set creates (a cast member's or a service account's)
	 * counts as existing for a `user` format here: the accounts are written
	 * first in a real install, and no other id is let through.
	 *
	 * @param array<string, mixed>   $plan  A plan from ExampleSetCatalogue::gather().
	 * @param array<int, string>     $adopt Account ids the administrator lets the set use although it did not create them.
	 * @param DateTimeImmutable|null $today The install day; now in the set's time zone when null.
	 *
	 * @return array{refusals: array<int, string>, checked: array<string, int>, checkedChanges: array<string, int>, registers: array<string, string>}
	 *         Every refusal, the number of objects (`checked`) and of changes (`checkedChanges`) checked per register
	 *         and schema, counted apart as an install reports them, and per required register `imported` or `kept`.
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
	 */
	public function check(array $plan, array $adopt=[], ?DateTimeImmutable $today=null): array {
		$today     = ($today ?? $this->today(timezone: (string) $plan['timezone']));
		$record    = $this->record->read(set: (string) $plan['set']);
		$ready     = $this->prerequisites->ensure(plan: $plan, record: $record);
		$base      = $this->withIds(plan: $plan, base: $this->prerequisites->context(plan: $plan, today: $today));
		$refusals  = array_merge($ready['refusals'], $this->people->refusals(plan: $plan, record: $record, adopt: $adopt));
		$checked   = [];
		$changes   = [];
		$planned   = [];
		$users     = $this->people->declaredUids(plan: $plan);
		foreach ($plan['fragments'] as $fragment) {
			$objects = [];
			foreach ($fragment['objects'] as $object) {
				$type           = $object['register'].' '.$object['schema'];
				$checked[$type] = (($checked[$type] ?? 0) + 1);
				$where          = $fragment['file'].': object "'.$object['key'].'" ('.$type.')';
				$resolved       = $this->resolve(
					object: $object,
					context: (['objects' => $objects] + $base),
					planned: $planned
				);
				if ($resolved['refusal'] !== '') {
					$refusals[] = $where.': '.$resolved['refusal'];
					$objects[$object['key']] = $this->standIn(app: $fragment['app'], object: $object, data: []);
					continue;
				}

				$messages = array_merge(
					$this->store->validate(register: $object['register'], schema: $object['schema'], data: $resolved['sent'], plannedUsers: $users),
					$this->files->refusals(dir: (string) $fragment['dir'], object: $object)
				);
				foreach ($messages as $message) {
					$refusals[] = $where.': '.$message;
				}

				$standIn   = $this->standIn(app: $fragment['app'], object: $object, data: $resolved['data']);
				$planned[] = ['register' => $object['register'], 'schema' => $object['schema'], 'row' => $standIn];
				$kept      = $this->kept(record: $record, app: $fragment['app'], key: $object['key']);
				$objects[$object['key']] = ($kept ?? $standIn);
			}//end foreach

			$context  = (['objects' => $objects] + $base);
			$refusals = array_merge(
				$refusals,
				$this->checkChanges(fragment: $fragment, context: $context, checked: $changes, plannedUsers: $users),
				$this->checkAppConfig(fragment: $fragment, context: $context),
				$this->checkUserPreferences(fragment: $fragment, context: $context, users: $users)
			);
		}//end foreach

		return ['refusals' => $refusals, 'checked' => $checked, 'checkedChanges' => $changes, 'registers' => $ready['registers']];
	}//end check()

	/**
	 * Install a set: check, then write what the record lacks, then prove it.
	 *
	 * @param array<string, mixed>   $plan     A plan from ExampleSetCatalogue::gather().
	 * @param array<int, string>     $adopt    Account ids the administrator lets the set use.
	 * @param string                 $password One password for every new account; '' makes one per account.
	 * @param DateTimeImmutable|null $today    The install day; now in the set's time zone when null.
	 *
	 * @return array<string, mixed> `refusals` (non-empty: none of the set's accounts or objects was written),
	 *                              `registers`, `users`, `groups`, `memberships` and `changes` (each name to
	 *                              `imported`, `created`, `adopted` or `kept`), `passwords` (uid to a password this
	 *                              run made), `types`, `dropped`, `missing`, `lost` and `ok`.
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
	 */
	public function install(array $plan, array $adopt=[], string $password='', ?DateTimeImmutable $today=null): array {
		$today  = ($today ?? $this->today(timezone: (string) $plan['timezone']));
		$report = [
			'refusals'    => [],
			'registers'   => [],
			'users'       => [],
			'groups'      => [],
			'memberships' => [],
			'changes'     => [],
			'appConfig'   => [],
			'userPreferences' => [],
			'files'       => 0,
			'passwords'   => [],
			'types'       => [],
			'dropped'     => [],
			'missing'     => [],
			'lost'        => [],
			'ok'          => false,
		];

		$checked             = $this->check(plan: $plan, adopt: $adopt, today: $today);
		$report['refusals']  = $checked['refusals'];
		$report['registers'] = $checked['registers'];
		if ($report['refusals'] !== []) {
			return $report;
		}

		$set    = (string) $plan['set'];
		$record = $this->record->read(set: $set);
		$report = $this->people->install(plan: $plan, adopt: $adopt, password: $password, report: $report, record: $record);

		$base    = $this->withIds(plan: $plan, base: $this->prerequisites->context(plan: $plan, today: $today));
		$written = [];
		foreach ($plan['fragments'] as $fragment) {
			$report = $this->installFragment(plan: $plan, fragment: $fragment, base: $base, report: $report, record: $record, written: $written);
		}

		return $this->proven(report: $report, record: $record, written: $written);
	}//end install()

	/**
	 * The context with the database id of every register and schema the set names by slug.
	 *
	 * Asked after the required registers are imported, so their ids are there
	 * in a check too. A slug the instance does not have gets no id, and its
	 * placeholder refuses the set.
	 *
	 * @param array<string, mixed> $plan The plan.
	 * @param array<string, mixed> $base The context so far.
	 *
	 * @return array<string, mixed> The context with `registers` (id by slug) and `schemas` (id by `register/schema`).
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-register-or-schema-is-named-by-slug-and-written-as-its-id-req-exs-021
	 */
	private function withIds(array $plan, array $base): array {
		$text              = (string) json_encode($plan['fragments'], (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		$base['registers'] = [];
		$base['schemas']   = [];
		preg_match_all('/\{\{\s*register:\s*([^{}\s]+)\s*\}\}/', $text, $registers);
		foreach (array_unique($registers[1]) as $slug) {
			$base['registers'][$slug] = $this->store->registerId(register: $slug);
		}

		preg_match_all('/\{\{\s*schema:\s*([^{}\s\/]+)\/([^{}\s]+)\s*\}\}/', $text, $schemas, PREG_SET_ORDER);
		foreach ($schemas as $match) {
			$base['schemas'][$match[1].'/'.$match[2]] = $this->store->schemaId(register: $match[1], schema: $match[2]);
		}

		return $base;
	}//end withIds()

	/**
	 * Check the changes of one fragment: each resolves, finds its object, and leaves it valid.
	 *
	 * The changed object is validated as it would be written, so a field kept
	 * as JSON text is validated as text, as when the object was created.
	 *
	 * @param array<string, mixed> $fragment     The fragment.
	 * @param array<string, mixed> $context      `cast`, `objects` and `today`.
	 * @param array<string, int>   $checked      Counts of changes per register and schema; this fragment's are added.
	 * @param array<int, string>   $plannedUsers Account ids the set creates, valid for a `user` format.
	 *
	 * @return array<int, string> Refusals.
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-field-a-schema-keeps-as-json-text-is-sent-as-text-req-exs-012
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
	 */
	private function checkChanges(array $fragment, array $context, array &$checked, array $plannedUsers): array {
		$refusals = [];
		foreach ($fragment['changes'] as $change) {
			$change         = $this->withObjectJsonFields(change: $change, fragment: $fragment);
			$type           = $change['register'].' '.$change['schema'];
			$checked[$type] = (($checked[$type] ?? 0) + 1);
			$where          = $fragment['file'].': change "'.$change['key'].'" ('.$type.')';
			$resolved       = $this->changes->resolve(change: $change, context: $context);
			if ($resolved['refusal'] !== '') {
				$refusals[] = $where.': '.$resolved['refusal'];
				continue;
			}

			$merged = $resolved['merged'];
			unset($merged['@self'], $merged['id']);
			$messages = $this->store->validate(register: $change['register'], schema: $change['schema'], data: $merged, plannedUsers: $plannedUsers);
			foreach ($messages as $message) {
				$refusals[] = $where.': '.$message;
			}
		}

		return $refusals;
	}//end checkChanges()

	/**
	 * Write the objects and changes of one fragment that the record does not hold.
	 *
	 * @param array<string, mixed>                $plan     The plan.
	 * @param array<string, mixed>                $fragment The fragment.
	 * @param array<string, mixed>                $base     The context every placeholder may draw on.
	 * @param array<string, mixed>                $report   The report so far.
	 * @param array<string, mixed>                $record   The record; updated and kept after every write.
	 * @param array<string, array<string, mixed>> $written  Collects what this run sent, by record key.
	 *
	 * @return array<string, mixed> The report.
	 */
	private function installFragment(array $plan, array $fragment, array $base, array $report, array &$record, array &$written): array {
		$objects = [];
		foreach ($fragment['objects'] as $object) {
			$type = $object['register'].' '.$object['schema'];
			$name = $fragment['app'].'/'.$object['key'];
			$report['types'][$type] ??= ['declared' => 0, 'created' => 0, 'kept' => 0, 'arrived' => 0];
			$report['types'][$type]['declared']++;

			$kept = $this->kept(record: $record, app: $fragment['app'], key: $object['key']);
			if ($kept !== null) {
				$report['types'][$type]['kept']++;
				$objects[$object['key']] = $this->refreshReferences(
					plan: $plan,
					fragment: $fragment,
					object: $object,
					row: $kept,
					context: (['objects' => $objects] + $base),
					report: $report,
					record: $record
				);
				continue;
			}

			$resolved = $this->resolve(
				object: $object,
				context: (['objects' => $objects] + $base),
				planned: []
			);
			if ($resolved['refusal'] !== '') {
				$report['dropped'][] = $fragment['file'].': object "'.$object['key'].'": '.$resolved['refusal'];
				continue;
			}

			$made = $this->store->create(register: $object['register'], schema: $object['schema'], data: $resolved['sent']);
			if ($made['id'] === '') {
				$report['dropped'][] = $fragment['file'].': object "'.$object['key'].'": '.$made['error'];
				continue;
			}

			$report['types'][$type]['created']++;
			$record['objects'][$name] = ['register' => $object['register'], 'schema' => $object['schema'], 'id' => $made['id']];
			$this->record->write(set: (string) $plan['set'], record: $record);
			$written[$name] = $resolved['data'];
			$report         = $this->attachFiles(plan: $plan, fragment: $fragment, object: $object, objectId: $made['id'], report: $report, record: $record);

			// The row as stored, because a later object may quote a field the
			// register made itself, such as a case number.
			$objects[$object['key']] = ($this->store->get(register: $object['register'], schema: $object['schema'], id: $made['id']) ?? ['id' => $made['id']]);
		}//end foreach

		$context = (['objects' => $objects] + $base);
		$report  = $this->installChanges(plan: $plan, fragment: $fragment, context: $context, report: $report, record: $record);

		$report = $this->installAppConfig(plan: $plan, fragment: $fragment, context: $context, report: $report, record: $record);

		return $this->installUserPreferences(plan: $plan, fragment: $fragment, context: $context, report: $report, record: $record);
	}//end installFragment()

	/**
	 * Point a kept object at the objects its declaration references now.
	 *
	 * An object from an earlier run is kept, but an object it references may
	 * have been removed and written again with a new id since (a partial
	 * remove). Every top-level field whose declared value holds an
	 * `{{object:}}` or `{{lookup:}}` placeholder is resolved again; a field
	 * that differs from what is stored is written, recorded as the change
	 * `<app>/<key>#references`, so the proof checks it and remove stays exact.
	 * The first recorded old value of a field is kept over later refreshes.
	 *
	 * @param array<string, mixed> $plan     The plan.
	 * @param array<string, mixed> $fragment The fragment.
	 * @param array<string, mixed> $object   The declared object.
	 * @param array<string, mixed> $row      The object as stored.
	 * @param array<string, mixed> $context  The context its placeholders draw on.
	 * @param array<string, mixed> $report   The report; gains the outcome under `changes`.
	 * @param array<string, mixed> $record   The record; gains the refresh.
	 *
	 * @return array<string, mixed> The object as stored after the refresh.
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-kept-object-follows-the-objects-it-references-req-exs-023
	 */
	private function refreshReferences(array $plan, array $fragment, array $object, array $row, array $context, array &$report, array &$record): array {
		$fields = $this->referenceFields(object: $object);
		if ($fields === []) {
			return $row;
		}

		$name     = $fragment['app'].'/'.$object['key'].'#references';
		$resolved = $this->resolve(object: $object, context: $context, planned: []);
		if ($resolved['refusal'] !== '') {
			$report['dropped'][]      = $fragment['file'].': object "'.$object['key'].'": its references cannot be refreshed: '.$resolved['refusal'];
			$report['changes'][$name] = 'failed';
			return $row;
		}

		$made = $this->changes->refresh(object: $object, row: $row, values: array_intersect_key($resolved['data'], array_flip($fields)));
		if ($made['error'] !== '') {
			$report['dropped'][]      = $fragment['file'].': object "'.$object['key'].'": its references cannot be refreshed: '.$made['error'];
			$report['changes'][$name] = 'failed';
			return $row;
		}

		if ($made['entry'] === null) {
			return $row;
		}

		$entry = $made['entry'];
		foreach ((array) ($record['changes'][$name]['set'] ?? []) as $earlier) {
			foreach ($entry['set'] as $index => $set) {
				if ($set['path'] === $earlier['path']) {
					$entry['set'][$index] = array_merge($set, ['had' => $earlier['had'], 'old' => $earlier['old'], 'from' => $earlier['from']]);
				}
			}
		}

		$record['changes'][$name] = $entry;
		$report['changes'][$name] = 'refreshed';
		$this->record->write(set: (string) $plan['set'], record: $record);

		return ($this->store->get(register: $object['register'], schema: $object['schema'], id: (string) $entry['id']) ?? $row);
	}//end refreshReferences()

	/**
	 * The top-level fields of an object whose declared value references another object.
	 *
	 * @param array<string, mixed> $object The declared object.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-kept-object-follows-the-objects-it-references-req-exs-023
	 */
	private function referenceFields(array $object): array {
		$fields = [];
		foreach ((array) ($object['data'] ?? []) as $field => $value) {
			$text = (string) json_encode($value, (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
			if (str_contains((string) $field, '.') === false && preg_match('/\{\{\s*(object|lookup):/', $text) === 1) {
				$fields[] = (string) $field;
			}
		}

		return $fields;
	}//end referenceFields()

	/**
	 * Make the changes of one fragment that the record does not hold.
	 *
	 * @param array<string, mixed> $plan     The plan.
	 * @param array<string, mixed> $fragment The fragment.
	 * @param array<string, mixed> $context  `cast`, `objects` and `today`.
	 * @param array<string, mixed> $report   The report so far.
	 * @param array<string, mixed> $record   The record; updated and kept after every change.
	 *
	 * @return array<string, mixed> The report.
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-field-a-schema-keeps-as-json-text-is-sent-as-text-req-exs-012
	 */
	private function installChanges(array $plan, array $fragment, array $context, array $report, array &$record): array {
		foreach ($fragment['changes'] as $change) {
			$change = $this->withObjectJsonFields(change: $change, fragment: $fragment);
			$name   = $fragment['app'].'/'.$change['key'];
			$report['changes'][$name] = 'kept';
			if (isset($record['changes'][$name]) === true) {
				continue;
			}

			$resolved = $this->changes->resolve(change: $change, context: $context);
			$made     = ['error' => $resolved['refusal'], 'entry' => null];
			if ($resolved['refusal'] === '') {
				$made = $this->changes->apply(change: $change, resolved: $resolved);
			}

			if ($made['error'] !== '') {
				$report['dropped'][]      = $fragment['file'].': change "'.$change['key'].'": '.$made['error'];
				$report['changes'][$name] = 'failed';
				continue;
			}

			if ($made['entry'] !== null) {
				$record['changes'][$name] = $made['entry'];
				$report['changes'][$name] = 'created';
				$this->record->write(set: (string) $plan['set'], record: $record);
			}
		}//end foreach

		return $report;
	}//end installChanges()

	/**
	 * A change on an object of the same fragment, with that object's `jsonFields` added to its own.
	 *
	 * The object was sent with those fields as JSON text, so the change reads,
	 * validates and writes them the same way.
	 *
	 * @param array<string, mixed> $change   The declared change.
	 * @param array<string, mixed> $fragment The fragment.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-field-a-schema-keeps-as-json-text-is-sent-as-text-req-exs-012
	 */
	private function withObjectJsonFields(array $change, array $fragment): array {
		if (is_string($change['object'] ?? null) === false) {
			return $change;
		}

		foreach ($fragment['objects'] as $object) {
			if (($object['key'] ?? null) === $change['object']) {
				$change['jsonFields'] = array_values(
					array_unique(array_merge((array) ($change['jsonFields'] ?? []), (array) ($object['jsonFields'] ?? [])))
				);
				break;
			}
		}

		return $change;
	}//end withObjectJsonFields()

	/**
	 * Resolve an object's lookups and fill its data.
	 *
	 * A field named in `jsonFields` is sent as JSON text, for a schema that
	 * keeps it as text; `data` keeps the list, which is what a read hands back.
	 *
	 * @param array<string, mixed>             $object  The declared object.
	 * @param array<string, mixed>             $context `cast`, `objects` and `today`.
	 * @param array<int, array<string, mixed>> $planned Objects a check pass declared earlier, for lookups.
	 *
	 * @return array{data: array<string, mixed>, sent: array<string, mixed>, refusal: string} The filled data, what
	 *                                                                                        to send, or why it
	 *                                                                                        cannot be filled.
	 */
	private function resolve(array $object, array $context, array $planned): array {
		$context['lookups'] = [];
		foreach ((array) ($object['lookups'] ?? []) as $name => $lookup) {
			$found = $this->lookedUp(register: (string) $object['register'], lookup: (array) $lookup, context: $context, planned: $planned);
			if ($found['id'] === '') {
				return ['data' => [], 'sent' => [], 'refusal' => 'lookup "'.$name.'" found '.$found['reason']];
			}

			$context['lookups'][(string) $name] = $found['id'];
		}

		$gaps = [];
		$data = (array) $this->values->fill(value: $object['data'], context: $context, gaps: $gaps);
		if ($gaps !== []) {
			return ['data' => [], 'sent' => [], 'refusal' => 'cannot resolve '.implode(', ', array_unique($gaps))];
		}

		$sent = $this->changes->asSent(row: $data, fields: (array) ($object['jsonFields'] ?? []));

		return ['data' => $data, 'sent' => $sent, 'refusal' => ''];
	}//end resolve()

	/**
	 * The id of the one row a lookup by business key describes.
	 *
	 * The match is checked here as well: a register that does not filter on a
	 * property hands over every row, and the first of those is not the one
	 * that was asked for.
	 *
	 * @param string                           $register The register of the object being written.
	 * @param array<string, mixed>             $lookup   `schema` and `where`; `register` when it is another one.
	 * @param array<string, mixed>             $context  The cast, the lookups found so far and the day.
	 * @param array<int, array<string, mixed>> $planned  Objects a check pass declared earlier.
	 *
	 * @return array{id: string, reason: string}
	 */
	private function lookedUp(string $register, array $lookup, array $context, array $planned): array {
		$gaps   = [];
		$where  = (array) $this->values->fill(value: (array) ($lookup['where'] ?? []), context: $context, gaps: $gaps);
		$schema = (string) ($lookup['schema'] ?? '');
		$from   = (string) ($lookup['register'] ?? $register);
		if ($gaps !== []) {
			return ['id' => '', 'reason' => 'an unresolvable '.implode(', ', array_unique($gaps))];
		}

		if ($where === [] || $schema === '') {
			return ['id' => '', 'reason' => 'nothing: a lookup needs a schema and a where'];
		}

		// Asked with the filter first, then without: OpenRegister does not
		// filter on every kind of property, and the match is checked here anyway.
		foreach ([$where, []] as $filters) {
			foreach ($this->store->find(register: $from, schema: $schema, filters: $filters) as $row) {
				if ($this->matches(row: $row, where: $where) === true) {
					return ['id' => $this->store->idOf(row: $row), 'reason' => ''];
				}
			}
		}

		$id = $this->plannedMatch(planned: $planned, register: $from, schema: $schema, where: $where);
		if ($id !== '') {
			return ['id' => $id, 'reason' => ''];
		}

		$words = [];
		foreach ($where as $field => $value) {
			$words[] = $field.' '.json_encode($value, JSON_UNESCAPED_UNICODE);
		}

		return ['id' => '', 'reason' => 'no '.$from.' '.$schema.' with '.implode(' and ', $words)];
	}//end lookedUp()

	/**
	 * The stand-in id of an object declared earlier in the set that a lookup describes, or ''.
	 *
	 * @param array<int, array<string, mixed>> $planned  Objects a check pass declared earlier.
	 * @param string                           $register The register looked in.
	 * @param string                           $schema   The schema looked in.
	 * @param array<string, mixed>             $where    The asked values.
	 *
	 * @return string
	 */
	private function plannedMatch(array $planned, string $register, string $schema, array $where): string {
		foreach ($planned as $object) {
			if ($object['register'] === $register && $object['schema'] === $schema && $this->matches(row: $object['row'], where: $where) === true) {
				return (string) $object['row']['id'];
			}
		}

		return '';
	}//end plannedMatch()

	/**
	 * Whether a row holds every asked value.
	 *
	 * @param array<string, mixed> $row   The row.
	 * @param array<string, mixed> $where The asked values, by field.
	 *
	 * @return bool
	 */
	private function matches(array $row, array $where): bool {
		foreach ($where as $field => $value) {
			$held = ($row[$field] ?? null);
			if ($held === $value) {
				continue;
			}

			if (is_scalar($held) === false || is_scalar($value) === false || (string) $held !== (string) $value) {
				return false;
			}
		}

		return true;
	}//end matches()

	/**
	 * The row the record holds for an object, when it is still there.
	 *
	 * @param array<string, mixed> $record The record.
	 * @param string               $app    The app of the fragment.
	 * @param string               $key    The object key.
	 *
	 * @return array<string, mixed>|null
	 */
	private function kept(array $record, string $app, string $key): ?array {
		$held = ($record['objects'][$app.'/'.$key] ?? null);
		if ($held === null) {
			return null;
		}

		return $this->store->get(register: $held['register'], schema: $held['schema'], id: $held['id']);
	}//end kept()

	/**
	 * A row that stands in for an object a check does not write.
	 *
	 * @param string               $app    The app of the fragment.
	 * @param array<string, mixed> $object The declared object.
	 * @param array<string, mixed> $data   Its filled data, or [] when it could not be filled.
	 *
	 * @return array<string, mixed>
	 */
	private function standIn(string $app, array $object, array $data): array {
		$hash = md5($app.'/'.$object['key']);
		$id   = sprintf(
			'%s-%s-4%s-8%s-%s',
			substr($hash, 0, 8),
			substr($hash, 8, 4),
			substr($hash, 13, 3),
			substr($hash, 17, 3),
			substr($hash, 20, 12)
		);

		return array_merge($data, ['id' => $id, 'uuid' => $id, ExampleSetValues::PLANNED => true]);
	}//end standIn()

	/**
	 * Read the instance back and fill in what is missing and what was not kept.
	 *
	 * An object from an earlier run is counted, not compared: somebody may have
	 * changed it since, and that is not a loss.
	 *
	 * @param array<string, mixed>                $report  The report so far.
	 * @param array<string, mixed>                $record  The record as written.
	 * @param array<string, array<string, mixed>> $written What this run sent, by record key.
	 *
	 * @return array<string, mixed> The finished report.
	 */
	private function proven(array $report, array $record, array $written): array {
		foreach ($record['objects'] as $name => $held) {
			$type   = $held['register'].' '.$held['schema'];
			$stored = $this->store->get(register: $held['register'], schema: $held['schema'], id: $held['id']);
			if ($stored === null) {
				$report['missing'][] = $type.' '.$name;
				continue;
			}

			if (isset($report['types'][$type]) === true) {
				$report['types'][$type]['arrived']++;
			}

			foreach ($this->proof->lostPaths(declared: ($written[$name] ?? []), stored: $stored) as $path) {
				$report['lost'][] = $type.' '.$name.': '.$path;
			}
		}

		$report            = $this->provenParts(report: $report, record: $record);
		$report['missing'] = array_merge($report['missing'], $this->people->missing(record: $record));
		$report['ok']      = ($report['dropped'] === [] && $report['missing'] === [] && $report['lost'] === []);

		return $report;
	}//end proven()

	/**
	 * Read back the changes, files and app config the record holds.
	 *
	 * @param array<string, mixed> $report The report so far.
	 * @param array<string, mixed> $record The record as written.
	 *
	 * @return array<string, mixed> The report with what is missing or not kept.
	 */
	private function provenParts(array $report, array $record): array {
		foreach ($record['changes'] as $name => $entry) {
			foreach ($this->changes->lost(entry: $entry) as $path) {
				$report['lost'][] = 'change '.$name.': '.$path;
			}
		}

		foreach ($record['files'] as $name => $files) {
			$present = $this->files->names(objectId: (string) ($record['objects'][$name]['id'] ?? ''));
			foreach ($files as $file) {
				if (in_array($file['name'], $present, true) === false) {
					$report['missing'][] = 'file '.$file['name'].' on '.$name;
				}
			}
		}

		foreach ($record['appConfig'] as $name => $entry) {
			if ($this->appConfig->lost(entry: $entry) === true) {
				$report['lost'][] = 'app config '.$entry['app'].' '.$entry['key'].' ('.$name.')';
			}
		}

		foreach (array_keys(array_filter($record['userPreferences'], fn (array $entry): bool => $this->userPreferences->lost(entry: $entry))) as $name) {
			$report['lost'][] = 'user preference '.$name;
		}

		return $report;
	}//end provenParts()

	/**
	 * Check the app config of one fragment: each key may be written and each value resolves.
	 *
	 * @param array<string, mixed> $fragment The fragment.
	 * @param array<string, mixed> $context  The context placeholders draw on.
	 *
	 * @return array<int, string> Refusals; never a value.
	 */
	private function checkAppConfig(array $fragment, array $context): array {
		$refusals = [];
		foreach (($fragment['appConfig'] ?? []) as $entry) {
			$where   = $fragment['file'].': app config "'.$entry['key'].'"';
			$refusal = $this->appConfig->refusal(app: $fragment['app'], key: $entry['key'], generate: $entry['generate']);
			if ($refusal !== '') {
				$refusals[] = $where.': '.$refusal;
				continue;
			}

			$gaps = [];
			$this->values->fill(value: (string) $entry['value'], context: $context, gaps: $gaps);
			if ($entry['generate'] === false && $gaps !== []) {
				$refusals[] = $where.': cannot resolve '.implode(', ', array_unique($gaps));
			}
		}

		return $refusals;
	}//end checkAppConfig()

	/**
	 * Write the app config of one fragment that the record does not hold.
	 *
	 * @param array<string, mixed> $plan     The plan.
	 * @param array<string, mixed> $fragment The fragment.
	 * @param array<string, mixed> $context  The context placeholders draw on.
	 * @param array<string, mixed> $report   The report so far; names keys, never values.
	 * @param array<string, mixed> $record   The record; updated after every write.
	 *
	 * @return array<string, mixed> The report.
	 */
	private function installAppConfig(array $plan, array $fragment, array $context, array $report, array &$record): array {
		foreach (($fragment['appConfig'] ?? []) as $entry) {
			$name = $fragment['app'].'/'.$entry['key'];
			$report['appConfig'][$name] = 'kept';
			if (isset($record['appConfig'][$name]) === true) {
				continue;
			}

			$gaps  = [];
			$value = (string) $this->values->fill(value: (string) $entry['value'], context: $context, gaps: $gaps);
			$made  = $this->appConfig->apply(app: $fragment['app'], key: $entry['key'], value: $value, generate: $entry['generate']);
			if ($made['error'] !== '') {
				$report['dropped'][]        = $fragment['file'].': app config "'.$entry['key'].'": '.$made['error'];
				$report['appConfig'][$name] = 'failed';
				continue;
			}

			if ($made['entry'] !== null) {
				$record['appConfig'][$name] = $made['entry'];
				$report['appConfig'][$name] = 'created';
				$this->record->write(set: (string) $plan['set'], record: $record);
			}
		}//end foreach

		return $report;
	}//end installAppConfig()

	/**
	 * Check the user preferences of one fragment: each account fills and is the set's own, and each key may be written.
	 *
	 * @param array<string, mixed> $fragment The fragment.
	 * @param array<string, mixed> $context  The context placeholders draw on.
	 * @param array<int, string>   $users    The account ids the set creates or adopts.
	 *
	 * @return array<int, string> Refusals.
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-fragment-may-set-its-own-apps-preferences-for-the-sets-own-accounts-req-exs-024
	 */
	private function checkUserPreferences(array $fragment, array $context, array $users): array {
		$refusals = [];
		foreach (($fragment['userPreferences'] ?? []) as $entry) {
			$where = $fragment['file'].': user preference "'.$entry['key'].'"';
			$gaps  = [];
			$uid   = $this->values->fill(value: $entry['user'], context: $context, gaps: $gaps);
			$this->values->fill(value: $entry['value'], context: $context, gaps: $gaps);
			if (is_string($uid) === false) {
				$gaps[] = (string) $entry['user'];
			}

			if ($gaps !== []) {
				$refusals[] = $where.': cannot resolve '.implode(', ', array_unique($gaps));
				continue;
			}

			$refusal = $this->userPreferences->refusal(app: $fragment['app'], uid: $uid, key: $entry['key'], users: $users);
			if ($refusal !== '') {
				$refusals[] = $where.': '.$refusal;
			}
		}

		return $refusals;
	}//end checkUserPreferences()

	/**
	 * Write the user preferences of one fragment that the record does not hold, for the set's own accounts only.
	 *
	 * @param array<string, mixed> $plan     The plan.
	 * @param array<string, mixed> $fragment The fragment.
	 * @param array<string, mixed> $context  The context placeholders draw on.
	 * @param array<string, mixed> $report   The report so far.
	 * @param array<string, mixed> $record   The record; updated after every write.
	 *
	 * @return array<string, mixed> The report.
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-fragment-may-set-its-own-apps-preferences-for-the-sets-own-accounts-req-exs-024
	 */
	private function installUserPreferences(array $plan, array $fragment, array $context, array $report, array &$record): array {
		foreach (($fragment['userPreferences'] ?? []) as $entry) {
			$gaps  = [];
			$uid   = (string) $this->values->fill(value: $entry['user'], context: $context, gaps: $gaps);
			$value = (string) $this->values->fill(value: $entry['value'], context: $context, gaps: $gaps);
			$name  = $fragment['app'].'/'.$uid.'/'.$entry['key'];
			$report['userPreferences'][$name] = 'kept';
			if (isset($record['userPreferences'][$name]) === true) {
				continue;
			}

			// Checked again against the record: only an account this set created or adopted.
			$why = $this->userPreferences->refusal(app: $fragment['app'], uid: $uid, key: $entry['key'], users: array_keys($record['users']));
			if ($why === '' && $gaps !== []) {
				$why = 'cannot resolve '.implode(', ', $gaps);
			}

			$made = ['error' => $why, 'entry' => null];
			if ($why === '') {
				$made = $this->userPreferences->apply(uid: $uid, app: $fragment['app'], key: $entry['key'], value: $value);
			}

			if ($made['error'] !== '') {
				$report['dropped'][] = $fragment['file'].': user preference "'.$entry['key'].'" for '.$uid.': '.$made['error'];
				$report['userPreferences'][$name] = 'failed';
				continue;
			}

			if ($made['entry'] !== null) {
				$record['userPreferences'][$name] = $made['entry'];
				$report['userPreferences'][$name] = 'created';
				$this->record->write(set: (string) $plan['set'], record: $record);
			}
		}//end foreach

		return $report;
	}//end installUserPreferences()

	/**
	 * Attach the files of a just-written object and record them.
	 *
	 * @param array<string, mixed> $plan     The plan.
	 * @param array<string, mixed> $fragment The fragment.
	 * @param array<string, mixed> $object   The declared object.
	 * @param string               $objectId The written object's id.
	 * @param array<string, mixed> $report   The report so far.
	 * @param array<string, mixed> $record   The record; gains the files.
	 *
	 * @return array<string, mixed> The report.
	 */
	private function attachFiles(array $plan, array $fragment, array $object, string $objectId, array $report, array &$record): array {
		if (($object['files'] ?? []) === []) {
			return $report;
		}

		$name     = $fragment['app'].'/'.$object['key'];
		$attached = $this->files->attach(dir: (string) $fragment['dir'], object: $object, objectId: $objectId);
		if ($attached['files'] !== []) {
			$record['files'][$name] = $attached['files'];
			$this->record->write(set: (string) $plan['set'], record: $record);
		}

		$report['files'] += count($attached['files']);
		foreach ($attached['errors'] as $error) {
			$report['dropped'][] = $fragment['file'].': object "'.$object['key'].'": '.$error;
		}

		return $report;
	}//end attachFiles()

	/**
	 * This moment in the set's time zone, so "09:12" is a quarter past nine where the set takes place.
	 *
	 * @param string $timezone The set's time zone.
	 *
	 * @return DateTimeImmutable
	 */
	private function today(string $timezone): DateTimeImmutable {
		try {
			return new DateTimeImmutable('now', new DateTimeZone($timezone));
		} catch (Throwable) {
			return new DateTimeImmutable('now');
		}
	}//end today()
}//end class
