<?php

/**
 * OpenRegister Example Set Catalogue
 *
 * Finds the fragments of an example set in every enabled app, at
 * `lib/Settings/example-sets/<set>.json`, and turns them into one plan: the
 * cast from OpenRegister's file, then every fragment in the order their
 * `after` lists ask for. A file that does not parse, a cast in another app's
 * file, a duplicate key or a cycle refuses the whole set.
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
 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-set-is-gathered-from-every-enabled-app-in-a-declared-order-req-exs-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use OCP\App\IAppManager;
use Throwable;

/**
 * Gathers an example set from the enabled apps.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) One gathering: discovery, the cast rule and every entry's shape.
 *
 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-set-is-gathered-from-every-enabled-app-in-a-declared-order-req-exs-001
 */
class ExampleSetCatalogue {
	/**
	 * Where an app keeps its fragments, relative to the app's root.
	 */
	public const FOLDER = 'lib/Settings/example-sets';

	/**
	 * What a set id may look like; it becomes part of a file name and a config key.
	 */
	private const SET_ID = '/^[a-z0-9][a-z0-9-]{0,62}$/';

	/**
	 * What each kind of entry needs, for the message that refuses one.
	 */
	private const NEEDS = [
		'object' => 'a key, a register, a schema, data, and files with a relative path inside the fragment\'s folder',
		'change' => 'a key, a register, a schema, a where or an object, and a set or an append',
	];

	/**
	 * Constructor.
	 *
	 * @param IAppManager   $appManager Names the enabled apps and where they live.
	 * @param ExampleSetOrder $order      Orders the fragments.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ExampleSetOrder $order,
	) {
	}//end __construct()

	/**
	 * Every set any enabled app ships a fragment of, with those apps.
	 *
	 * @return array<string, array<int, string>> App ids by set id, both sorted.
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-an-administrator-can-list-the-sets-req-exs-008
	 */
	public function sets(): array {
		$sets = [];
		foreach ($this->enabledApps() as $app) {
			$folder = $this->folderOf(app: $app);
			if ($folder === null) {
				continue;
			}

			$files = glob($folder.'/*.json');
			if ($files === false) {
				continue;
			}

			foreach ($files as $file) {
				$set = basename($file, '.json');
				if (preg_match(self::SET_ID, $set) === 1) {
					$sets[$set][] = $app;
				}
			}
		}

		ksort($sets);
		foreach ($sets as $set => $apps) {
			sort($apps);
			$sets[$set] = $apps;
		}

		return $sets;
	}//end sets()

	/**
	 * The plan of one set: its cast and its fragments in order.
	 *
	 * @param string $set The set id.
	 *
	 * @return array<string, mixed> `set`, `title`, `timezone`, `cast` (people, organisations and groups by key,
	 *                              plus `entries`, all three merged), `requires` (OpenRegister register slug to `{mock}`),
	 *                              `fragments` (each with `app`, `file`, `objects`, `changes` and `groups`) and
	 *                              `skipped` (named apps that are not enabled).
	 *
	 * @throws ExampleSetException When the set cannot be gathered; the message names why and where.
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-set-is-gathered-from-every-enabled-app-in-a-declared-order-req-exs-001
	 */
	public function gather(string $set): array {
		if (preg_match(self::SET_ID, $set) !== 1) {
			throw new ExampleSetException('"'.$set.'" is not a set id: use lower-case letters, digits and dashes.');
		}

		$enabled   = $this->enabledApps();
		$fragments = [];
		foreach ($enabled as $app) {
			$folder = $this->folderOf(app: $app);
			if ($folder !== null && is_file($folder.'/'.$set.'.json') === true) {
				$fragments[$app] = $this->withParts(
					app: $app,
					fragment: $this->read(app: $app, file: $folder.'/'.$set.'.json'),
					folder: $folder.'/'.$set.'.d'
				);
			}
		}

		$castFile = $fragments[ExampleSetOrder::CAST_APP] ?? null;
		if ($castFile === null || is_array($castFile['cast'] ?? null) === false) {
			throw new ExampleSetException(
				'There is no example set "'.$set.'": OpenRegister ships no '.self::FOLDER.'/'.$set.'.json with a cast.'
			);
		}

		$after = [];
		foreach ($fragments as $app => $fragment) {
			$after[$app] = array_values(array_map('strval', (array) ($fragment['after'] ?? [])));
		}

		$ordered = $this->order->order(
			after: $after,
			enabled: $enabled,
			expected: array_values(array_map('strval', (array) ($castFile['apps'] ?? [])))
		);

		$plan = [
			'set'       => $set,
			'title'     => (string) ($castFile['title'] ?? $set),
			'timezone'  => (string) ($castFile['timezone'] ?? 'UTC'),
			'cast'      => $this->cast(cast: $castFile['cast'], file: $castFile['@file'], set: $set),
			'requires'  => $this->requiredRegisters(declared: (array) ($castFile['requires'] ?? []), file: $castFile['@file']),
			'organisations' => $this->organisationEntities(
				declared: (array) ($castFile['cast']['openregisterOrganisations'] ?? []),
				file: $castFile['@file'],
				people: (array) ($castFile['cast']['people'] ?? [])
			),
			'fragments' => [],
			'skipped'   => $ordered['skipped'],
		];
		foreach ($ordered['order'] as $app) {
			$plan['fragments'][] = $this->fragment(app: $app, fragment: $fragments[$app]);
		}

		$this->refuseSharedAccountKeys(plan: $plan);

		return $plan;
	}//end gather()

	/**
	 * Refuse a service account key used twice in the set, or also used by the cast.
	 *
	 * @param array<string, mixed> $plan The plan.
	 *
	 * @return void
	 *
	 * @throws ExampleSetException When a key is used twice.
	 */
	private function refuseSharedAccountKeys(array $plan): void {
		$accounts = [];
		foreach ($plan['fragments'] as $fragment) {
			foreach ($fragment['serviceAccounts'] as $account) {
				if (isset($accounts[$account['key']]) === true || isset($plan['cast']['entries'][$account['key']]) === true) {
					throw new ExampleSetException($fragment['file'].': the service account key "'.$account['key'].'" is used twice in this set.');
				}

				$accounts[$account['key']] = true;
			}
		}
	}//end refuseSharedAccountKeys()

	/**
	 * A fragment with the parts in its `<set>.d/` folder appended, in file-name order.
	 *
	 * A part adds lists to its app's fragment (`objects`, `changes`, `appConfig` and the
	 * other parts a fragment declares as lists), so two seed areas can live in two files.
	 * A part may not set anything else: the fragment's own settings stay in its main file.
	 * Relative file paths in a part resolve from the main file's folder.
	 *
	 * @param string               $app      The app id.
	 * @param array<string, mixed> $fragment The main fragment, as read.
	 * @param string               $folder   The parts folder.
	 *
	 * @return array<string, mixed> The fragment with every part's lists appended.
	 *
	 * @throws ExampleSetException When a part does not parse or sets a value that is not a list.
	 */
	private function withParts(string $app, array $fragment, string $folder): array {
		$files = glob($folder.'/*.json');
		if ($files === false || $files === []) {
			return $fragment;
		}

		sort($files);
		foreach ($files as $file) {
			$part  = $this->read(app: $app, file: $file);
			$named = $app.'/'.self::FOLDER.'/'.basename($folder).'/'.basename($file);
			foreach ($part as $key => $value) {
				if (self::isPartNote(key: (string) $key) === true) {
					continue;
				}

				if (is_array($value) === false || array_is_list($value) === false) {
					throw new ExampleSetException($named.': a part may only add lists; "'.$key.'" belongs in the main file.');
				}

				$fragment[$key] = array_merge((array) ($fragment[$key] ?? []), $value);
			}
		}

		return $fragment;
	}//end withParts()

	/**
	 * Whether a part's key is a note rather than content: `set`, `$comment`, or what read() adds (`@file`, `@dir`).
	 *
	 * @param string $key The key.
	 *
	 * @return bool True for a note.
	 */
	private static function isPartNote(string $key): bool {
		return $key === 'set' || str_starts_with($key, '@') === true || str_starts_with($key, '$') === true;
	}//end isPartNote()

	/**
	 * Read and parse one fragment file.
	 *
	 * @param string $app  The app id.
	 * @param string $file The file's path.
	 *
	 * @return array<string, mixed> The fragment, with `@file` set to a path that names the app.
	 *
	 * @throws ExampleSetException When the file does not parse, or carries a cast outside OpenRegister.
	 */
	private function read(string $app, string $file): array {
		$named   = $app.'/'.self::FOLDER.'/'.basename($file);
		$decoded = json_decode((string) file_get_contents($file), true);
		if (is_array($decoded) === false) {
			throw new ExampleSetException($named.' is not valid JSON: '.json_last_error_msg());
		}

		if ($app !== ExampleSetOrder::CAST_APP && array_key_exists('cast', $decoded) === true) {
			throw new ExampleSetException(
				$named.' carries a cast. The cast of a set lives once, in OpenRegister; refer to its members with {{cast:key.field}}.'
			);
		}

		$decoded['@file'] = $named;
		$decoded['@dir']  = dirname($file);

		return $decoded;
	}//end read()

	/**
	 * The cast, keyed, with every key checked to be unique across people, organisations and groups.
	 *
	 * Every person and organisation gets a `contactsUid` when it declares none:
	 * `example-set-<set>-<key>`, stable across installs, so an app that keeps a
	 * Contacts uid can quote `{{cast:key.contactsUid}}`.
	 *
	 * @param array<string, mixed> $cast The declared cast.
	 * @param string               $file The cast file, for messages.
	 * @param string               $set  The set id.
	 *
	 * @return array{people: array<string, array<string, mixed>>, organisations: array<string, array<string, mixed>>,
	 *               groups: array<string, array<string, mixed>>, entries: array<string, array<string, mixed>>}
	 *
	 * @throws ExampleSetException When a key is missing or used twice.
	 */
	private function cast(array $cast, string $file, string $set): array {
		$keyed = ['people' => [], 'organisations' => [], 'groups' => [], 'entries' => []];
		foreach (['people', 'organisations', 'groups'] as $part) {
			foreach ((array) ($cast[$part] ?? []) as $entry) {
				$key = $this->keyOf(entry: $entry);
				if ($key === '' || isset($keyed['entries'][$key]) === true) {
					throw new ExampleSetException($file.': every cast entry needs a key of its own; "'.$key.'" in '.$part.' is missing or used twice.');
				}

				if ($part !== 'groups' && is_string($entry['contactsUid'] ?? null) === false) {
					$entry['contactsUid'] = 'example-set-'.$set.'-'.$key;
				}

				$keyed[$part][$key]    = $entry;
				$keyed['entries'][$key] = $entry;
			}
		}

		return $keyed;
	}//end cast()

	/**
	 * One fragment as the installer reads it.
	 *
	 * @param string               $app      The app id.
	 * @param array<string, mixed> $fragment The parsed file.
	 *
	 * @return array{app: string, file: string, dir: string, objects: array<int, array<string, mixed>>,
	 *               changes: array<int, array<string, mixed>>, groups: array<int, array<string, mixed>>,
	 *               serviceAccounts: array<int, array{key: string, uid: string, displayName: string, groups: array<int, string>}>,
	 *               appConfig: array<int, array{key: string, value: mixed, generate: bool}>,
	 *               userPreferences: array<int, array{user: string, key: string, value: string}>,
	 *               tasks: array<int, array{key: string, data: array<string, mixed>}>,
	 *               views: array<int, array{key: string, data: array<string, mixed>}>}
	 *
	 * @throws ExampleSetException When an object or change lacks a key, register or schema, or a key is used twice.
	 */
	private function fragment(string $app, array $fragment): array {
		$file = (string) $fragment['@file'];
		$seen = [];

		return [
			'app'             => $app,
			'file'            => $file,
			'dir'             => (string) ($fragment['@dir'] ?? ''),
			'objects'         => $this->entries(file: $file, declared: (array) ($fragment['objects'] ?? []), kind: 'object', seen: $seen),
			'changes'         => $this->entries(file: $file, declared: (array) ($fragment['changes'] ?? []), kind: 'change', seen: $seen),
			'groups'          => array_values(array_filter((array) ($fragment['groups'] ?? []), 'is_array')),
			'serviceAccounts' => $this->serviceAccounts(file: $file, declared: (array) ($fragment['serviceAccounts'] ?? [])),
			'appConfig'       => $this->appConfig(app: $app, file: $file, declared: (array) ($fragment['appConfig'] ?? [])),
			'userPreferences' => $this->userPreferences(app: $app, file: $file, declared: (array) ($fragment['userPreferences'] ?? [])),
			'tasks'           => $this->keyedRows(file: $file, declared: (array) ($fragment['tasks'] ?? []), noun: 'task'),
			'views'           => $this->keyedRows(file: $file, declared: (array) ($fragment['views'] ?? []), noun: 'view'),
			'watchers'        => $this->keyedRows(file: $file, declared: (array) ($fragment['watchers'] ?? []), noun: 'watcher'),
			'parties'         => $this->keyedRows(file: $file, declared: (array) ($fragment['parties'] ?? []), noun: 'party'),
			'notes'           => $this->keyedRows(file: $file, declared: (array) ($fragment['notes'] ?? []), noun: 'note'),
			'readStates'      => $this->keyedRows(file: $file, declared: (array) ($fragment['readStates'] ?? []), noun: 'read state'),
			'flows'           => $this->keyedRows(file: $file, declared: (array) ($fragment['flows'] ?? []), noun: 'flow'),
			'exportRuns'      => $this->keyedRows(file: $file, declared: (array) ($fragment['exportRuns'] ?? []), noun: 'export'),
			'deckCards'       => $this->keyedRows(file: $file, declared: (array) ($fragment['deckCards'] ?? []), noun: 'deck card'),
			'appointments'    => $this->keyedRows(file: $file, declared: (array) ($fragment['appointments'] ?? []), noun: 'appointment'),
			'casePlans'       => $this->keyedRows(file: $file, declared: (array) ($fragment['casePlans'] ?? []), noun: 'case plan'),
			'collectivesPages' => $this->keyedRows(file: $file, declared: (array) ($fragment['collectivesPages'] ?? []), noun: 'collectives page'),
			'talkConversations' => $this->keyedRows(file: $file, declared: (array) ($fragment['talkConversations'] ?? []), noun: 'talk conversation'),
			'auditEntries'    => $this->keyedRows(file: $file, declared: (array) ($fragment['auditEntries'] ?? []), noun: 'audit entry'),
			'timelineEntries' => $this->keyedRows(file: $file, declared: (array) ($fragment['timelineEntries'] ?? []), noun: 'timeline entry'),
			'emailLinks'      => $this->keyedRows(file: $file, declared: (array) ($fragment['emailLinks'] ?? []), noun: 'email link'),
			'archival'        => $this->archival(app: $app, file: $file, declared: (array) ($fragment['archival'] ?? [])),
		];
	}//end fragment()

	/**
	 * The tasks or views of a fragment: a key each, unique in the fragment, and the row's fields.
	 *
	 * @param string            $file     The fragment file, for messages.
	 * @param array<int, mixed> $declared The declared entries.
	 * @param string            $noun     `task` or `view`, for messages.
	 *
	 * @return array<int, array{key: string, data: array<string, mixed>}>
	 *
	 * @throws ExampleSetException When an entry lacks a key or fields, or a key is used twice.
	 *
	 * @spec openspec/changes/example-set-tasks/specs/example-sets/spec.md#requirement-a-fragment-may-put-tasks-on-its-objects
	 * @spec openspec/changes/example-set-views/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
	 */
	private function keyedRows(string $file, array $declared, string $noun): array {
		$entries = [];
		$seen    = [];
		foreach ($declared as $index => $entry) {
			$entry = (array) $entry;
			$key   = ($entry['key'] ?? null);
			if (is_string($key) === false || $key === '' || is_array($entry['data'] ?? null) === false) {
				throw new ExampleSetException($file.': '.$noun.' '.$index.' needs a key and its fields (data).');
			}

			if (isset($seen[$key]) === true) {
				throw new ExampleSetException($file.': '.$noun.' key "'.$key.'" is used twice.');
			}

			$seen[$key] = true;
			$entries[]  = ['key' => $key, 'data' => $entry['data']];
		}

		return $entries;
	}//end keyedRows()

	/**
	 * The archival setting rows of a fragment: only OpenRegister's own fragment may write OpenRegister's setting.
	 *
	 * @param string            $app      The app that ships the fragment.
	 * @param string            $file     The fragment file, for messages.
	 * @param array<int, mixed> $declared The declared rows.
	 *
	 * @return array<int, array{key: string, data: array<string, mixed>}>
	 *
	 * @throws ExampleSetException When another app declares the part, or a row lacks a key or fields.
	 *
	 * @spec openspec/changes/example-set-archival-setting/specs/example-sets/spec.md#requirement-openregisters-fragment-may-name-the-destruction-list-register-and-schema
	 */
	private function archival(string $app, string $file, array $declared): array {
		if ($declared !== [] && $app !== ExampleSetOrder::CAST_APP) {
			throw new ExampleSetException($file.': archival is OpenRegister\'s own setting; only OpenRegister\'s fragment may write it.');
		}

		return $this->keyedRows(file: $file, declared: $declared, noun: 'archival setting');
	}//end archival()

	/**
	 * The service accounts of a fragment: a key and an account id each.
	 *
	 * @param string            $file     The fragment file, for messages.
	 * @param array<int, mixed> $declared The declared accounts.
	 *
	 * @return array<int, array{key: string, uid: string, displayName: string, groups: array<int, string>}>
	 *
	 * @throws ExampleSetException When an account lacks a key or a valid account id.
	 */
	private function serviceAccounts(string $file, array $declared): array {
		$accounts = [];
		foreach ($declared as $index => $account) {
			$uid = (string) (((array) $account)['uid'] ?? '');
			if ($this->keyOf(entry: $account) === '' || preg_match('/^[a-z0-9._-]{1,64}$/', $uid) !== 1) {
				throw new ExampleSetException(
					$file.': service account '.$index.' needs a key and an account id of lower-case letters, digits, dots, dashes or underscores.'
				);
			}

			$accounts[] = [
				'key'         => $this->keyOf(entry: $account),
				'uid'         => $uid,
				'displayName' => (string) ($account['displayName'] ?? $uid),
				'groups'      => array_values(array_map('strval', (array) ($account['groups'] ?? []))),
			];
		}

		return $accounts;
	}//end serviceAccounts()

	/**
	 * The app config entries of a fragment, each for the fragment's own app.
	 *
	 * @param string            $app      The app that ships the fragment.
	 * @param string            $file     The fragment file, for messages.
	 * @param array<int, mixed> $declared The declared entries.
	 *
	 * @return array<int, array{key: string, value: mixed, generate: bool, type: string}>
	 *
	 * @throws ExampleSetException When an entry names another app, lacks a key, has no value to write, or a type it
	 *                             cannot write or whose value does not fit.
	 */
	private function appConfig(string $app, string $file, array $declared): array {
		$entries = [];
		foreach ($declared as $index => $entry) {
			$entry = (array) $entry;
			if ((string) ($entry['app'] ?? $app) !== $app) {
				throw new ExampleSetException($file.': app config '.$index.' is for "'.$entry['app'].'"; a fragment sets only its own app\'s config.');
			}

			$generate = (($entry['generate'] ?? false) === true);
			if (is_string($entry['key'] ?? null) === false || $entry['key'] === '' || ($generate === false && is_scalar($entry['value'] ?? null) === false)) {
				throw new ExampleSetException($file.': app config '.$index.' needs a key and a value, or generate: true.');
			}

			$entries[] = [
				'key'      => $entry['key'],
				'value'    => ($entry['value'] ?? ''),
				'generate' => $generate,
				'type'     => $this->appConfigType(file: $file, index: (int) $index, entry: $entry, generate: $generate),
				'atLeast'  => $this->appConfigFloor(file: $file, index: (int) $index, entry: $entry, generate: $generate),
			];
		}

		return $entries;
	}//end appConfig()

	/**
	 * Whether an app config entry is a floor (`atLeast`): a running counter the set raises to its value but never
	 * lowers, which the app may move on after the install.
	 *
	 * @param string               $file     The fragment file, for messages.
	 * @param int                  $index    The entry's position, for messages.
	 * @param array<string, mixed> $entry    The declared entry.
	 * @param bool                 $generate Whether the set generates the value.
	 *
	 * @return bool
	 *
	 * @throws ExampleSetException When a floor is not a written integer.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Whether the value is generated is the entry's own fact.
	 *
	 * @spec openspec/changes/example-set-app-config-at-least/specs/example-sets/spec.md#requirement-an-integer-app-config-entry-may-be-a-floor
	 */
	private function appConfigFloor(string $file, int $index, array $entry, bool $generate): bool {
		if (($entry['atLeast'] ?? false) !== true) {
			return false;
		}

		if ($generate === true || (string) ($entry['type'] ?? 'string') !== 'int') {
			throw new ExampleSetException($file.': app config '.$index.' ("'.(string) $entry['key'].'") is atLeast; only a written int can be a floor.');
		}

		return true;
	}//end appConfigFloor()

	/**
	 * The type an app config entry is written with: `string`, or `int` for a key its app reads as an integer.
	 *
	 * @param string               $file     The fragment file, for messages.
	 * @param int                  $index    The entry's position, for messages.
	 * @param array<string, mixed> $entry    The declared entry.
	 * @param bool                 $generate Whether the set generates the value.
	 *
	 * @return string
	 *
	 * @throws ExampleSetException When the type is not one a set writes, or an integer's value is no integer.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Whether the value is generated is the entry's own fact.
	 *
	 * @spec openspec/changes/example-set-typed-app-config/specs/example-sets/spec.md#requirement-an-app-config-entry-may-name-its-type
	 */
	private function appConfigType(string $file, int $index, array $entry, bool $generate): string {
		$type = (string) ($entry['type'] ?? 'string');
		if (in_array($type, ['string', 'int'], true) === false) {
			throw new ExampleSetException($file.': app config '.$index.' has type "'.$type.'"; a set writes string or int.');
		}

		if ($type === 'int' && $generate === false && preg_match('/^(-?\d+|\{\{[^{}]+\}\})$/', (string) ($entry['value'] ?? '')) !== 1) {
			throw new ExampleSetException($file.': app config '.$index.' ("'.(string) $entry['key'].'") is an integer; its value is not.');
		}

		return $type;
	}//end appConfigType()

	/**
	 * The per-user preferences of a fragment, each for the fragment's own app.
	 *
	 * @param string            $app      The app that ships the fragment.
	 * @param string            $file     The fragment file, for messages.
	 * @param array<int, mixed> $declared The declared entries.
	 *
	 * @return array<int, array{user: string, key: string, value: string}>
	 *
	 * @throws ExampleSetException When an entry names another app, or lacks a user, a key or a value.
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-fragment-may-set-its-own-apps-preferences-for-the-sets-own-accounts-req-exs-024
	 */
	private function userPreferences(string $app, string $file, array $declared): array {
		$entries = [];
		foreach ($declared as $index => $entry) {
			$entry = (array) $entry;
			if ((string) ($entry['app'] ?? $app) !== $app) {
				throw new ExampleSetException($file.': user preference '.$index.' is for "'.$entry['app'].'"; a fragment sets only its own app\'s preferences.');
			}

			$user = ($entry['user'] ?? null);
			$key  = ($entry['key'] ?? null);
			if (is_string($user) === false || $user === '' || is_string($key) === false || $key === '' || is_scalar($entry['value'] ?? null) === false) {
				throw new ExampleSetException($file.': user preference '.$index.' needs a user, a key and a value.');
			}

			$entries[] = ['user' => $user, 'key' => $key, 'value' => (string) $entry['value']];
		}

		return $entries;
	}//end userPreferences()

	/**
	 * The OpenRegister registers the cast requires, keyed by slug.
	 *
	 * An entry is a slug, imported from OpenRegister's real descriptor, or
	 * `{"register": "<slug>", "mock": true}`, imported from its mock (sample)
	 * descriptor.
	 *
	 * @param array<int, mixed> $declared The declared requirements.
	 * @param string            $file     The cast file, for messages.
	 *
	 * @return array<string, array{mock: bool}> Slug to how it is imported.
	 *
	 * @throws ExampleSetException When an entry names no register.
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-a-set-imports-the-openregister-registers-it-requires-req-exs-009
	 */
	private function requiredRegisters(array $declared, string $file): array {
		$registers = [];
		foreach ($declared as $index => $entry) {
			if (is_array($entry) === false) {
				$entry = ['register' => $entry];
			}

			$slug = $entry['register'] ?? '';
			$mock = $entry['mock'] ?? false;
			if (is_string($slug) === false || $slug === '' || is_bool($mock) === false) {
				throw new ExampleSetException(
					$file.': requires '.$index.' needs a register slug, or {"register": "<slug>", "mock": true}.'
				);
			}

			$registers[$slug] = ['mock' => $mock];
		}

		return $registers;
	}//end requiredRegisters()

	/**
	 * The OpenRegister organisations the cast declares, each with its quota and its members' accounts.
	 *
	 * @param array<int, mixed>  $declared The declared organisations.
	 * @param string             $file     The cast file, for messages.
	 * @param array<int, mixed>  $people   The cast's people, to turn member keys into accounts.
	 *
	 * @return array<int, array{key: string, name: string, slug: string, description: string, quota: array<string, int>, members: array<int, string>}>
	 *
	 * @throws ExampleSetException When an organisation lacks a key, name or slug, names a quota that is not a whole number,
	 *                             or a member the cast does not hold.
	 *
	 * @spec openspec/changes/example-set-organisation-quota-and-members/specs/example-sets/spec.md#requirement-a-cast-organisation-may-declare-its-quota-and-its-members
	 */
	private function organisationEntities(array $declared, string $file, array $people=[]): array {
		$accounts = [];
		foreach ($people as $person) {
			$person = (array) $person;
			$accounts[(string) ($person['key'] ?? '')] = (string) ($person['uid'] ?? '');
		}

		$organisations = [];
		foreach ($declared as $index => $organisation) {
			$organisation = (array) $organisation;
			if ($this->keyOf(entry: $organisation) === '' || (string) ($organisation['name'] ?? '') === '' || (string) ($organisation['slug'] ?? '') === '') {
				throw new ExampleSetException($file.': OpenRegister organisation '.$index.' needs a key, a name and a slug.');
			}

			$organisations[] = [
				'key'         => $this->keyOf(entry: $organisation),
				'name'        => (string) $organisation['name'],
				'slug'        => (string) $organisation['slug'],
				'description' => (string) ($organisation['description'] ?? ''),
				'type'        => (string) ($organisation['type'] ?? ''),
				'contactEmail' => (string) ($organisation['contactEmail'] ?? ''),
				'quota'       => $this->organisationQuota(declared: ($organisation['quota'] ?? []), file: $file, slug: (string) $organisation['slug']),
				'members'     => $this->organisationMembers(
					declared: ($organisation['members'] ?? []),
					accounts: $accounts,
					file: $file,
					slug: (string) $organisation['slug']
				),
			];
		}

		return $organisations;
	}//end organisationEntities()

	/**
	 * An organisation's declared quota: storage (bytes), requests, bandwidth,
	 * users and objects, each a whole number, and objectsSchema, the slug of the
	 * schema the object quota counts.
	 *
	 * @param mixed  $declared The declared quota.
	 * @param string $file     The cast file, for messages.
	 * @param string $slug     The organisation's slug, for messages.
	 *
	 * @return array<string, int|string>
	 *
	 * @throws ExampleSetException When a key is unknown or a value is not a whole number.
	 */
	private function organisationQuota(mixed $declared, string $file, string $slug): array {
		$quota = [];
		foreach ((array) $declared as $key => $value) {
			if ($key === 'objectsSchema' && is_string($value) === true && $value !== '') {
				$quota[$key] = $value;
				continue;
			}

			if (in_array($key, ['storage', 'requests', 'bandwidth', 'users', 'objects'], true) === false || is_int($value) === false || $value < 0) {
				throw new ExampleSetException(
					$file.': OpenRegister organisation "'.$slug.'": quota "'.$key.'" must be storage, requests, bandwidth, users or objects'
					.' with a whole number, or objectsSchema with a schema slug.'
				);
			}

			$quota[$key] = $value;
		}

		return $quota;
	}//end organisationQuota()

	/**
	 * The accounts of an organisation's declared members, which are cast people's keys.
	 *
	 * @param mixed                 $declared The declared member keys.
	 * @param array<string, string> $accounts Each cast person's account, by key.
	 * @param string                $file     The cast file, for messages.
	 * @param string                $slug     The organisation's slug, for messages.
	 *
	 * @return array<int, string>
	 *
	 * @throws ExampleSetException When a member is not a cast person with an account.
	 */
	private function organisationMembers(mixed $declared, array $accounts, string $file, string $slug): array {
		$members = [];
		foreach ((array) $declared as $key) {
			if (($accounts[(string) $key] ?? '') === '') {
				throw new ExampleSetException($file.': OpenRegister organisation "'.$slug.'": member "'.$key.'" is not a cast person with an account.');
			}

			$members[] = $accounts[(string) $key];
		}

		return $members;
	}//end organisationMembers()

	/**
	 * The objects or changes of a fragment, each checked for its parts and a key of its own.
	 *
	 * @param string               $file     The fragment file, for messages.
	 * @param array<int, mixed>    $declared The declared entries.
	 * @param string               $kind     `object` or `change`.
	 * @param array<string, bool>  $seen     Keys used so far in this fragment.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws ExampleSetException When an entry lacks a part, or a key is used twice.
	 */
	private function entries(string $file, array $declared, string $kind, array &$seen): array {
		$entries = [];
		foreach ($declared as $index => $entry) {
			$key = $this->keyOf(entry: $entry);
			if ($key === '' || $this->complete(entry: (array) $entry, kind: $kind) === false) {
				throw new ExampleSetException($file.': '.$kind.' '.$index.' needs '.self::NEEDS[$kind].'.');
			}

			if (isset($seen[$key]) === true) {
				throw new ExampleSetException($file.': the key "'.$key.'" is used twice.');
			}

			$seen[$key] = true;
			$entries[]  = (array) $entry;
		}

		return $entries;
	}//end entries()

	/**
	 * Whether every file of an object names a path inside its fragment's folder:
	 * relative, without `..`, a backslash or a NUL.
	 *
	 * @param array<int, mixed> $files The declared files.
	 *
	 * @return bool
	 */
	private function safeFiles(array $files): bool {
		foreach ($files as $file) {
			$path = '';
			if (is_array($file) === true) {
				$path = (string) ($file['path'] ?? '');
			}

			$outside = (str_starts_with($path, '/') === true || preg_match('#(^|/)\.\.(/|$)#', $path) === 1);
			if ($path === '' || $outside === true || str_contains($path, '\\') === true || str_contains($path, "\0") === true) {
				return false;
			}
		}

		return true;
	}//end safeFiles()

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
			return is_array($entry['data'] ?? null) === true && $this->safeFiles(files: (array) ($entry['files'] ?? [])) === true;
		}

		return (is_array($entry['where'] ?? null) === true || is_string($entry['object'] ?? null) === true)
			&& (is_array($entry['set'] ?? null) === true || is_array($entry['append'] ?? null) === true);
	}//end complete()

	/**
	 * The `key` of a declared entry, or '' when it has none.
	 *
	 * @param mixed $entry The declared entry.
	 *
	 * @return string
	 */
	private function keyOf(mixed $entry): string {
		if (is_array($entry) === false || is_scalar($entry['key'] ?? null) === false) {
			return '';
		}

		return (string) $entry['key'];
	}//end keyOf()

	/**
	 * The ids of every enabled app, sorted.
	 *
	 * @return array<int, string>
	 */
	private function enabledApps(): array {
		$apps = array_values(array_map('strval', $this->appManager->getEnabledApps()));
		sort($apps);

		return $apps;
	}//end enabledApps()

	/**
	 * The example-sets folder of an app, or null when it has none.
	 *
	 * @param string $app The app id.
	 *
	 * @return string|null
	 */
	private function folderOf(string $app): ?string {
		try {
			$folder = rtrim($this->appManager->getAppPath($app), '/').'/'.self::FOLDER;
		} catch (Throwable) {
			return null;
		}

		if (is_dir($folder) === false) {
			return null;
		}

		return $folder;
	}//end folderOf()
}//end class
