<?php

/**
 * OpenRegister flow run controller services
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
 * The service bundles of the flow run API.
 *
 * @spec openspec/specs/flow-tooling/spec.md
 */
final class FlowRunControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param FlowRunRuns     $runs     The runs bundle.
	 * @param FlowRunAccess   $access   The access bundle.
	 * @param FlowRunPlatform $platform The platform bundle.
	 *
	 * @spec openspec/specs/flow-tooling/spec.md
	 */
	public function __construct(
		public readonly FlowRunRuns $runs,
		public readonly FlowRunAccess $access,
		public readonly FlowRunPlatform $platform,
	) {
	}//end __construct()
}//end class
