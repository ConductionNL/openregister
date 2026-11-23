<?php

/**
 * ConfigurationFlowTransferInterface: carry flows in and out of a configuration document.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Exports a configuration's flows as `components.flows` entries and imports them back.
 *
 * The Configuration area reaches flows only through this port; the flow engine
 * (Service/Flow/ConfigurationFlowTransfer) holds the mapper and versioning logic.
 */
interface ConfigurationFlowTransferInterface {

	/**
	 * The flows behind the given uuids, keyed by uuid, without instance-specific
	 * properties (id, owner, organisation). A flow that cannot be loaded is logged
	 * and left out.
	 *
	 * @param array<int|string, mixed> $flowUuids The uuids a configuration lists.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function exportFlows(array $flowUuids): array;

	/**
	 * Import one `components.flows` entry: resolve an existing flow by uuid, write
	 * the imported fields when its graph differs (or $force), and publish it.
	 *
	 * @param array<string, mixed> $data         The flow data from the configuration document.
	 * @param string|null          $owner        The owner stamped on a newly created flow.
	 * @param string|null          $organisation The organisation stamped on a newly created flow.
	 * @param bool                 $force        Rewrite the flow even when its graph is unchanged.
	 *
	 * @return object|null The stored flow entity, or null when the entry was skipped.
	 */
	public function importFlow(array $data, ?string $owner = null, ?string $organisation = null, bool $force = false): ?object;
}//end interface
