<?php

/**
 * OpenRegister StoredFlowWriterInterface
 *
 * Writes or removes one stored flow by uuid, for an owner outside Processes (a scheduled source).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * One scheduled flow, keyed by uuid, kept equal to the definition its owner describes.
 *
 * Implemented by \OCA\OpenRegister\Service\Flow\StoredFlowWriter (Processes); called by
 * \OCA\OpenRegister\Service\Sync\SourceHarvestFlow (Integrations).
 */
interface StoredFlowWriterInterface {

	/**
	 * Create the flow, update it in place when its graph and cron are unchanged, or draft and republish it.
	 *
	 * @param string               $uuid       The flow uuid.
	 * @param array<string, mixed> $definition name, app, cron, nodes, edges, enabled and organisation.
	 * @param string               $owner      The owner of a new flow; a stored flow keeps its own.
	 *
	 * @return string The flow uuid.
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function put(string $uuid, array $definition, string $owner): string;

	/**
	 * Delete the flow when one is stored.
	 *
	 * @param string $uuid The flow uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function remove(string $uuid): void;
}//end interface
