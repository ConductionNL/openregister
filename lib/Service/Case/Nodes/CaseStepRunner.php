<?php

/**
 * OpenRegister case step runner
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case\Nodes;

use OCA\OpenRegister\Service\Case\CasePlanService;
use OCA\OpenRegister\Service\Flow\FlowRunAsScope;
use OCP\IUserSession;

/**
 * What a case step runs through and as whom: the case layer's verbs, the
 * run-as scope and the interactive user. Wired by DI autowiring.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-a-flow-step-opens-or-advances-a-case
 */
final class CaseStepRunner {
	/**
	 * Bundle the services.
	 *
	 * @param CasePlanService $plans   The case layer's verbs.
	 * @param FlowRunAsScope  $scope   Runs the work as the run's identity.
	 * @param IUserSession    $session The interactive user, when the run names nobody.
	 */
	public function __construct(
		public readonly CasePlanService $plans,
		public readonly FlowRunAsScope $scope,
		public readonly IUserSession $session,
	) {
	}//end __construct()
}//end class
