<?php

/**
 * OpenRegister flow node call
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
 * One direct invocation of a flow node: the flow and the node within its published graph.
 *
 * @spec openspec/changes/or-flow-run-node/specs/flow-run-node/spec.md#requirement-direct-invocation-is-authorized-against-the-subject-object
 */
final readonly class FlowNodeCall {
	/**
	 * Bundle the flow with the node a direct run invokes.
	 *
	 * @param string $flowId The flow's uuid.
	 * @param string $nodeId The node's id within the flow's published graph.
	 *
	 * @spec openspec/changes/or-flow-run-node/specs/flow-run-node/spec.md#requirement-direct-invocation-is-authorized-against-the-subject-object
	 */
	public function __construct(
		public string $flowId,
		public string $nodeId,
	) {
	}//end __construct()
}//end class
