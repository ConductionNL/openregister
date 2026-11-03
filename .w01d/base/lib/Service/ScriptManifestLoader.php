<?php

/**
 * OpenRegister ScriptManifestLoader Service
 *
 * Enqueues all initial webpack chunks for a given entry point, driven by the
 * build-time manifest (js/openregister-entrypoints.json).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCP\Util;

/**
 * Loads the webpack output for an entry point as a set of cooperating chunks.
 *
 * The production webpack build splits shared dependencies (vue, @nextcloud/vue,
 * pinia, the nextcloud-vue source, …) out of each entry into shared chunks so
 * they are bundled once instead of once per entry. As a result an entry is no
 * longer a single self-contained file: the page must load every initial chunk
 * the entry depends on, in order, before the entry chunk can run.
 *
 * The build emits js/openregister-entrypoints.json mapping each entry name to
 * its ordered list of initial chunk filenames. Split-chunk filenames are
 * content-hashed and change every build, so they cannot be hardcoded in PHP —
 * this loader resolves them from the manifest at render time.
 *
 * If the manifest is absent (e.g. a dev build, or an older build predating the
 * split), the loader falls back to enqueuing the single legacy entry script so
 * behaviour degrades gracefully rather than breaking the page.
 *
 * @psalm-suppress UnusedClass
 */
class ScriptManifestLoader {

	/**
	 * In-process cache of the decoded manifest, keyed by absolute path.
	 *
	 * @var array<string, array<string, string[]>>
	 */
	private static array $manifestCache = [];

	/**
	 * Enqueue every initial chunk of an entry point.
	 *
	 * @param string $appId The Nextcloud app id (e.g. 'openregister').
	 * @param string $entry The webpack entry name (e.g. 'main').
	 * @param string $fallbackScript The legacy single-script name to enqueue
	 *                               when the manifest is unavailable, without
	 *                               the '.js' extension (e.g. 'openregister-main').
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) \OCP\Util::addScript is the
	 *     canonical Nextcloud API for enqueuing scripts and has no injectable
	 *     DI equivalent in the AppFramework.
	 */
	public static function addEntryScripts(string $appId, string $entry, string $fallbackScript): void {
		$chunks = self::resolveEntryScripts(appId: $appId, entry: $entry);

		if ($chunks === null) {
			Util::addScript($appId, $fallbackScript);
			return;
		}

		foreach ($chunks as $chunk) {
			Util::addScript($appId, $chunk);
		}

	}//end addEntryScripts()

	/**
	 * Resolve the ordered chunk names (without '.js') for an entry.
	 *
	 * Returns null when the manifest is missing, malformed, or does not list the
	 * requested entry; callers treat null as "fall back to the legacy script".
	 *
	 * @param string $appId The Nextcloud app id.
	 * @param string $entry The webpack entry name.
	 * @param string|null $jsDirectory Override for the directory containing the
	 *                                 manifest. Defaults to the app's js/ folder;
	 *                                 primarily a seam for testing.
	 *
	 * @return string[]|null Ordered chunk names, or null when the entry cannot
	 *                       be resolved from a manifest.
	 */
	public static function resolveEntryScripts(string $appId, string $entry, ?string $jsDirectory = null): ?array {
		$manifest = self::loadManifest(appId: $appId, jsDirectory: $jsDirectory);

		if ($manifest === null || isset($manifest[$entry]) === false) {
			return null;
		}

		$chunks = [];
		foreach ($manifest[$entry] as $file) {
			// The manifest stores filenames with the '.js' extension; addScript
			// expects the name without it.
			$chunks[] = preg_replace('/\.js$/', '', $file);
		}

		return $chunks;
	}//end resolveEntryScripts()

	/**
	 * Load and cache the entrypoints manifest for an app.
	 *
	 * @param string $appId The Nextcloud app id.
	 * @param string|null $jsDirectory Override for the directory containing the
	 *                                 manifest. Defaults to the app's js/ folder.
	 *
	 * @return array<string, string[]>|null The decoded manifest, or null when
	 *                                      it is missing or invalid.
	 */
	private static function loadManifest(string $appId, ?string $jsDirectory = null): ?array {
		$directory = ($jsDirectory ?? __DIR__ . '/../../js');
		$path = $directory . '/' . $appId . '-entrypoints.json';

		if (array_key_exists($path, self::$manifestCache) === true) {
			return self::$manifestCache[$path];
		}

		if (is_file($path) === false) {
			return null;
		}

		$contents = file_get_contents($path);
		if ($contents === false) {
			return null;
		}

		$decoded = json_decode($contents, true);
		if (is_array($decoded) === false) {
			return null;
		}

		self::$manifestCache[$path] = $decoded;
		return $decoded;
	}//end loadManifest()
}//end class
