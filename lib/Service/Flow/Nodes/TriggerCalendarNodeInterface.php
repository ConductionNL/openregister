<?php

/**
 * TriggerCalendarNodeInterface: the TriggerCalendarNode as the flow node registration listener reaches it.
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
 *
 * @spec openspec/changes/flow-calendar-and-webhook-triggers
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\IFlowNode;

/**
 * The TriggerCalendarNode node, typed for callers outside the Service component.
 *
 * Its only outside caller hands it to {@see \OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent::registerNode()}.
 */
interface TriggerCalendarNodeInterface extends IFlowNode {
}//end interface
