<?php

/**
 * OpenRegister AppHost — Store Manifest Target
 *
 * Where a declared store reads from and where an install writes to.
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
 * The remote schema and register a store reads, and the local register it writes.
 *
 * @spec openspec/specs/apphost-store-plane/spec.md#requirement-a-leaf-app-must-declare-its-store-rather-than-implement-one
 * @spec openspec/changes/store-install-target/specs/apphost-store-plane/spec.md#requirement-an-install-can-go-into-a-target-the-app-declares
 */
final class StoreManifestTarget {

	/**
	 * Constructor.
	 *
	 * @param string             $schema        Remote schema slug the registry serves.
	 * @param string             $register      Default remote register slug.
	 * @param string             $localRegister Local register an install writes into.
	 * @param StoreInstallTarget $installTarget Where inside that register an install can go.
	 */
	public function __construct(
		public readonly string $schema = '',
		public readonly string $register = '',
		public readonly string $localRegister = '',
		public readonly StoreInstallTarget $installTarget = new StoreInstallTarget(),
	) {
	}//end __construct()
}//end class
