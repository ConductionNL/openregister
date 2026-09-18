<?php

/**
 * Whether an app ships the bundle its render surface needs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/a-leaf-that-cannot-render-refuses-to-register/specs/leaf-provider-registration/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

use OCP\App\IAppManager;
use Throwable;

/**
 * One answer to "can this app's leaf actually render", asked in two places.
 *
 * 🔴 IT HAS TO BE ONE ANSWER. `LeafScriptListener` decides which bundles to put
 * on a page, and `LeafRegistry` now decides whether a render surface may
 * register at all. If those two disagreed, the registry would accept a leaf the
 * listener never loads, which is precisely the failure this exists to end: a
 * descriptor reaches capability discovery, `getLeaves()` returns it, the gate
 * goes green on both halves, and the surface renders NOTHING.
 *
 * 🔑 THE FILENAME IS PART OF THE CONTRACT, AND IT IS NOT OBVIOUS. The loader
 * looks for `js/<app>-leaves.js`, built from a dedicated `leaves` webpack entry.
 * An app that builds its leaf under any other name has shipped a bundle nobody
 * looks for. Measured on the development instance, one app had done exactly
 * that: hermiq ships `js/hermiq-agent-leaf.js`, which no loader reads.
 *
 * @spec openspec/changes/a-leaf-that-cannot-render-refuses-to-register/specs/leaf-provider-registration/spec.md
 */
class LeafBundle {

	/**
	 * The webpack entry name a providing app must build.
	 */
	public const ENTRY = 'leaves';

	/**
	 * The app manager, for resolving an app's path.
	 *
	 * @param IAppManager $appManager The app manager.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
	) {
	}//end __construct()

	/**
	 * The file a providing app must ship for its render surface to load.
	 *
	 * @param string $appId The providing app.
	 *
	 * @return string The expected bundle file name.
	 */
	public function expectedFileName(string $appId): string {
		return $appId . '-' . self::ENTRY . '.js';
	}//end expectedFileName()

	/**
	 * Whether an app ships a built leaf bundle.
	 *
	 * @param string $appId The providing app.
	 *
	 * @return bool Whether `js/<app>-leaves.js` exists.
	 */
	public function existsFor(string $appId): bool {
		$path = $this->pathFor(appId: $appId);
		if ($path === null) {
			return false;
		}

		return file_exists($path . '/js/' . $this->expectedFileName(appId: $appId));
	}//end existsFor()

	/**
	 * An app's filesystem path, or null when it cannot be resolved.
	 *
	 * 🔑 AN UNRESOLVABLE PATH IS NOT A MISSING BUNDLE, AND THE CALLER MUST TELL
	 * THEM APART. A disabled or uninstalled app has no path, and refusing its
	 * leaf for "no bundle" would be a confident wrong reason on an app that is
	 * simply not there.
	 *
	 * @param string $appId The app.
	 *
	 * @return string|null The path.
	 */
	public function pathFor(string $appId): ?string {
		try {
			return $this->appManager->getAppPath($appId);
		} catch (Throwable) {
			return null;
		}
	}//end pathFor()
}//end class
