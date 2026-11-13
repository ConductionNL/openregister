<?php

/**
 * Registers OpenRegister's own built-in flow node types.
 *
 * OpenRegister contributes its nodes through exactly the same event every other
 * app uses ({@see RegisterFlowNodesEvent}) rather than seeding the registry
 * directly from the container. If the owner of the mechanism does not use the
 * mechanism, the mechanism rots: a bug in contribution would show up for
 * consuming apps and never for us.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-nodes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Listener\FlowNodes\ActionFlowNodes;
use OCA\OpenRegister\Listener\FlowNodes\BoundaryFlowNodes;
use OCA\OpenRegister\Listener\FlowNodes\CoreFlowNodes;
use OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Contributes the built-in node types.
 *
 * @template-implements IEventListener<RegisterFlowNodesEvent>
 */
class FlowNodeRegistrationListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * The built-ins come in three families, each listing its nodes in the
	 * order they register; see {@see CoreFlowNodes}, {@see ActionFlowNodes}
	 * and {@see BoundaryFlowNodes} for why each group sits where it does.
	 *
	 * @param CoreFlowNodes     $core     Shaping, branching, routing and object steps.
	 * @param ActionFlowNodes   $actions  Reshaping, messaging, human and lock steps.
	 * @param BoundaryFlowNodes $boundary The case-layer steps and the entry points.
	 */
	public function __construct(
		private readonly CoreFlowNodes $core,
		private readonly ActionFlowNodes $actions,
		private readonly BoundaryFlowNodes $boundary,
	) {

	}//end __construct()

	/**
	 * Register the built-ins.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-nodes/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof RegisterFlowNodesEvent) === false) {
			return;
		}

		$nodes = [...$this->core->nodes(), ...$this->actions->nodes(), ...$this->boundary->nodes()];
		foreach ($nodes as $node) {
			$event->registerNode(node: $node);
		}

	}//end handle()
}//end class
