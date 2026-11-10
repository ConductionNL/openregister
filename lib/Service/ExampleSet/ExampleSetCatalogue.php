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
 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-is-gathered-from-every-enabled-app-in-a-declared-order-req-exs-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use OCP\App\IAppManager;
use Throwable;

/**
 * Gathers an example set from the enabled apps.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-is-gathered-from-every-enabled-app-in-a-declared-order-req-exs-001
 */
class ExampleSetCatalogue implements ExampleSetCatalogueInterface {
	use ExampleSetKeyOf;

	/**
	 * Where an app keeps its fragments, relative to the app's root.
	 */
	public const FOLDER = 'lib/Settings/example-sets';

	/**
	 * What a set id may look like; it becomes part of a file name and a config key.
	 */
	private const SET_ID = '/^[a-z0-9][a-z0-9-]{0,62}$/';

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
	 * @return array<string, array<int, string>> App ids by set id, both sorted: the apps come from enabledApps(),
	 *                                           which sorts them.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-an-administrator-can-list-the-sets-req-exs-008
	 */
	public function sets(): array {
		$sets = [];
		foreach ($this->enabledApps() as $app) {
			foreach ($this->setsOf(app: $app) as $set) {
				$sets[$set][] = $app;
			}
		}

		ksort($sets);

		return $sets;
	}//end sets()

	/**
	 * The ids of the sets an app ships a fragment of, in file-name order.
	 *
	 * @param string $app The app id.
	 *
	 * @return array<int, string>
	 */
	private function setsOf(string $app): array {
		$folder = $this->folderOf(app: $app);
		$files  = false;
		if ($folder !== null) {
			$files = glob($folder.'/*.json');
		}

		if ($files === false) {
			return [];
		}

		$sets = array_map(static fn (string $file): string => basename($file, '.json'), $files);

		return array_values(array_filter($sets, static fn (string $set): bool => preg_match(self::SET_ID, $set) === 1));
	}//end setsOf()

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
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-is-gathered-from-every-enabled-app-in-a-declared-order-req-exs-001
	 */
	public function gather(string $set): array {
		$this->refuseSetId(set: $set);
		$enabled   = $this->enabledApps();
		$fragments = $this->fragmentsOf(set: $set, enabled: $enabled);
		$castFile  = $this->castFileOf(fragments: $fragments, set: $set);
		$ordered   = $this->orderOf(fragments: $fragments, enabled: $enabled);
		$plan      = $this->head(castFile: $castFile, set: $set);
		$plan['fragments'] = $this->shaped(fragments: $fragments, order: $ordered['order']);
		$plan['skipped']   = $ordered['skipped'];
		$this->refuseSharedAccountKeys(plan: $plan);

		return $plan;
	}//end gather()

	/**
	 * Refuse a set id that could not be part of a file name and a config key.
	 *
	 * @param string $set The set id.
	 *
	 * @return void
	 *
	 * @throws ExampleSetException When the id is not lower-case letters, digits and dashes.
	 */
	private function refuseSetId(string $set): void {
		if (preg_match(self::SET_ID, $set) !== 1) {
			throw new ExampleSetException('"'.$set.'" is not a set id: use lower-case letters, digits and dashes.');
		}
	}//end refuseSetId()

	/**
	 * The fragments as the installer reads them, in install order.
	 *
	 * @param array<string, array<string, mixed>> $fragments The fragments by app id.
	 * @param array<int, string>                  $order     The apps in install order.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws ExampleSetException When an entry lacks a part it needs, or a key is used twice.
	 */
	private function shaped(array $fragments, array $order): array {
		$shaped = [];
		foreach ($order as $app) {
			$shape    = new ExampleSetFragmentShape(app: $app, file: (string) $fragments[$app]['@file']);
			$shaped[] = $shape->shape(fragment: $fragments[$app]);
		}

		return $shaped;
	}//end shaped()

	/**
	 * Every enabled app's fragment of a set, with its parts appended, by app id.
	 *
	 * @param string             $set     The set id.
	 * @param array<int, string> $enabled The enabled apps.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @throws ExampleSetException When a fragment or part does not parse.
	 */
	private function fragmentsOf(string $set, array $enabled): array {
		$fragments = [];
		foreach ($enabled as $app) {
			$fragment = $this->fragmentOf(app: $app, set: $set);
			if ($fragment !== null) {
				$fragments[$app] = $fragment;
			}
		}

		return $fragments;
	}//end fragmentsOf()

	/**
	 * OpenRegister's fragment, which carries the cast.
	 *
	 * @param array<string, array<string, mixed>> $fragments The fragments by app id.
	 * @param string                              $set       The set id, for the message.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws ExampleSetException When OpenRegister ships no fragment of the set with a cast.
	 */
	private function castFileOf(array $fragments, string $set): array {
		$castFile = ($fragments[ExampleSetOrder::CAST_APP] ?? null);
		if ($castFile === null || is_array($castFile['cast'] ?? null) === false) {
			throw new ExampleSetException(
				'There is no example set "'.$set.'": OpenRegister ships no '.self::FOLDER.'/'.$set.'.json with a cast.'
			);
		}

		return $castFile;
	}//end castFileOf()

	/**
	 * The order the fragments install in, and the named apps that are not enabled.
	 *
	 * @param array<string, array<string, mixed>> $fragments The fragments by app id, OpenRegister's among them.
	 * @param array<int, string>                  $enabled   The enabled apps.
	 *
	 * @return array{order: array<int, string>, skipped: array<int, string>}
	 *
	 * @throws ExampleSetException When the fragments wait on each other in a cycle.
	 */
	private function orderOf(array $fragments, array $enabled): array {
		$strings = static fn (mixed $value): array => array_values(array_map('strval', (array) $value));

		return $this->order->order(
			after: array_map(static fn (array $fragment): array => $strings($fragment['after'] ?? []), $fragments),
			enabled: $enabled,
			expected: $strings($fragments[ExampleSetOrder::CAST_APP]['apps'] ?? [])
		);
	}//end orderOf()

	/**
	 * The plan's head: the set's own fields, its cast, the registers it requires and its organisations.
	 *
	 * @param array<string, mixed> $castFile OpenRegister's fragment.
	 * @param string               $set      The set id.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws ExampleSetException When the cast, a requirement or an organisation is incomplete.
	 */
	private function head(array $castFile, string $set): array {
		$shape = new ExampleSetCastShape(file: (string) $castFile['@file'], set: $set);

		return [
			'set'           => $set,
			'title'         => (string) ($castFile['title'] ?? $set),
			'timezone'      => (string) ($castFile['timezone'] ?? 'UTC'),
			'cast'          => $shape->cast(cast: $castFile['cast']),
			'requires'      => $shape->requires(declared: (array) ($castFile['requires'] ?? [])),
			'organisations' => $this->organisationsOf(castFile: $castFile),
		];
	}//end head()

	/**
	 * The OpenRegister organisations the cast declares.
	 *
	 * @param array<string, mixed> $castFile OpenRegister's fragment.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws ExampleSetException When an organisation is incomplete.
	 */
	private function organisationsOf(array $castFile): array {
		return (new ExampleSetCastOrganisations())->entities(
			declared: (array) ($castFile['cast']['openregisterOrganisations'] ?? []),
			file: $castFile['@file'],
			people: (array) ($castFile['cast']['people'] ?? [])
		);
	}//end organisationsOf()

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
	 * An app's fragment of a set, with its parts appended, or null when the app ships none.
	 *
	 * @param string $app The app id.
	 * @param string $set The set id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @throws ExampleSetException When the fragment or a part does not parse, or a part sets a value that is not a list.
	 */
	private function fragmentOf(string $app, string $set): ?array {
		$folder = $this->folderOf(app: $app);
		if ($folder === null) {
			return null;
		}

		return (new ExampleSetFragmentFile(app: $app, folder: $folder))->fragment(set: $set);
	}//end fragmentOf()

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
