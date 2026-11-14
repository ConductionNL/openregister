<?php

/**
 * OpenRegister ICallFlowNode
 *
 * A flow node that takes one FlowNodeCall.
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

/**
 * A flow node in the parameter-object form.
 *
 * @spec openspec/specs/flow-nodes/spec.md
 */
interface ICallFlowNode extends IFlowNode {
	/**
	 * Do the work: items in, items out.
	 *
	 * This is the method `IOperation` has no equivalent of, and the reason
	 * this interface exists at all.
	 *
	 * A node that acts per record acts once per item and returns one item per
	 * result. A node that filters returns fewer items than it received. An
	 * empty returned list is meaningful — it ends that branch's data.
	 *
	 * Throwing is meaningful: the engine reads the step's `onError` policy to
	 * decide whether to stop, continue, or dead-letter. Catching here defeats
	 * that policy and produces a run that reports success while doing nothing.
	 *
	 * @param FlowNodeCall $call The input items ({@see FlowItems}), the step's
	 *                           authored configuration and the run-level
	 *                           context, which is NOT the data channel.
	 *
	 * @return array The output items.
	 *
	 * @spec openspec/specs/flow-nodes/spec.md
	 */
	public function execute(FlowNodeCall $call): array;
}//end interface
