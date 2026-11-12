<?php

/**
 * SendTalkMessageNodeInterface: the SendTalkMessageNode as the flow node registration listener reaches it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
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

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\IFlowNode;

/**
 * The SendTalkMessageNode node, typed for callers outside the Service component.
 *
 * Its only outside caller hands it to {@see \OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent::registerNode()},
 * which takes an IFlowNode, so the contract adds nothing to IFlowNode. Binding it
 * in ContractBindings lets that caller resolve the node without naming the class.
 */
interface SendTalkMessageNodeInterface extends IFlowNode {
}//end interface
