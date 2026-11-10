<?php

/**
 * Runs the harvest pipeline for one source, as the sources API and the sync job start it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sync
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Sync;

use OCA\OpenRegister\Db\Source;

/**
 * Runs the harvest pipeline for one source, as the sources API and the sync job start it.
 *
 * Declares only what callers outside lib/Service use; the container binds it
 * to {@see HarvestPipelineService} in ContractBindings.
 */
interface HarvestPipelineServiceInterface {

	/**
	 * Run the full pipeline for a source.
	 *
	 * @param Source $source The source to harvest
	 * @param SourceFetcherInterface $fetcher Transport for gather/fetch
	 * @param string $executionId Unique id for this run
	 * @param string|null $since Incremental checkpoint (null = full)
	 *
	 * @return array<string, int|string> Execution summary (counts + status)
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function run(
		Source $source,
		SourceFetcherInterface $fetcher,
		string $executionId,
		?string $since = null,
	): array;
}//end interface
