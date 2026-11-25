<?php

/**
 * Contract of the ExtendInputNode node.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-parity-mapping-and-webhooks/specs/flow-rule-parity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\IFlowNode;

/**
 * The ExtendInputNode node, typed for callers outside the Service component.
 *
 * Its only outside caller hands it to {@see \OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent::registerNode()},
 * which takes an IFlowNode, so the contract adds nothing to IFlowNode. Binding it
 * in FlowWiring lets that caller resolve the node without naming the class.
 */
interface ExtendInputNodeInterface extends IFlowNode {
}//end interface
