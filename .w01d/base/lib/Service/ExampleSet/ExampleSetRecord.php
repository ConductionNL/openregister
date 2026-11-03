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
class ExampleSetRecord {
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
		'archival'      => [],
	];

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
		$record  = self::BLANK;
		$decoded = json_decode($this->stored(set: $set), true);
		if (is_array($decoded) === false) {
			return $record;
		}

		foreach ((array) ($decoded['users'] ?? []) as $uid => $how) {
			if (in_array($how, ['created', 'adopted'], true) === true) {
				$record['users'][(string) $uid] = $how;
			}
		}

		$record['groups']      = array_values(array_filter((array) ($decoded['groups'] ?? []), 'is_string'));
		$record['groupNames']  = array_filter((array) ($decoded['groupNames'] ?? []), 'is_array');
		$record['memberships'] = $this->memberships(decoded: (array) ($decoded['memberships'] ?? []));
		$record['subAdmins']   = $this->memberships(decoded: (array) ($decoded['subAdmins'] ?? []));
		$record['objects']     = $this->objects(decoded: (array) ($decoded['objects'] ?? []));
		$record['changes']     = array_filter((array) ($decoded['changes'] ?? []), 'is_array');
		$record['registers']   = array_filter((array) ($decoded['registers'] ?? []), 'is_array');
		$record['files']       = array_filter((array) ($decoded['files'] ?? []), 'is_array');
		$record['appConfig']   = array_filter((array) ($decoded['appConfig'] ?? []), 'is_array');
		$record['userPreferences'] = array_filter((array) ($decoded['userPreferences'] ?? []), 'is_array');
		$record['organisations'] = array_values(array_filter((array) ($decoded['organisations'] ?? []), 'is_string'));
		$record['tasks']         = array_filter((array) ($decoded['tasks'] ?? []), 'is_array');
		$record['views']         = array_filter((array) ($decoded['views'] ?? []), 'is_array');
		$record['watchers']      = array_filter((array) ($decoded['watchers'] ?? []), 'is_array');
		$record['parties']       = array_filter((array) ($decoded['parties'] ?? []), 'is_array');
		$record['notes']         = array_filter((array) ($decoded['notes'] ?? []), 'is_array');
		$record['readStates']    = array_filter((array) ($decoded['readStates'] ?? []), 'is_array');
		$record['flows']         = array_filter((array) ($decoded['flows'] ?? []), 'is_array');
		$record['exportRuns']    = array_filter((array) ($decoded['exportRuns'] ?? []), 'is_array');
		$record['deckCards']     = array_filter((array) ($decoded['deckCards'] ?? []), 'is_array');
		$record['appointments']  = array_filter((array) ($decoded['appointments'] ?? []), 'is_array');
		$record['casePlans']     = array_filter((array) ($decoded['casePlans'] ?? []), 'is_array');
		$record['collectivesPages'] = array_filter((array) ($decoded['collectivesPages'] ?? []), 'is_array');
		$record['talkConversations'] = array_filter((array) ($decoded['talkConversations'] ?? []), 'is_array');
		$record['auditEntries']  = array_filter((array) ($decoded['auditEntries'] ?? []), 'is_array');
		$record['timelineEntries'] = array_filter((array) ($decoded['timelineEntries'] ?? []), 'is_array');
		$record['emailLinks']    = array_filter((array) ($decoded['emailLinks'] ?? []), 'is_array');
		$record['archival']      = array_filter((array) ($decoded['archival'] ?? []), 'is_array');

		return $record;
	}//end read()

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
				$objects[(string) $key] = [
					'register' => (string) ($object['register'] ?? ''),
					'schema'   => (string) ($object['schema'] ?? ''),
					'id'       => $object['id'],
				];
				// What the install wrote into the object's reference fields, so a later install can tell its own value from an edit.
				if (is_array($object['refs'] ?? null) === true && $object['refs'] !== []) {
					$objects[(string) $key]['refs'] = $object['refs'];
				}
			}
		}

		return $objects;
	}//end objects()

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
	 * @param array<string, mixed> $fragment The fragment, as gathered for this run.
	 * @param string               $part     The record part, which is also the fragment's list of declarations.
	 * @param string               $noun     The part's word in the report.
	 * @param array<string, mixed> $report   The report; gains a `left` line per entry let go.
	 * @param array<string, mixed> $record   The record; loses the entries let go.
	 *
	 * @return bool Whether the record changed and is to be written.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-the-record-lets-go-of-a-held-entry-the-set-no-longer-declares
	 */
	public static function letGoUndeclared(array $fragment, string $part, string $noun, array &$report, array &$record): bool {
		$prefix   = $fragment['app'].'/';
		$declared = [];
		foreach ((array) ($fragment[$part] ?? []) as $entry) {
			$declared[$prefix.($entry['key'] ?? '')] = true;
		}

		$changed = false;
		foreach (array_keys((array) ($record[$part] ?? [])) as $name) {
			$name = (string) $name;
			if (str_starts_with($name, $prefix) === false || str_contains($name, '#') === true || isset($declared[$name]) === true) {
				continue;
			}

			unset($record[$part][$name]);
			$report['left'][] = $noun.' '.$name.': the set no longer declares it';
			$changed          = true;
		}

		return $changed;
	}//end letGoUndeclared()

	/**
	 * Hold what following a held change or preference made of its entry, and report a write or a failure.
	 *
	 * @param string                                                         $set    The set id.
	 * @param string                                                         $part   `changes` or `userPreferences`.
	 * @param string                                                         $name   The record key.
	 * @param array{error: string, entry: array<string, mixed>, wrote: bool} $made   What follow() made.
	 * @param array<string, mixed>                                           $report The report so far.
	 * @param array<string, mixed>                                           $record The record; written when the entry changed.
	 *
	 * @return array<string, mixed> The report.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-held-change-or-preference-follows-its-declaration
	 */
	public function hold(string $set, string $part, string $name, array $made, array $report, array &$record): array {
		if ($made['error'] !== '') {
			$report['dropped'][]  = $part.' '.$name.': not refreshed: '.$made['error'];
			$report[$part][$name] = 'failed';
		}

		if ($made['entry'] !== $record[$part][$name]) {
			$record[$part][$name] = $made['entry'];
			$this->write(set: $set, record: $record);
		}

		if ($made['wrote'] === true) {
			$report[$part][$name] = 'refreshed';
		}

		return $report;
	}//end hold()

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
