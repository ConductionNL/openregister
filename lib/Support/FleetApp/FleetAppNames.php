<?php

/**
 * FleetAppNames — the route path and class name of a fleet app, by resolved id.
 *
 * Builds names from the id FleetAppProbe resolves and the namespaces
 * FleetAppCatalogue holds; it never checks that the named thing exists.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Support
 * @package  OCA\OpenRegister\Support\FleetApp
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Support\FleetApp;

use OCP\App\IAppManager;

/**
 * Names a fleet app's routes and classes under the id it is installed as.
 */
final class FleetAppNames {

	/**
	 * The `/apps/<id>[/suffix]` path under the resolved id.
	 *
	 * @param IAppManager $appManager The app manager to ask.
	 * @param string $canonical Canonical (new) app name.
	 * @param string $suffix Optional path below the app.
	 *
	 * @return string|null The path, or null when the app is absent.
	 */
	public static function appPath(IAppManager $appManager, string $canonical, string $suffix = ''): ?string {
		$id = FleetAppProbe::resolve(appManager: $appManager, canonical: $canonical);
		if ($id === null) {
			return null;
		}

		$path = '/apps/' . $id;
		if ($suffix !== '') {
			$path .= '/' . ltrim($suffix, '/');
		}

		return $path;
	}//end appPath()

	/**
	 * The FQCN of a cross-app class under the installed id, falling back to
	 * the canonical spelling when neither id is installed.
	 *
	 * @param IAppManager $appManager The app manager to ask.
	 * @param string $canonical Canonical (new) app name.
	 * @param string $relativeClass Class path below the namespace.
	 *
	 * @return string|null The FQCN, or null when no namespace was read.
	 */
	public static function className(IAppManager $appManager, string $canonical, string $relativeClass): ?string {
		if (FleetAppCatalogue::hasNamespaces(canonical: $canonical) === false) {
			return null;
		}

		$id = FleetAppProbe::resolve(appManager: $appManager, canonical: $canonical);
		if ($id === null || FleetAppCatalogue::namespaceForId(canonical: $canonical, id: $id) === null) {
			$id = FleetAppCatalogue::namespacedIds(canonical: $canonical)[0];
		}

		return FleetAppCatalogue::namespaceForId(canonical: $canonical, id: $id) . $relativeClass;
	}//end className()

}//end class
