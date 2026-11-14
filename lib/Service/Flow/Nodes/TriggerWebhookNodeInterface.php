<?php

/**
 * TriggerWebhookNodeInterface: the incoming-webhook entry point as the node registry asks for it.
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
 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#2-incoming-webhook-trigger
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\IFlowNode;

/**
 * The incoming-webhook trigger node.
 *
 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#2-incoming-webhook-trigger
 */
interface TriggerWebhookNodeInterface extends IFlowNode {
}//end interface
