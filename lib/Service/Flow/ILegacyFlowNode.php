<?php

/**
 * OpenRegister ILegacyFlowNode
 *
 * A flow node in the released positional form.
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
 * A flow node in the positional form of the released contract.
 *
 * @deprecated Implement ICallFlowNode.
 *
 * @spec openspec/specs/flow-nodes/spec.md
 */
interface ILegacyFlowNode extends IFlowNode {
	/**
	 * Do the work: items in, items out.
	 *
	 * @param array $items   The input items.
	 * @param array $config  The step's authored configuration.
	 * @param array $context The run-level context, which is NOT the data channel.
	 *
	 * @return array The output items.
	 *
	 * @spec openspec/specs/flow-nodes/spec.md
	 */
	public function execute(array $items, array $config, array $context): array;
}//end interface
