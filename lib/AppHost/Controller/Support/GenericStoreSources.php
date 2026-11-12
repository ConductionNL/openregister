<?php

/**
 * OpenRegister generic store sources
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\AppHost\Controller\Support
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

namespace OCA\OpenRegister\AppHost\Controller\Support;

use OCA\OpenRegister\AppHost\Observability\ManifestLoader;
use OCA\OpenRegister\AppHost\Service\GenericStoreService;
use OCA\OpenRegister\AppHost\Store\FederatedStoreCatalog;
use OCA\OpenRegister\AppHost\Store\Source\GitHubStoreSource;

/**
 * Where a leaf app's store is declared, and the sources it is searched in.
 *
 * @spec openspec/specs/apphost-store-plane/spec.md#requirement-a-leaf-app-must-declare-its-store-rather-than-implement-one
 */
final class GenericStoreSources {
	/**
	 * Bundle the services.
	 *
	 * @param ManifestLoader        $manifestLoader Loads the leaf app's manifest.
	 * @param GenericStoreService   $storeService   Guarded remote discovery.
	 * @param GitHubStoreSource     $gitHubSource   GitHub discovery source.
	 * @param FederatedStoreCatalog $catalog        Configuration browse and install.
	 *
	 * @spec openspec/specs/apphost-store-plane/spec.md#requirement-a-leaf-app-must-declare-its-store-rather-than-implement-one
	 */
	public function __construct(
		public readonly ManifestLoader $manifestLoader,
		public readonly GenericStoreService $storeService,
		public readonly GitHubStoreSource $gitHubSource,
		public readonly FederatedStoreCatalog $catalog,
	) {
	}//end __construct()
}//end class
