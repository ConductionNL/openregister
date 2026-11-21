<?php

/**
 * The flow store and run queue the Nextcloud Flow operation dispatches through.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category WorkflowEngine
 * @package  OCA\OpenRegister\WorkflowEngine
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

namespace OCA\OpenRegister\WorkflowEngine;

use OCA\OpenRegister\Db\FlowMapperInterface;
use OCA\OpenRegister\Service\Flow\FlowRunServiceInterface;

/**
 * Resolves a flow by name and queues its run, bundled for RunFlowOperation.
 */
final class RunFlowOperationFlows {

	/**
	 * Bundle the services.
	 *
	 * @param FlowMapperInterface     $flows  Resolves a flow by the name the rule configured.
	 * @param FlowRunServiceInterface $runner Queues the run.
	 */
	public function __construct(
		public readonly FlowMapperInterface $flows,
		public readonly FlowRunServiceInterface $runner,
	) {
	}//end __construct()
}//end class
