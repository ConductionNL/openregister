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
 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-second-install-writes-nothing-req-exs-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use OCA\OpenRegister\AppInfo\Application;
use OCP\IAppConfig;

/**
 * The install record of one example set.
 *
 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-second-install-writes-nothing-req-exs-005
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
		'notes'         => [],
		'readStates'    => [],
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
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-second-install-writes-nothing-req-exs-005
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
		$record['memberships'] = $this->memberships(decoded: (array) ($decoded['memberships'] ?? []));
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
		$record['notes']         = array_filter((array) ($decoded['notes'] ?? []), 'is_array');
		$record['readStates']    = array_filter((array) ($decoded['readStates'] ?? []), 'is_array');

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
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-second-install-writes-nothing-req-exs-005
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
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-second-install-writes-nothing-req-exs-005
	 */
	public function write(string $set, array $record): void {
		if ($this->isEmpty(record: $record) === true) {
			// A register this set imported and removed again is remembered, so the next install imports its data again.
			if (($record['registers'] ?? []) !== []) {
				$this->store(set: $set, json: (string) json_encode(['registers' => $record['registers']]));
				return;
			}

			$this->appConfig->deleteKey(Application::APP_ID, self::PREFIX.$set);
			return;
		}

		$this->store(set: $set, json: (string) json_encode($record));
	}//end write()

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
		$json = $this->appConfig->getValueString(Application::APP_ID, self::PREFIX.$set, '', true);
		if ($json === '') {
			$json = $this->appConfig->getValueString(Application::APP_ID, self::PREFIX.$set, '', false);
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
		$this->appConfig->setValueString(Application::APP_ID, self::PREFIX.$set, $json, true);
	}//end store()
}//end class
