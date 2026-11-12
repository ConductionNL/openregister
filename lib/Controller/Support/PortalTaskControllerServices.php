<?php

/**
 * OpenRegister portal task controller services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

/**
 * The service bundles of the portal task API.
 *
 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-only-the-matched-party-completes-fail-closed
 */
final class PortalTaskControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param PortalTaskDomain   $domain   The domain bundle.
	 * @param PortalTaskPlatform $platform The platform bundle.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-only-the-matched-party-completes-fail-closed
	 */
	public function __construct(
		public readonly PortalTaskDomain $domain,
		public readonly PortalTaskPlatform $platform,
	) {
	}//end __construct()
}//end class
