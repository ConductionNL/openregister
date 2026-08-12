<?php

/**
 * OpenRegister AppHost — Manifest Loader
 *
 * Resolves and decodes a host app's bundled `src/manifest.json`, then parses
 * its `observability` block into an {@see ObservabilityManifest}. Mirrors the
 * resolution path used by `GET /api/manifest/{appId}` (ManifestController),
 * supporting every deploy layout (bind-mounted apps-extra and custom_apps).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\AppHost\Observability
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Observability;

use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Loads + decodes an app's manifest and parses its observability config.
 *
 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-1.2
 */
class ManifestLoader {
	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager App-path resolution.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Load the observability config for an app id.
	 *
	 * Always returns a usable config: when the manifest is missing or
	 * unreadable, the app's defaults apply (database check, implicit metrics).
	 *
	 * @param string $appId The calling app's id.
	 *
	 * @return ObservabilityManifest
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-1.2
	 */
	public function load(string $appId): ObservabilityManifest {
		$manifest = $this->loadBundledManifest(appId: $appId);
		if ($manifest === null) {
			return ObservabilityManifest::defaults(appId: $appId, manifest: []);
		}

		return ObservabilityManifest::fromManifest(appId: $appId, manifest: $manifest);
	}//end load()

	/**
	 * Resolve the installed version of an app (for the implicit `{app}_info`).
	 *
	 * @param string $appId The app id.
	 *
	 * @return string The version string, or 'unknown'.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-3.6
	 */
	public function appVersion(string $appId): string {
		try {
			$version = $this->appManager->getAppVersion($appId);
			if ($version === '') {
				return 'unknown';
			}

			return $version;
		} catch (Throwable $e) {
			return 'unknown';
		}
	}//end appVersion()

	/**
	 * Load + JSON-decode an app's bundled `src/manifest.json`.
	 *
	 * @param string $appId The Nextcloud app id.
	 *
	 * @return array<string, mixed>|null Decoded manifest, or null if unreadable.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private function loadBundledManifest(string $appId): ?array {
		try {
			$appPath = $this->appManager->getAppPath(appId: $appId);
		} catch (Throwable $e) {
			$this->logger->debug(
				message: sprintf('[AppHost\\ManifestLoader] App "%s" path not found: %s', $appId, $e->getMessage()),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}

		$manifestPath = $appPath . '/src/manifest.json';
		if (is_readable($manifestPath) === false) {
			return null;
		}

		$raw = file_get_contents($manifestPath);
		if ($raw === false) {
			return null;
		}

		$decoded = json_decode($raw, associative: true);
		if (json_last_error() !== JSON_ERROR_NONE || is_array($decoded) === false) {
			$this->logger->warning(
				message: sprintf('[AppHost\\ManifestLoader] manifest.json for "%s" is invalid JSON', $appId),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}

		return $decoded;
	}//end loadBundledManifest()
}//end class
