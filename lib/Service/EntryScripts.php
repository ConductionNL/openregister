<?php

/**
 * EntryScripts: the entry listeners and widgets call to enqueue the chunks of a webpack entry point.
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
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Enqueues every initial chunk of a webpack entry point, for the listeners and widgets that load a bundle.
 *
 * The manifest reading stays in ScriptManifestLoader; this is the entry
 * callers outside the Service component use, so they do not name it.
 *
 * @spec openspec/specs/frontend-store-client-state/spec.md
 */
final class EntryScripts {

	/**
	 * Enqueue every initial chunk of an entry point.
	 *
	 * @param string $appId          The Nextcloud app id (e.g. 'openregister').
	 * @param string $entry          The webpack entry name (e.g. 'main').
	 * @param string $fallbackScript The legacy single-script name to enqueue
	 *                               when the manifest is unavailable, without
	 *                               the '.js' extension (e.g. 'openregister-main').
	 *
	 * @return void
	 *
	 * @spec openspec/specs/frontend-store-client-state/spec.md
	 */
	public static function add(string $appId, string $entry, string $fallbackScript): void {
		ScriptManifestLoader::addEntryScripts(appId: $appId, entry: $entry, fallbackScript: $fallbackScript);
	}//end add()
}//end class
