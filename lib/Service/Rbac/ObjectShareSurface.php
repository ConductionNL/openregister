<?php

/**
 * The Nextcloud share surface an object's grants live on: its folder, core's share manager, the share reader and the share-time writer.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCP\Share\IManager;

/**
 * The share collaborators of ObjectSharingService, wired by DI autowiring.
 *
 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
 */
final class ObjectShareSurface {
	/**
	 * Bundle the services.
	 *
	 * @param FolderManagementHandler $folders      Resolves an object's NC folder.
	 * @param IManager                $shareManager Core share manager.
	 * @param ObjectShareReader|null  $shareReader  Reads the shares on the object folder. Null only in legacy fixtures.
	 * @param ShareTimeWriter|null    $shareTimes   Dates a new share in the past. Null only in legacy fixtures.
	 */
	public function __construct(
		public readonly FolderManagementHandler $folders,
		public readonly IManager $shareManager,
		public readonly ?ObjectShareReader $shareReader = null,
		public readonly ?ShareTimeWriter $shareTimes = null,
	) {
	}//end __construct()
}//end class
