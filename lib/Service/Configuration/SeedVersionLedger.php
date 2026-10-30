<?php

/**
 * The version each seed object of an app's register configuration was last shipped at.
 *
 * A seed object is updated when the shipped `@self.version` is higher than
 * the stored one. The stored object's own version cannot be that stored one:
 * every later save of the object (a user's edit) bumps its patch, so a seed
 * imported at `1.4.7` and edited once reads `1.4.8`, and the next release of
 * the seed at `1.4.8` would compare equal and never arrive, while a `1.5.0`
 * would replace the edit. The import therefore remembers, apart from the
 * object, the version it last shipped, and compares against that. The record
 * is written by the import alone, one map per app id in a lazy app-config
 * value, so no table changes.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

use OCP\IAppConfig;

/**
 * Remembers the shipped version of each seed object, per app id.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
class SeedVersionLedger {

	/**
	 * The app-config key prefix of one app id's map.
	 */
	private const KEY_PREFIX = 'imported_seed_versions_';

	/**
	 * Longest app config key Nextcloud accepts.
	 */
	private const MAX_KEY_LENGTH = 64;

	/**
	 * The maps read so far in this request, by app id.
	 *
	 * @var array<string, array<string, string>>
	 */
	private array $maps = [];

	/**
	 * The app ids whose map changed since it was last written.
	 *
	 * @var array<string, true>
	 */
	private array $dirty = [];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig OpenRegister's app config.
	 * @param string     $prefix    The app-config key prefix; another prefix keeps another value per seed, such as the
	 *                              hash of the content it was last shipped with.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly string $prefix = self::KEY_PREFIX,
	) {

	}//end __construct()

	/**
	 * The version a seed was last shipped at, or null when the import never recorded one.
	 *
	 * @param string $appId The importing app id.
	 * @param string $seed  The seed's identity: register id, schema id and slug.
	 *
	 * @return string|null The version.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function shipped(string $appId, string $seed): ?string {
		return ($this->map(appId: $appId)[$seed] ?? null);
	}//end shipped()

	/**
	 * Record the version a seed was shipped at, when it differs from the recorded one.
	 *
	 * Kept in memory until {@see self::flush()}: an app ships up to a hundred and more seeds in one import,
	 * and rewriting the whole map once per seed made the import quadratic in the map's size.
	 *
	 * @param string $appId   The importing app id.
	 * @param string $seed    The seed's identity: register id, schema id and slug.
	 * @param string $version The shipped version.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function record(string $appId, string $seed, string $version): void {
		$map = $this->map(appId: $appId);
		if (($map[$seed] ?? null) === $version) {
			return;
		}

		$map[$seed] = $version;
		$this->maps[$appId] = $map;
		$this->dirty[$appId] = true;
	}//end record()

	/**
	 * Write every map that changed, once per app id.
	 *
	 * The import calls this when it has gone through its seeds. A map that was not written (the process ended
	 * first) only costs the next import a comparison against the older version, never a lost update.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function flush(): void {
		foreach (array_keys($this->dirty) as $appId) {
			$this->appConfig->setValueString('openregister', $this->key(appId: (string)$appId), (string)json_encode($this->maps[$appId]), lazy: true);
		}

		$this->dirty = [];
	}//end flush()

	/**
	 * One app id's map, read once per request.
	 *
	 * @param string $appId The app id.
	 *
	 * @return array<string, string> Seed identity to shipped version.
	 */
	private function map(string $appId): array {
		if (isset($this->maps[$appId]) === false) {
			$decoded = json_decode($this->appConfig->getValueString('openregister', $this->key(appId: $appId), '', lazy: true), true);
			if (is_array($decoded) === false) {
				$decoded = [];
			}

			$map = [];
			foreach ($decoded as $seed => $version) {
				if (is_string($version) === true) {
					$map[(string)$seed] = $version;
				}
			}

			$this->maps[$appId] = $map;
		}

		return $this->maps[$appId];
	}//end map()

	/**
	 * The app-config key of an app id's map, hashed when the plain key would pass Nextcloud's limit.
	 *
	 * @param string $appId The app id.
	 *
	 * @return string
	 */
	private function key(string $appId): string {
		$key = $this->prefix . $appId;
		if (strlen($key) <= self::MAX_KEY_LENGTH) {
			return $key;
		}

		return $this->prefix . sha1($appId);
	}//end key()
}//end class
