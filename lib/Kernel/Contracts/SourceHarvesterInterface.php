<?php

/**
 * OpenRegister SourceHarvesterInterface
 *
 * Runs one source's harvest for a caller outside Integrations, such as the harvest flow step.
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
 * Runs one source's harvest: look the source up, check it can run, hold its lock, run the
 * pipeline and record the last sync on the source.
 *
 * Implemented by \OCA\OpenRegister\Service\Sync\SourceHarvester (Integrations); the flow step
 * \OCA\OpenRegister\Service\Flow\Nodes\HarvestSourceNode (Processes) calls it.
 */
interface SourceHarvesterInterface {

	/**
	 * The flow node type of the harvest step.
	 */
	public const NODE_TYPE = 'openregister.harvest-source';

	/**
	 * Harvest one source.
	 *
	 * @param string $sourceId    The uuid or id of the source.
	 * @param string $executionId The run the harvest belongs to.
	 *
	 * @return array<string, mixed> The pipeline's summary.
	 *
	 * @throws SourceHarvestRefused When the source is missing or disabled, has no fetcher or mapping, or is already being harvested.
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function harvest(string $sourceId, string $executionId): array;
}//end interface
