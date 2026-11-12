<?php

/**
 * OpenRegister sync data services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob\Support;

use OCA\OpenRegister\Db\SourceMapperInterface;
use OCA\OpenRegister\Service\Sync\HarvestPipelineServiceInterface;
use OCA\OpenRegister\Service\Sync\SourceFetcherLookupInterface;
use OCA\OpenRegister\Service\Sync\SyncScheduleInterface;

/**
 * The sources, their schedule, their fetchers and the harvest pipeline of the hourly data sync.
 *
 * @spec openspec/specs/data-sync-harvesting/spec.md
 */
final readonly class SyncDataServices {
	/**
	 * Bundle the services.
	 *
	 * @param SourceMapperInterface           $sourceMapper    Source store.
	 * @param SyncScheduleInterface           $scheduleService Selects the due sources.
	 * @param SourceFetcherLookupInterface    $fetcherRegistry Fetcher per source type.
	 * @param HarvestPipelineServiceInterface $pipeline        Runs one harvest.
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function __construct(
		public SourceMapperInterface $sourceMapper,
		public SyncScheduleInterface $scheduleService,
		public SourceFetcherLookupInterface $fetcherRegistry,
		public HarvestPipelineServiceInterface $pipeline,
	) {
	}//end __construct()
}//end class
