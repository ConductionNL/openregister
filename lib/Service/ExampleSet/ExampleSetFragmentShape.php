<?php

/**
 * OpenRegister Example Set Fragment Shape
 *
 * Turns one parsed fragment of an example set into the shape the installer
 * reads: every list checked for the parts its entries need and for keys of
 * their own, with the fragment's file named in every refusal.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-is-gathered-from-every-enabled-app-in-a-declared-order-req-exs-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * One fragment, for one app and file, as the installer reads it.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-is-gathered-from-every-enabled-app-in-a-declared-order-req-exs-001
 */
class ExampleSetFragmentShape {
	use ExampleSetKeyOf;

	/**
	 * What each kind of entry needs, for the message that refuses one.
	 */
	private const NEEDS = [
		'object' => 'a key, a register, a schema, data, and files with a relative path inside the fragment\'s folder',
		'change' => 'a key, a register, a schema, a where or an object, and a set or an append',
	];

	/**
	 * The lists of `{key, data}` rows a fragment may declare, in plan order, with the noun their messages use.
	 */
	private const KEYED_ROWS = [
		'tasks'             => 'task',
		'views'             => 'view',
		'watchers'          => 'watcher',
		'parties'           => 'party',
		'notes'             => 'note',
		'readStates'        => 'read state',
		'flows'             => 'flow',
		'exportRuns'        => 'export',
		'deckCards'         => 'deck card',
		'appointments'      => 'appointment',
		'casePlans'         => 'case plan',
		'collectivesPages'  => 'collectives page',
		'talkConversations' => 'talk conversation',
		'auditEntries'      => 'audit entry',
		'timelineEntries'   => 'timeline entry',
		'emailLinks'        => 'email link',
		'accessLinks'       => 'access link',
		'grants'            => 'grant',
	];

	/**
	 * Object and change keys used so far in this fragment.
	 *
	 * @var array<string, bool>
	 */
	private array $seen = [];

	/**
	 * Checks the fragment's app config and preferences.
	 *
	 * @var ExampleSetConfigShape
	 */
	private readonly ExampleSetConfigShape $config;

	/**
	 * Constructor.
	 *
	 * @param string $app  The app that ships the fragment.
	 * @param string $file The fragment file, for messages.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly string $app,
		private readonly string $file,
	) {
		$this->config = new ExampleSetConfigShape(app: $app, file: $file);
	}//end __construct()

	/**
	 * The fragment as the installer reads it.
	 *
	 * @param array<string, mixed> $fragment The parsed file.
	 *
	 * @return array<string, mixed> `app`, `file`, `dir`, `objects`, `changes`, `groups`, `serviceAccounts`,
	 *                              `appConfig`, `userPreferences`, every keyed-row list, and `archival`.
	 *
	 * @throws ExampleSetException When an entry lacks a part it needs, or a key is used twice.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-is-gathered-from-every-enabled-app-in-a-declared-order-req-exs-001
	 */
	public function shape(array $fragment): array {
		$shaped = $this->entryLists(fragment: $fragment);
		foreach (self::KEYED_ROWS as $part => $noun) {
			$shaped[$part] = $this->keyedRows(declared: (array) ($fragment[$part] ?? []), noun: $noun);
		}

		$shaped['archival'] = $this->archival(declared: (array) ($fragment['archival'] ?? []));

		return $shaped;
	}//end shape()

	/**
	 * The fragment's own fields and its objects, changes, groups, service accounts, app config and preferences.
	 *
	 * @param array<string, mixed> $fragment The parsed file.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws ExampleSetException When an entry lacks a part it needs, or a key is used twice.
	 */
	private function entryLists(array $fragment): array {
		$list = static fn (string $part): array => (array) ($fragment[$part] ?? []);

		return [
			'app'             => $this->app,
			'file'            => $this->file,
			'dir'             => (string) ($fragment['@dir'] ?? ''),
			'objects'         => $this->entries(declared: $list('objects'), kind: 'object'),
			'changes'         => $this->entries(declared: $list('changes'), kind: 'change'),
			'groups'          => array_values(array_filter($list('groups'), 'is_array')),
			'serviceAccounts' => $this->each(check: $this->serviceAccount(...), declared: $list('serviceAccounts')),
			'appConfig'       => $this->each(check: $this->config->appConfig(...), declared: $list('appConfig')),
			'userPreferences' => $this->each(check: $this->config->userPreference(...), declared: $list('userPreferences')),
		];
	}//end entryLists()

	/**
	 * Every declared entry through one check, which takes the entry's position and the entry.
	 *
	 * @param callable                 $check    The check.
	 * @param array<int|string, mixed> $declared The declared entries.
	 *
	 * @return array<int, mixed>
	 */
	private function each(callable $check, array $declared): array {
		return array_map($check, array_keys($declared), $declared);
	}//end each()

	/**
	 * The objects or changes of the fragment, each checked for its parts and a key of its own.
	 *
	 * @param array<int|string, mixed> $declared The declared entries.
	 * @param string                   $kind     `object` or `change`.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws ExampleSetException When an entry lacks a part, or a key is used twice.
	 */
	private function entries(array $declared, string $kind): array {
		$entries = [];
		foreach ($declared as $index => $entry) {
			$key = $this->keyOf(entry: $entry);
			if ($key === '' || $this->complete(entry: (array) $entry, kind: $kind) === false) {
				throw new ExampleSetException($this->file.': '.$kind.' '.$index.' needs '.self::NEEDS[$kind].'.');
			}

			$this->claim(key: $key);
			$entries[] = (array) $entry;
		}

		return $entries;
	}//end entries()

	/**
	 * Claim an object or change key for this fragment.
	 *
	 * @param string $key The key.
	 *
	 * @return void
	 *
	 * @throws ExampleSetException When the fragment already used the key.
	 */
	private function claim(string $key): void {
		if (isset($this->seen[$key]) === true) {
			throw new ExampleSetException($this->file.': the key "'.$key.'" is used twice.');
		}

		$this->seen[$key] = true;
	}//end claim()

	/**
	 * Whether an object or change has every part it needs.
	 *
	 * @param array<string, mixed> $entry The entry.
	 * @param string               $kind  `object` or `change`.
	 *
	 * @return bool
	 */
	private function complete(array $entry, string $kind): bool {
		if (($entry['register'] ?? '') === '' || ($entry['schema'] ?? '') === '') {
			return false;
		}

		if ($kind === 'object') {
			return $this->completeObject(entry: $entry);
		}

		return $this->completeChange(entry: $entry);
	}//end complete()

	/**
	 * Whether an object has data and only safe files.
	 *
	 * @param array<string, mixed> $entry The object.
	 *
	 * @return bool
	 */
	private function completeObject(array $entry): bool {
		return is_array($entry['data'] ?? null) === true && $this->safeFiles(files: (array) ($entry['files'] ?? [])) === true;
	}//end completeObject()

	/**
	 * Whether a change names what it changes (a where or an object) and how (a set or an append).
	 *
	 * @param array<string, mixed> $entry The change.
	 *
	 * @return bool
	 */
	private function completeChange(array $entry): bool {
		return (is_array($entry['where'] ?? null) === true || is_string($entry['object'] ?? null) === true)
			&& (is_array($entry['set'] ?? null) === true || is_array($entry['append'] ?? null) === true);
	}//end completeChange()

	/**
	 * Whether every file of an object names a path inside its fragment's folder.
	 *
	 * @param array<int|string, mixed> $files The declared files.
	 *
	 * @return bool
	 */
	private function safeFiles(array $files): bool {
		foreach ($files as $file) {
			if (is_array($file) === false || $this->safePath(path: (string) ($file['path'] ?? '')) === false) {
				return false;
			}
		}

		return true;
	}//end safeFiles()

	/**
	 * Whether a path is relative, inside its folder, and without `..`, a backslash or a NUL.
	 *
	 * @param string $path The declared path.
	 *
	 * @return bool
	 */
	private function safePath(string $path): bool {
		if ($path === '' || str_starts_with($path, '/') === true || preg_match('#(^|/)\.\.(/|$)#', $path) === 1) {
			return false;
		}

		return str_contains($path, '\\') === false && str_contains($path, "\0") === false;
	}//end safePath()

	/**
	 * One `{key, data}` list of the fragment: a key each, unique in the list, and the row's fields.
	 *
	 * @param array<int|string, mixed> $declared The declared rows.
	 * @param string                   $noun     The rows' noun, for messages.
	 *
	 * @return array<int, array{key: string, data: array<string, mixed>}>
	 *
	 * @throws ExampleSetException When a row lacks a key or fields, or a key is used twice.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-tasks-on-its-objects
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	private function keyedRows(array $declared, string $noun): array {
		$rows = [];
		foreach ($declared as $index => $entry) {
			$row = $this->keyedRow(label: $noun.' '.$index, entry: (array) $entry);
			if (isset($rows['k'.$row['key']]) === true) {
				throw new ExampleSetException($this->file.': '.$noun.' key "'.$row['key'].'" is used twice.');
			}

			$rows['k'.$row['key']] = $row;
		}

		return array_values($rows);
	}//end keyedRows()

	/**
	 * One `{key, data}` row.
	 *
	 * @param string               $label The row's noun and position, for messages.
	 * @param array<string, mixed> $entry The declared row.
	 *
	 * @return array{key: string, data: array<string, mixed>}
	 *
	 * @throws ExampleSetException When the row lacks a key or fields.
	 */
	private function keyedRow(string $label, array $entry): array {
		$key = ($entry['key'] ?? null);
		if (is_string($key) === false || $key === '' || is_array($entry['data'] ?? null) === false) {
			throw new ExampleSetException($this->file.': '.$label.' needs a key and its fields (data).');
		}

		return ['key' => $key, 'data' => $entry['data']];
	}//end keyedRow()

	/**
	 * The archival setting rows: only OpenRegister's own fragment may write OpenRegister's setting.
	 *
	 * @param array<int|string, mixed> $declared The declared rows.
	 *
	 * @return array<int, array{key: string, data: array<string, mixed>}>
	 *
	 * @throws ExampleSetException When another app declares the part, or a row lacks a key or fields.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-openregisters-fragment-may-name-the-destruction-list-register-and-schema
	 */
	private function archival(array $declared): array {
		if ($declared !== [] && $this->app !== ExampleSetOrder::CAST_APP) {
			throw new ExampleSetException($this->file.': archival is OpenRegister\'s own setting; only OpenRegister\'s fragment may write it.');
		}

		return $this->keyedRows(declared: $declared, noun: 'archival setting');
	}//end archival()

	/**
	 * One service account: a key and an account id.
	 *
	 * @param int|string $index   The account's position, for messages.
	 * @param mixed      $account The declared account.
	 *
	 * @return array{key: string, uid: string, displayName: string, groups: array<int, string>}
	 *
	 * @throws ExampleSetException When the account lacks a key or a valid account id.
	 */
	private function serviceAccount(int|string $index, mixed $account): array {
		$uid = (string) (((array) $account)['uid'] ?? '');
		if ($this->keyOf(entry: $account) === '' || preg_match('/^[a-z0-9._-]{1,64}$/', $uid) !== 1) {
			throw new ExampleSetException(
				$this->file.': service account '.$index.' needs a key and an account id of lower-case letters, digits, dots, dashes or underscores.'
			);
		}

		return [
			'key'         => $this->keyOf(entry: $account),
			'uid'         => $uid,
			'displayName' => (string) ($account['displayName'] ?? $uid),
			'groups'      => array_values(array_map('strval', (array) ($account['groups'] ?? []))),
		];
	}//end serviceAccount()
}//end class
