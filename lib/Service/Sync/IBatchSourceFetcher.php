<?php

/**
 * OpenRegister Batch Source Fetcher Interface
 *
 * A fetcher whose source carries its items in one document (a DCAT catalogue, a CSV file, an OAI-PMH
 * page) returns them whole, so the pipeline issues no per-item request.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sync
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

namespace OCA\OpenRegister\Service\Sync;

use OCA\OpenRegister\Db\Source;

/**
 * Whole-document fetcher contract.
 *
 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
 */
interface IBatchSourceFetcher extends SourceFetcherInterface {
	/**
	 * Read the source and return its items whole.
	 *
	 * @param Source            $source The source.
	 * @param string|null       $since  The incremental checkpoint, or null for a full read.
	 * @param HarvestHttpClient $http   The guarded client every request goes through.
	 *
	 * @return HarvestBatch The batch.
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function gatherItems(Source $source, ?string $since, HarvestHttpClient $http): HarvestBatch;
}//end interface
