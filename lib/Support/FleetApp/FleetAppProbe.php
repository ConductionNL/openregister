<?php

/**
 * FleetAppProbe — ask the instance which fleet app id or class it has.
 *
 * The lookup half of FleetAppId: walks the candidates of FleetAppCatalogue
 * against the app manager or the autoloader and returns the first that answers.
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
use Throwable;

/**
 * Probes the app manager and the autoloader for a fleet app's candidates.
 */
final class FleetAppProbe {

	/**
	 * The first candidate id the instance has installed.
	 *
	 * @param IAppManager $appManager The app manager to ask.
	 * @param string $canonical Canonical (new) app name.
	 *
	 * @return string|null The installed id, or null when none is.
	 */
	public static function resolve(IAppManager $appManager, string $canonical): ?string {
		foreach (FleetAppCatalogue::candidates(canonical: $canonical) as $candidate) {
			if (self::installed(appManager: $appManager, id: $candidate) === true) {
				return $candidate;
			}
		}

		return null;
	}//end resolve()

	/**
	 * Whether the resolved id is enabled for the current user.
	 *
	 * @param IAppManager $appManager The app manager to ask.
	 * @param string $canonical Canonical (new) app name.
	 *
	 * @return bool False when absent, disabled, or when the manager throws.
	 */
	public static function isEnabledForUser(IAppManager $appManager, string $canonical): bool {
		$id = self::resolve(appManager: $appManager, canonical: $canonical);
		if ($id === null) {
			return false;
		}

		try {
			return $appManager->isEnabledForUser($id);
		} catch (Throwable $e) {
			return false;
		}

	}//end isEnabledForUser()

	/**
	 * The first spelling of a cross-app class that is actually loadable.
	 *
	 * @param string $canonical Canonical (new) app name.
	 * @param string $relativeClass Class path below the namespace.
	 *
	 * @return string|null The loadable FQCN, or null when none is.
	 */
	public static function resolveClass(string $canonical, string $relativeClass): ?string {
		foreach (FleetAppCatalogue::namespacedIds(canonical: $canonical) as $id) {
			$className = FleetAppCatalogue::namespaceForId(canonical: $canonical, id: $id) . $relativeClass;
			if (self::loadable(className: $className) === true) {
				return $className;
			}
		}

		return null;
	}//end resolveClass()

	/**
	 * Whether one id is installed; a manager that throws answers no, so the
	 * next candidate may still resolve.
	 *
	 * @param IAppManager $appManager The app manager to ask.
	 * @param string $id One candidate id.
	 *
	 * @return bool True when installed.
	 */
	private static function installed(IAppManager $appManager, string $id): bool {
		try {
			return $appManager->isInstalled($id) === true;
		} catch (Throwable $e) {
			return false;
		}

	}//end installed()

	/**
	 * Whether one class loads; an autoloader entry that throws answers no, so
	 * the next spelling may still load.
	 *
	 * @param string $className The FQCN to probe.
	 *
	 * @return bool True when the class exists.
	 */
	private static function loadable(string $className): bool {
		try {
			return class_exists($className) === true;
		} catch (Throwable $e) {
			return false;
		}

	}//end loadable()

}//end class
