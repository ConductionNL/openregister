<?php

/**
 * LeafBundles: builds the leaf-bundle lookup the leaf-script listener asks.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/integration-registry/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

use OCP\App\IAppManager;

/**
 * Constructs exactly what the listener used to construct itself with `new`:
 * a fresh LeafBundle over the same app manager, typed as its interface.
 * Types only: no logic lives here.
 *
 * @spec openspec/specs/integration-registry/spec.md
 */
final class LeafBundles {

	/**
	 * A fresh leaf-bundle lookup.
	 *
	 * @param IAppManager $appManager The app manager, for resolving an app's path.
	 *
	 * @return LeafBundleInterface A fresh lookup.
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	public static function forApps(IAppManager $appManager): LeafBundleInterface {
		return new LeafBundle(appManager: $appManager);
	}//end forApps()
}//end class
