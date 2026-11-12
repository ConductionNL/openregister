<?php

/**
 * OpenRegister Example Set Record
 *
 * What an install of an example set made, kept in OpenRegister's app config
 * under `example_set_<set>`: the accounts it created or adopted, the groups it
 * created, the memberships it added and every object it wrote, in the order
 * it wrote them. A second install reads it to write nothing twice; remove
 * reads it to delete exactly what the install made. A password is never in it.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-a-second-install-writes-nothing-req-exs-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCP\IAppConfig;

/**
 * The install record of one example set.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-second-install-writes-nothing-req-exs-005
 */
class ExampleSetRecord implements ExampleSetRecordInterface {
	/**
	 * The prefix of the app-config key that holds a record.
	 */
	private const PREFIX = 'example_set_';

	/**
	 * A record that names nothing.
	 */
	public const BLANK = [
		'users'       => [],
		'groups'      => [],
		'memberships' => [],
		'subAdmins'   => [],
		'groupNames'  => [],
		'objects'     => [],
		'changes'     => [],
		'registers'   => [],
		'files'       => [],
		'appConfig'   => [],
		'userPreferences' => [],
		'organisations' => [],
		'tasks'         => [],
		'views'         => [],
		'watchers'      => [],
		'parties'       => [],
		'notes'         => [],
		'readStates'    => [],
		'flows'         => [],
		'exportRuns'    => [],
		'deckCards'     => [],
		'appointments'  => [],
		'casePlans'     => [],
		'collectivesPages' => [],
		'talkConversations' => [],
		'auditEntries'  => [],
		'timelineEntries' => [],
		'emailLinks'    => [],
		'accessLinks'   => [],
		'grants'        => [],
		'archival'      => [],
	];

	/**
	 * The parts that hold gid and uid pairs.
	 */
	private const PAIRS = ['memberships', 'subAdmins'];

	/**
	 * The parts that hold a list of names.
	 */
	private const NAMES = ['groups', 'organisations'];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Holds the records.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * What an earlier install made.
	 *
	 * @param string $set The set id.
	 *
	 * @return array{users: array<string, string>, groups: array<int, string>, memberships: array<int, array{0: string, 1: string}>,
	 *               objects: array<string, array{register: string, schema: string, id: string}>,
	 *               changes: array<string, array<string, mixed>>, registers: array<string, array<int, array{appId: string, jobId: string}>>}
	 *               `users` maps a uid to `created` or `adopted`; `objects` and `changes` are keyed `<app>/<key>` in
	 *               write order; `registers` maps a register slug this set imported to the import jobs it created;
	 *               `files` maps `<app>/<key>` to the files attached to that object; `appConfig` maps `<app>/<key>` to
	 *               what was written and what it replaced (never a sensitive value); `userPreferences` maps
	 *               `<app>/<uid>/<key>` to what was written for that account and what it replaced; `organisations` lists the uuids
	 *               of the OpenRegister organisations the set created; `tasks` and `views` map `<app>/<key>` to the
	 *               uuid of the engine task or saved view the set wrote.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-second-install-writes-nothing-req-exs-005
	 */
	public function read(string $set): array {
		$decoded = json_decode($this->stored(set: $set), true);
		if (is_array($decoded) === false) {
			return self::BLANK;
		}

		$record = self::BLANK;
		foreach (array_keys(self::BLANK) as $part) {
			$record[$part] = $this->part(part: $part, stored: (array) ($decoded[$part] ?? []));
		}

		return $record;
	}//end read()

	/**
	 * The well-formed entries of one stored part: accounts that were created or adopted, gid and uid pairs, objects
	 * with an id, lists of names, or (every other part) the entries that are maps.
	 *
	 * @param string                   $part   The part.
	 * @param array<int|string, mixed> $stored The stored part.
	 *
	 * @return array<int|string, mixed>
	 */
	private function part(string $part, array $stored): array {
		return match (true) {
			$part === 'users'   => $this->users(decoded: $stored),
			$part === 'objects' => $this->objects(decoded: $stored),
			in_array($part, self::PAIRS, true) => $this->memberships(decoded: $stored),
			in_array($part, self::NAMES, true) => array_values(array_filter($stored, 'is_string')),
			default => array_filter($stored, 'is_array'),
		};
	}//end part()

	/**
	 * The accounts of a stored record, each `created` or `adopted`.
	 *
	 * @param array<int|string, mixed> $decoded The stored accounts.
	 *
	 * @return array<string, string>
	 */
	private function users(array $decoded): array {
		$users = [];
		foreach ($decoded as $uid => $how) {
			if (in_array($how, ['created', 'adopted'], true) === true) {
				$users[(string) $uid] = $how;
			}
		}

		return $users;
	}//end users()

	/**
	 * The well-formed gid and uid pairs of a stored record.
	 *
	 * @param array<int|string, mixed> $decoded The stored memberships.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	private function memberships(array $decoded): array {
		$pairs = [];
		foreach ($decoded as $pair) {
			if (is_array($pair) === true && is_string($pair[0] ?? null) === true && is_string($pair[1] ?? null) === true) {
				$pairs[] = [$pair[0], $pair[1]];
			}
		}

		return $pairs;
	}//end memberships()

	/**
	 * The well-formed objects of a stored record, in write order.
	 *
	 * @param array<int|string, mixed> $decoded The stored objects.
	 *
	 * @return array<string, array{register: string, schema: string, id: string, refs?: array<string, mixed>}>
	 */
	private function objects(array $decoded): array {
		$objects = [];
		foreach ($decoded as $key => $object) {
			if (is_array($object) === true && is_string($object['id'] ?? null) === true && $object['id'] !== '') {
				$objects[(string) $key] = $this->object(object: $object);
			}
		}

		return $objects;
	}//end objects()

	/**
	 * One well-formed object of a stored record.
	 *
	 * @param array<string, mixed> $object The stored object, with an id.
	 *
	 * @return array{register: string, schema: string, id: string, refs?: array<string, mixed>}
	 */
	private function object(array $object): array {
		$kept = [
			'register' => (string) ($object['register'] ?? ''),
			'schema'   => (string) ($object['schema'] ?? ''),
			'id'       => $object['id'],
		];
		// What the install wrote into the object's reference fields, so a later install can tell its own value from an edit.
		if (is_array($object['refs'] ?? null) === true && $object['refs'] !== []) {
			$kept['refs'] = $object['refs'];
		}

		return $kept;
	}//end object()

	/**
	 * Whether a record names nothing.
	 *
	 * @param array<string, mixed> $record The record.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-second-install-writes-nothing-req-exs-005
	 */
	public function isEmpty(array $record): bool {
		foreach (array_keys(self::BLANK) as $part) {
			$held = ($record[$part] ?? []);
			if ($part === 'registers') {
				// A slug with no job left only remembers that its import was removed; it is not something installed.
				$held = array_filter((array) $held, static fn ($jobs): bool => $jobs !== []);
			}

			if ($held !== []) {
				return false;
			}
		}

		return true;
	}//end isEmpty()

	/**
	 * Keep the record, or drop the key when nothing is left in it.
	 *
	 * What stays when nothing is installed is the slug of each register the
	 * set imported whose import jobs were all removed again (an empty job list).
	 *
	 * @param string               $set    The set id.
	 * @param array<string, mixed> $record The record.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-second-install-writes-nothing-req-exs-005
	 */
	public function write(string $set, array $record): void {
		if ($this->isEmpty(record: $record) === true) {
			// A register this set imported and removed again is remembered, so the next install imports its data again.
			if (($record['registers'] ?? []) !== []) {
				$this->store(set: $set, json: (string) json_encode(['registers' => $record['registers']]));
				return;
			}

			$this->appConfig->deleteKey(AppIdentity::APP_ID, self::PREFIX.$set);
			return;
		}

		$this->store(set: $set, json: (string) json_encode($record));
	}//end write()

	/**
	 * Let go of the entries of one part that a fragment's app holds and the fragment no longer declares: the proof
	 * and remove leave what they wrote alone, and the report names each under `left`. An entry another part keys
	 * by an object (`<app>/<key>#references`) is not a declaration of this part and stays.
	 *
	 * @param FragmentPart $declaring The fragment, as gathered for this run, and the record part it declares.
	 * @param RecordLedger $ledger    The report (gains a `left` line per entry let go) and the record (loses the entries let go).
	 *
	 * @return bool Whether the record changed and is to be written.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-the-record-lets-go-of-a-held-entry-the-set-no-longer-declares
	 */
	public static function letGoUndeclared(FragmentPart $declaring, RecordLedger $ledger): bool {
		$part  = $declaring->part;
		$held  = array_keys((array) ($ledger->record[$part->part] ?? []));
		$names = self::undeclared(declared: self::declared(declaring: $declaring), held: $held);
		foreach ($names as $name) {
			unset($ledger->record[$part->part][$name]);
			$ledger->report['left'][] = $part->noun.' '.$name.': the set no longer declares it';
		}

		return $names !== [];
	}//end letGoUndeclared()

	/**
	 * The held record keys, in order, that are the app's own declarations of the part and that the fragment no
	 * longer declares.
	 *
	 * @param FragmentDeclarations $declared The app's prefix and the record keys the fragment declares.
	 * @param array<int, int|string> $held   The record keys the part holds.
	 *
	 * @return array<int, string>
	 */
	private static function undeclared(FragmentDeclarations $declared, array $held): array {
		$names = [];
		foreach ($held as $name) {
			if (self::isUndeclared(name: (string) $name, declared: $declared) === true) {
				$names[] = (string) $name;
			}
		}

		return $names;
	}//end undeclared()

	/**
	 * The app's prefix, `<app>/`, and the record keys of the declarations of one part in a fragment.
	 *
	 * @param FragmentPart $declaring The fragment and the part.
	 *
	 * @return FragmentDeclarations
	 */
	private static function declared(FragmentPart $declaring): FragmentDeclarations {
		$prefix   = $declaring->fragment['app'].'/';
		$declared = [];
		foreach ((array) ($declaring->fragment[$declaring->part->part] ?? []) as $entry) {
			$declared[$prefix.($entry['key'] ?? '')] = true;
		}

		return new FragmentDeclarations(prefix: $prefix, keys: $declared);
	}//end declared()

	/**
	 * Whether a held entry is one of the app's own declarations of this part that the fragment no longer declares.
	 *
	 * @param string               $name     The record key.
	 * @param FragmentDeclarations $declared The app's prefix and the record keys the fragment declares.
	 *
	 * @return bool
	 */
	private static function isUndeclared(string $name, FragmentDeclarations $declared): bool {
		return str_starts_with($name, $declared->prefix) === true && str_contains($name, '#') === false && isset($declared->keys[$name]) === false;
	}//end isUndeclared()

	/**
	 * Hold what following a held change or preference made of its entry, and report a write or a failure.
	 *
	 * @param ExampleSetFollowedEntry $followed The entry's part and record key, and what follow() made.
	 * @param RecordLedger            $ledger   The report, which gains the outcome, and the record, which takes the entry.
	 *
	 * @return bool Whether the record changed and is to be written.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-held-change-or-preference-follows-its-declaration
	 */
	public function hold(ExampleSetFollowedEntry $followed, RecordLedger $ledger): bool {
		$took           = self::took(followed: $followed, record: $ledger->record);
		$ledger->report = self::reported(followed: $followed, report: $ledger->report);

		return $took;
	}//end hold()

	/**
	 * Put what following made of an entry into the record, when it differs from what the record holds.
	 *
	 * @param ExampleSetFollowedEntry $followed The entry's part and record key, and what follow() made.
	 * @param array<string, mixed>    $record   The record; updated.
	 *
	 * @return bool Whether the record changed.
	 */
	private static function took(ExampleSetFollowedEntry $followed, array &$record): bool {
		if ($followed->made['entry'] === $record[$followed->part][$followed->name]) {
			return false;
		}

		$record[$followed->part][$followed->name] = $followed->made['entry'];
		return true;
	}//end took()

	/**
	 * The report with a followed entry's failure and write.
	 *
	 * @param ExampleSetFollowedEntry $followed The entry's part and record key, and what follow() made.
	 * @param array<string, mixed>    $report   The report so far.
	 *
	 * @return array<string, mixed> The report.
	 */
	private static function reported(ExampleSetFollowedEntry $followed, array $report): array {
		$part = $followed->part;
		$name = $followed->name;
		$made = $followed->made;
		if ($made['error'] !== '') {
			$report['dropped'][]  = $part.' '.$name.': not refreshed: '.$made['error'];
			$report[$part][$name] = 'failed';
		}

		if ($made['wrote'] === true) {
			$report[$part][$name] = 'refreshed';
		}

		return $report;
	}//end reported()

	/**
	 * The stored record as JSON text, '' when there is none.
	 *
	 * A record is kept as a lazy app-config value: Nextcloud loads every
	 * non-lazy value of every app on every request, and a demo instance's
	 * record (each object id, change, file, config entry and preference) is
	 * large and rewritten after every object an install creates. A record an
	 * earlier version stored as a non-lazy value is still found, and moves to
	 * the lazy store the next time it is written.
	 *
	 * @param string $set The set id.
	 *
	 * @return string
	 */
	private function stored(string $set): string {
		$json = $this->appConfig->getValueString(AppIdentity::APP_ID, self::PREFIX.$set, '', true);
		if ($json === '') {
			$json = $this->appConfig->getValueString(AppIdentity::APP_ID, self::PREFIX.$set, '', false);
		}

		return $json;
	}//end stored()

	/**
	 * Store the record's JSON text as a lazy value.
	 *
	 * @param string $set  The set id.
	 * @param string $json The record as JSON text.
	 *
	 * @return void
	 */
	private function store(string $set, string $json): void {
		$this->appConfig->setValueString(AppIdentity::APP_ID, self::PREFIX.$set, $json, true);
	}//end store()
}//end class
