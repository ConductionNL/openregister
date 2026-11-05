<?php

/**
 * OpenRegister flow node run platform
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

use OCP\IUserSession;

/**
 * The platform services of the flow node run API.
 *
 * @spec openspec/changes/or-flow-run-node/specs/flow-run-node/spec.md
 */
final class FlowNodeRunPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param IUserSession $userSession Resolves the calling user.
	 *
	 * @spec openspec/changes/or-flow-run-node/specs/flow-run-node/spec.md
	 */
	public function __construct(
		public readonly IUserSession $userSession,
	) {
	}//end __construct()
}//end class
