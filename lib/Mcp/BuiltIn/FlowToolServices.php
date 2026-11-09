<?php

/**
 * The flow services the flow MCP tools author, check and queue flows through.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp\BuiltIn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/or-flow-mcp/specs/flow-mcp/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp\BuiltIn;

use OCA\OpenRegister\Db\FlowRunMapperInterface;
use OCA\OpenRegister\Service\Flow\FlowNodePreflightInterface;
use OCA\OpenRegister\Service\Flow\FlowRunServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowServiceInterface;

/**
 * A dependency bundle of {@see FlowMcpToolProvider}.
 *
 * @spec openspec/changes/or-flow-mcp/specs/flow-mcp/spec.md
 */
final class FlowToolServices {
	/**
	 * Bundle the services.
	 *
	 * @param FlowRunServiceInterface    $runner    Queues a flow run.
	 * @param FlowRunMapperInterface     $mapper    Reads recent runs (for listing / status).
	 * @param FlowServiceInterface       $flows     Authors flows, with the organisation scoping.
	 * @param FlowNodePreflightInterface $preflight Reports what a saved document is missing.
	 *
	 * @spec openspec/changes/or-flow-mcp/specs/flow-mcp/spec.md
	 */
	public function __construct(
		public readonly FlowRunServiceInterface $runner,
		public readonly FlowRunMapperInterface $mapper,
		public readonly FlowServiceInterface $flows,
		public readonly FlowNodePreflightInterface $preflight,
	) {
	}//end __construct()
}//end class
