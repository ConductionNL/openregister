<?php

/**
 * Reading a leaf app's register document and its `register.d/` fragments.
 *
 * @category Service
 * @package  OCA\OpenRegister\AppHost\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/apphost-boilerplate-controllers/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Service;

use OCP\App\IAppManager;
use Throwable;

/**
 * Loads one app's register document, merged with its fragments.
 *
 * 🔑 THE FRAGMENT SIGNATURE IS PART OF THE VERSION. OpenRegister's import is
 * version-gated, so a fragment edited without touching the base document's
 * `info.version` would never be imported. Folding a hash of every fragment
 * into the version string is what makes editing a fragment take effect, and
 * it is the one piece of this that is easy to lose in a rewrite.
 *
 * Its own class because the settings service around it answers questions
 * about SETTINGS — what is stored, who may change it, which features are on
 * — and this answers a question about FILES ON DISK. Nothing here reads or
 * writes app config.
 *
 * @spec openspec/changes/apphost-boilerplate-controllers/tasks.md#task-2.1
 */
class RegisterDocumentLoader {

	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager Locates the leaf app's install directory.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
	) {
	}//end __construct()

	/**
	 * Resolve the leaf app's register JSON + `register.d/` fragments so they can be
	 * passed to {@see \OCA\OpenRegister\Service\ConfigurationService::importFromApp()},
	 * which requires both a `$data` array and a `$version` string.
	 *
	 * Mirrors the fleet convention hand-rolled by every bespoke per-app
	 * `SettingsService::doLoadConfiguration()` (e.g. openbuild, procest, scholiq,
	 * pipelinq): `lib/Settings/{appId}_register.json` as the base document, with
	 * `lib/Settings/register.d/*.json` fragments deep-merged on top in sorted
	 * filename order. The fragment signature (filename + content hash of every
	 * fragment) is folded into the returned version string so OpenRegister's
	 * version-gated import re-imports whenever a fragment changes, even when the
	 * base document's own `info.version` did not change.
	 *
	 * Uses {@see IAppManager::getAppPath()} to locate the leaf app's install
	 * directory, since - unlike each app's own bespoke SettingsService - this
	 * generic service lives inside OpenRegister itself and has no `__DIR__`
	 * relative to the calling (leaf) app.
	 *
	 * @return array{0: array<string, mixed>|null, 1: string} `[$data, $version]`;
	 *                                                        `$data` is `null` when
	 *                                                        no register JSON was found.
	 *
	 * @spec openspec/changes/apphost-boilerplate-controllers/tasks.md#task-2.1
	 */
	public function load(string $appId): array {
		try {
			$appPath = $this->appManager->getAppPath($appId);
		} catch (Throwable $e) {
			return [null, ''];
		}

		$configPath = $appPath . '/lib/Settings/' . $appId . '_register.json';
		if (file_exists($configPath) === false) {
			return [null, ''];
		}

		$configContent = file_get_contents($configPath);
		if ($configContent === false) {
			return [null, ''];
		}

		$configData = json_decode($configContent, true);
		if (json_last_error() !== JSON_ERROR_NONE || is_array($configData) === false) {
			return [null, ''];
		}

		[$configData, $fragmentSig] = $this->withFragments(
			base: $configData,
			fragmentDir: $appPath . '/lib/Settings/register.d'
		);

		$version = (string)($configData['info']['version'] ?? '0.0.0');
		if ($fragmentSig !== '') {
			$version .= '+frag.' . substr(md5($fragmentSig), 0, 8);
		}

		return [$configData, $version];
	}//end load()

	/**
	 * The base document with every `register.d/` fragment merged onto it.
	 *
	 * ADR-037: modular register fragments from `Settings/register.d/*.json`,
	 * merged in sorted filename order, same as every bespoke per-app
	 * `SettingsService`. An unreadable or malformed fragment is SKIPPED rather
	 * than fatal: one bad file must not make the app's whole register
	 * unimportable.
	 *
	 * 🔑 THE SIGNATURE COMES BACK WITH IT. It is what the caller folds into
	 * the version so a fragment edit is actually re-imported, and computing it
	 * anywhere other than beside the merge is how the two come apart.
	 *
	 * @param array<string, mixed> $base        The base register document.
	 * @param string               $fragmentDir Where the fragments live.
	 *
	 * @return array{0: array<string, mixed>, 1: string} The merged document and the fragment signature.
	 *
	 * @spec openspec/changes/apphost-boilerplate-controllers/tasks.md#task-2.1
	 */
	private function withFragments(array $base, string $fragmentDir): array {
		if (is_dir($fragmentDir) === false) {
			return [$base, ''];
		}

		$fragmentFiles = glob($fragmentDir . '/*.json');
		if ($fragmentFiles === false) {
			return [$base, ''];
		}

		sort($fragmentFiles);
		$fragmentSig = '';
		foreach ($fragmentFiles as $fragmentFile) {
			$fragmentContent = file_get_contents($fragmentFile);
			if ($fragmentContent === false) {
				continue;
			}

			$fragmentData = json_decode($fragmentContent, true);
			if (json_last_error() !== JSON_ERROR_NONE || is_array($fragmentData) === false) {
				continue;
			}

			$base = self::deepMergeConfig(base: $base, overlay: $fragmentData);
			$fragmentSig .= basename($fragmentFile) . ':' . md5($fragmentContent) . ';';
		}

		return [$base, $fragmentSig];
	}//end withFragments()

	/**
	 * Recursively deep-merges an overlay config onto a base config.
	 *
	 * Keyed (associative) arrays are merged key-by-key (recursing into nested
	 * arrays); list arrays (sequential integer keys) are concatenated. Scalars
	 * in the overlay win. Identical semantics to every bespoke per-app
	 * `SettingsService::deepMergeConfig()` (e.g. openbuild), duplicated here so
	 * the generic AppHost path merges `register.d/` fragments the same way.
	 *
	 * @param array<string, mixed> $base The base configuration array.
	 * @param array<string, mixed> $overlay The overlay to merge onto the base.
	 *
	 * @return array<string, mixed> The merged configuration.
	 *
	 * @spec openspec/changes/apphost-boilerplate-controllers/tasks.md#task-2.1
	 */
	public static function deepMergeConfig(array $base, array $overlay): array {
		foreach ($overlay as $key => $value) {
			$bothArrays = (is_array($value) === true
				&& isset($base[$key]) === true
				&& is_array($base[$key]) === true);
			if ($bothArrays === false) {
				$base[$key] = $value;
				continue;
			}

			$baseIsList = ($base[$key] === [] || array_keys($base[$key]) === range(0, (count($base[$key]) - 1)));
			$overlayIsList = ($value === [] || array_keys($value) === range(0, (count($value) - 1)));
			if ($baseIsList === true && $overlayIsList === true) {
				$base[$key] = array_merge($base[$key], $value);
				continue;
			}

			$base[$key] = self::deepMergeConfig(base: $base[$key], overlay: $value);
		}

		return $base;
	}//end deepMergeConfig()

}//end class
