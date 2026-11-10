<?php

/**
 * Bundled manifest file reader
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category AppHost
 * @package  OCA\OpenRegister\AppHost\Observability
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Observability;

use Psr\Log\LoggerInterface;

/**
 * Reads and JSON-decodes the `src/manifest.json` an app bundles, for every
 * loader that needs one block of it.
 */
final class BundledManifestFile {

	/**
	 * Decode the bundled manifest under an app path.
	 *
	 * @param string          $appPath The app's install path.
	 * @param string          $appId   The app id, for the warning.
	 * @param LoggerInterface $logger  Receives a warning on invalid JSON.
	 * @param string          $tag     The log tag of the calling loader.
	 *
	 * @return array<string, mixed>|null The decoded manifest, or null when it
	 *                                   is unreadable or not a JSON object.
	 */
	public static function decode(string $appPath, string $appId, LoggerInterface $logger, string $tag): ?array {
		return self::decodeFor(lookup: new ManifestLookup(appId: $appId, appPath: $appPath, tag: $tag), logger: $logger);
	}//end decode()

	/**
	 * Decode the bundled manifest an app lookup names.
	 *
	 * @param ManifestLookup  $lookup The app id, its install path and the caller's log tag.
	 * @param LoggerInterface $logger Receives a warning on invalid JSON.
	 *
	 * @return array<string, mixed>|null The decoded manifest, or null when it
	 *                                   is unreadable or not a JSON object.
	 *
	 * @spec openspec/specs/apphost-observability/spec.md
	 */
	public static function decodeFor(ManifestLookup $lookup, LoggerInterface $logger): ?array {
		$raw = self::read(path: $lookup->appPath . '/src/manifest.json');
		if ($raw === null) {
			return null;
		}

		$decoded = json_decode($raw, associative: true);
		if (json_last_error() !== JSON_ERROR_NONE || is_array($decoded) === false) {
			$logger->warning(
				message: sprintf('[%s] manifest.json for "%s" is invalid JSON', $lookup->tag, $lookup->appId),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}

		return $decoded;
	}//end decodeFor()

	/**
	 * The contents of a readable file.
	 *
	 * @param string $path The file path.
	 *
	 * @return string|null The contents, or null when the file cannot be read.
	 */
	private static function read(string $path): ?string {
		if (is_readable($path) === false) {
			return null;
		}

		$raw = file_get_contents($path);
		if ($raw === false) {
			return null;
		}

		return $raw;
	}//end read()
}//end class
