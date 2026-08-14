<?php

/**
 * Dispatched so apps can contribute their flow node types.
 *
 * This is Nextcloud's own discovery pattern, copied on purpose. Core's
 * workflow engine builds its operator list by dispatching
 * `OCP\WorkflowEngine\Events\RegisterOperationsEvent` and letting apps call
 * `registerOperation()` on the manager it carries
 * (`apps/workflowengine/lib/Manager.php:633`). An app contributing a flow node
 * writes the same listener it would write for Nextcloud Flow.
 *
 * It is also the better of the two mechanisms this fleet already has. The
 * alternative — OpenRegister's own MCP provider discovery — probes every
 * installed app's `info.xml`, builds candidate container aliases
 * (`OCA\OpenRegister\Mcp\IMcpToolProvider::<appId>`), resolves them through the
 * container, and then needs a distributed cache with two invalidation
 * mechanisms to stay affordable. That complexity exists because it scans for
 * something apps do not announce. Apps DO announce a listener, so none of it is
 * needed here.
 *
 * Dispatched lazily, only when the node list is actually needed, so an app that
 * never opens a flow never pays for it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCP\EventDispatcher\Event;

/**
 * Carries the registry an app registers its node types on.
 */
class RegisterFlowNodesEvent extends Event {
	/**
	 * Constructor.
	 *
	 * @param FlowNodeRegistry $registry The registry to contribute to.
	 */
	public function __construct(
		private readonly FlowNodeRegistry $registry,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Contribute a node type.
	 *
	 * @param IFlowNode $node The node type.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function registerNode(IFlowNode $node): void {
		$this->registry->register(node: $node);

	}//end registerNode()
}//end class
