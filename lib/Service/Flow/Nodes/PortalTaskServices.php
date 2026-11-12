<?php

/**
 * The services the portal-task step creates, addresses and delivers its ask with.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\FlowTaskBridge;
use OCA\OpenRegister\Service\Portal\PortalPartyResolver;
use OCA\OpenRegister\Service\Portal\PortalTaskDeliveryService;

/**
 * The services the portal-task step creates, addresses and delivers its ask with.
 *
 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-a-portal-task-step-creates-one-external-task-and-suspends-the-run
 */
final class PortalTaskServices {
	/**
	 * Bundle the services.
	 *
	 * @param FlowTaskBridge            $bridge   Creates and reads the node's task.
	 * @param PortalPartyResolver       $parties  Resolves the party role against the case.
	 * @param PortalTaskDeliveryService $delivery Records the ask for the portal seam.
	 */
	public function __construct(
		public readonly FlowTaskBridge $bridge,
		public readonly PortalPartyResolver $parties,
		public readonly PortalTaskDeliveryService $delivery,
	) {
	}//end __construct()
}//end class
