<?php

/**
 * OpenRegister AppHost — Store Manifest Permissions
 *
 * Who may install and publish through a declared store, and what an install may configure.
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
 * The install and publish posture a store declares.
 *
 * @spec openspec/specs/apphost-store-plane/spec.md#requirement-a-leaf-app-must-declare-its-store-rather-than-implement-one
 */
final class StoreManifestPermissions {

	/**
	 * Constructor.
	 *
	 * @param string             $installAuth   Who may install: see StoreManifest::INSTALL_AUTH.
	 * @param array<int, string> $configurable  Config keys an install may write.
	 * @param array<int, string> $publishFields Item properties a publish may send, next to the slug.
	 * @param array<int, string> $publishGroups Groups whose members may publish.
	 */
	public function __construct(
		public readonly string $installAuth = StoreManifest::DEFAULT_INSTALL_AUTH,
		public readonly array $configurable = [],
		public readonly array $publishFields = [],
		public readonly array $publishGroups = [],
	) {
	}//end __construct()
}//end class
