<?php

/**
 * OpenRegister FlowNodeInvoker
 *
 * Calls a flow node in whichever form it implements.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Kernel\Compat\ImplementationForm;
use RuntimeException;

/**
 * Runs a flow node written in either published form.
 *
 * @spec openspec/specs/flow-nodes/spec.md
 */
final class FlowNodeInvoker {

	/**
	 * Constructor
	 *
	 * @param ImplementationForm $form Tells the two forms apart.
	 */
	public function __construct(
		private readonly ImplementationForm $form=new ImplementationForm(),
	) {
	}//end __construct()

	/**
	 * Run the node on its input items.
	 *
	 * @param IFlowNode    $node The node.
	 * @param FlowNodeCall $call The input items, the step's configuration and the run-level context.
	 *
	 * @return array The output items.
	 *
	 * @throws RuntimeException When the node declares no execute().
	 */
	public function execute(IFlowNode $node, FlowNodeCall $call): array {
		if ($node instanceof ICallFlowNode) {
			return $node->execute($call);
		}

		if ($node instanceof ILegacyFlowNode) {
			return $node->execute($call->items, $call->config, $call->context);
		}

		if (method_exists($node, 'execute') === false) {
			throw new RuntimeException(sprintf('Flow node %s declares no execute().', $node::class));
		}

		$execute = [$node, 'execute'];
		if ($this->form->takesObject(implementer: $node, method: 'execute', parameterClass: FlowNodeCall::class) === true) {
			return $execute($call);
		}

		return $execute($call->items, $call->config, $call->context);
	}//end execute()
}//end class
