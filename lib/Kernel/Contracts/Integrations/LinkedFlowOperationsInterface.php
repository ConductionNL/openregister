<?php
/**
 * LinkedFlowOperationsInterface: the Flow operations linked to an object, as the relations API asks them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Integrations
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Integrations;

/**
 * The flow link register behind the relations API's "flow" type.
 *
 * The relations API (Integrations) lists an object's linked operations next to its other
 * links; Processes keeps the flow links and binds its service in its own wiring.
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */
interface LinkedFlowOperationsInterface {

	/**
	 * Whether NC WorkflowEngine is installed.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function isFlowAvailable(): bool;

	/**
	 * Return the linked Flow operations for an object, refreshing the
	 * cached `enabled` / `operation_name` / `entity_type` columns from
	 * `flow_operations` on every call.
	 *
	 * Available to everyone (read-only) — non-admins still see which
	 * automations are bound to an OR object even though they can't
	 * change them.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function getLinkedOperations(string $objectUuid): array;
}//end interface
