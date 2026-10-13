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
		'object' => 'a key, a register, a schema and data',
		'change' => 'a key, a register, a schema, a where, and a set or an append',
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
	 *                              plus `entries`, all three merged), `requires` (OpenRegister register slugs),
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
				$fragments[$app] = $this->read(app: $app, file: $folder.'/'.$set.'.json');
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
			'cast'      => $this->cast(cast: $castFile['cast'], file: $castFile['@file']),
			'requires'  => array_values(array_unique(array_map('strval', (array) ($castFile['requires'] ?? [])))),
			'fragments' => [],
			'skipped'   => $ordered['skipped'],
		];
		foreach ($ordered['order'] as $app) {
			$plan['fragments'][] = $this->fragment(app: $app, fragment: $fragments[$app]);
		}

		return $plan;
	}//end gather()

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

		return $decoded;
	}//end read()

	/**
	 * The cast, keyed, with every key checked to be unique across people, organisations and groups.
	 *
	 * @param array<string, mixed> $cast The declared cast.
	 * @param string               $file The cast file, for messages.
	 *
	 * @return array{people: array<string, array<string, mixed>>, organisations: array<string, array<string, mixed>>,
	 *               groups: array<string, array<string, mixed>>, entries: array<string, array<string, mixed>>}
	 *
	 * @throws ExampleSetException When a key is missing or used twice.
	 */
	private function cast(array $cast, string $file): array {
		$keyed = ['people' => [], 'organisations' => [], 'groups' => [], 'entries' => []];
		foreach (['people', 'organisations', 'groups'] as $part) {
			foreach ((array) ($cast[$part] ?? []) as $entry) {
				$key = $this->keyOf(entry: $entry);
				if ($key === '' || isset($keyed['entries'][$key]) === true) {
					throw new ExampleSetException($file.': every cast entry needs a key of its own; "'.$key.'" in '.$part.' is missing or used twice.');
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
	 * @return array{app: string, file: string, objects: array<int, array<string, mixed>>, changes: array<int, array<string, mixed>>,
	 *               groups: array<int, array<string, mixed>>}
	 *
	 * @throws ExampleSetException When an object or change lacks a key, register or schema, or a key is used twice.
	 */
	private function fragment(string $app, array $fragment): array {
		$file = (string) $fragment['@file'];
		$seen = [];

		return [
			'app'     => $app,
			'file'    => $file,
			'objects' => $this->entries(file: $file, declared: (array) ($fragment['objects'] ?? []), kind: 'object', seen: $seen),
			'changes' => $this->entries(file: $file, declared: (array) ($fragment['changes'] ?? []), kind: 'change', seen: $seen),
			'groups'  => array_values(array_filter((array) ($fragment['groups'] ?? []), 'is_array')),
		];
	}//end fragment()

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
			return is_array($entry['data'] ?? null);
		}

		return is_array($entry['where'] ?? null) === true
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
