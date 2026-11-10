<?php

/**
 * The platform services the object-write step reads: the run's user and the write ceiling.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCP\IAppConfig;
use OCP\IUserManager;

/**
 * The platform services the object-write step reads: the run's user and the write ceiling.
 *
 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
 */
final class ObjectWritePlatform {
	/**
	 * Bundle the services.
	 *
	 * @param IUserManager $userManager Resolves the user the run acts as.
	 * @param IAppConfig   $appConfig   Holds the per-step write ceiling.
	 */
	public function __construct(
		public readonly IUserManager $userManager,
		public readonly IAppConfig $appConfig,
	) {
	}//end __construct()
}//end class
