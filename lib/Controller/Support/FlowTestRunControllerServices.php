<?php

/**
 * OpenRegister FlowTestRunController services
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

use OCA\OpenRegister\Service\Flow\FlowLocatorInterface;
use OCA\OpenRegister\Service\Flow\FlowRunnableGuardInterface;
use OCA\OpenRegister\Service\Flow\FlowRunServiceInterface;
use OCP\IUserSession;

/**
 * The collaborators of FlowTestRunController, wired by DI autowiring.
 *
 * @spec openspec/specs/flow-partial-run/spec.md
 */
final class FlowTestRunControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param FlowRunServiceInterface $runner Queues and executes a run.
	 * @param FlowLocatorInterface $resolvers Resolves the flow being tested.
	 * @param IUserSession $userSession Who is asking, for attribution.
	 * @param FlowRunnableGuardInterface $guard Whether the caller may run and edit this flow.
	 *
	 * @spec openspec/specs/flow-partial-run/spec.md
	 */
	public function __construct(
		public readonly FlowRunServiceInterface $runner,
		public readonly FlowLocatorInterface $resolvers,
		public readonly IUserSession $userSession,
		public readonly FlowRunnableGuardInterface $guard,
	) {
	}//end __construct()
}//end class
