<?php

/**
 * The mount providers and the mount cache of the sharer's view.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCP\Files\Config\IMountProviderCollection;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\Mount\IMountManager;

/**
 * A constructor bundle, autowired by Nextcloud: it carries no behaviour.
 *
 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
 */
final class MountRefresh {
	/**
	 * Bundle the collaborators.
	 *
	 * @param IMountProviderCollection $providers Builds a user's mounts.
	 * @param IUserMountCache          $cache     Where a user's mounts are recorded.
	 * @param IMountManager            $manager   The mounts set up in this request.
	 */
	public function __construct(
		public readonly IMountProviderCollection $providers,
		public readonly IUserMountCache $cache,
		public readonly IMountManager $manager,
	) {
	}//end __construct()
}//end class
