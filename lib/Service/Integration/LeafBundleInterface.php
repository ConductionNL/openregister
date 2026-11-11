<?php

/**
 * LeafBundleInterface: whether an app ships a leaf bundle, as the leaf-script listener asks.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

/**
 * The methods of LeafBundle that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface LeafBundleInterface {

	/**
	 * Whether an app ships a built leaf bundle.
	 *
	 * @param string $appId The providing app.
	 *
	 * @return bool Whether `js/<app>-leaves.js` exists.
	 *
	 * @spec openspec/changes/a-leaf-that-cannot-render-refuses-to-register/specs/leaf-provider-registration/spec.md
	 */
	public function existsFor(string $appId): bool;
}//end interface
