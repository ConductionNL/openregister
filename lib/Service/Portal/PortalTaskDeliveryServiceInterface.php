<?php

/**
 * PortalTaskDeliveryServiceInterface: the part of PortalTaskDeliveryService that the portal task controller uses.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Portal
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-delivery-rides-the-portal-contribution-surface-and-nothing-else
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Portal;

use OCA\OpenRegister\Db\PortalTaskDelivery;

/**
 * The methods of PortalTaskDeliveryService that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface PortalTaskDeliveryServiceInterface {

	/**
	 * The requests portaliq has not yet reported on, oldest first.
	 *
	 * @param int $limit Page size.
	 *
	 * @return array<int, PortalTaskDelivery> The pending rows.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-delivery-rides-the-portal-contribution-surface-and-nothing-else
	 */
	public function pending(int $limit = 100): array;

	/**
	 * Portaliq reports a request as sent.
	 *
	 * @param string $uuid The delivery uuid.
	 *
	 * @return PortalTaskDelivery The settled row.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such request exists.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-delivery-rides-the-portal-contribution-surface-and-nothing-else
	 */
	public function markDelivered(string $uuid): PortalTaskDelivery;

	/**
	 * Portaliq reports a request as failed, and why.
	 *
	 * @param string $uuid The delivery uuid.
	 * @param string $error The failure.
	 *
	 * @return PortalTaskDelivery The settled row.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such request exists.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-delivery-rides-the-portal-contribution-surface-and-nothing-else
	 */
	public function markFailed(string $uuid, string $error): PortalTaskDelivery;
}//end interface
