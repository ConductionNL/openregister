<?php

/**
 * OpenRegister AppHost — Store Manifest Content
 *
 * The target, catalogue and discovery of a declared store.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Store
 * @package  OCA\OpenRegister\AppHost\Store
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

namespace OCA\OpenRegister\AppHost\Store;

/**
 * What a store reads, offers and discovers.
 *
 * @spec openspec/specs/apphost-store-plane/spec.md#requirement-a-leaf-app-must-declare-its-store-rather-than-implement-one
 */
final class StoreManifestContent {

	/**
	 * Constructor.
	 *
	 * @param StoreManifestTarget    $target    Where it reads and writes.
	 * @param StoreManifestCatalogue $catalogue What it offers.
	 * @param StoreManifestDiscovery $discovery How it finds items.
	 */
	public function __construct(
		public readonly StoreManifestTarget $target = new StoreManifestTarget(),
		public readonly StoreManifestCatalogue $catalogue = new StoreManifestCatalogue(),
		public readonly StoreManifestDiscovery $discovery = new StoreManifestDiscovery(),
	) {
	}//end __construct()
}//end class
