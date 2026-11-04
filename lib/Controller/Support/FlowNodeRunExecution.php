<?php

/**
 * OpenRegister flow node run domain
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

use OCA\OpenRegister\Service\Flow\FlowRunServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Object\PermissionHandler;

/**
 * The domain services of the flow node run API.
 *
 * @spec openspec/changes/or-flow-run-node/specs/flow-run-node/spec.md
 */
final class FlowNodeRunExecution {
	/**
	 * Bundle the services.
	 *
	 * @param FlowRunServiceInterface    $runner      Queues and executes the one node.
	 * @param ObjectService     $objects     Loads the subject, scoped to the CALLER's
	 * @param PermissionHandler $permissions The SAME object-RBAC evaluator the
	 *
	 * @spec openspec/changes/or-flow-run-node/specs/flow-run-node/spec.md
	 */
	public function __construct(
		public readonly FlowRunServiceInterface $runner,
		public readonly ObjectService $objects,
		public readonly PermissionHandler $permissions,
	) {
	}//end __construct()
}//end class
