<?php

/**
 * OpenRegister sources domain
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Service\Dbal\DatabaseIntrospectionServiceInterface;
use OCA\OpenRegister\Service\Dbal\DbalConnectionFactoryInterface;
use OCA\OpenRegister\Service\Dbal\QueryPreviewService;
use OCA\OpenRegister\Service\Sync\HarvestPipelineServiceInterface;
use OCA\OpenRegister\Service\Sync\SourceFetcherLookupInterface;
use OCA\OpenRegister\Service\Sync\SourceService;
use OCA\OpenRegister\Service\Flow\FlowServiceInterface;

/**
 * The domain services of the sources API.
 *
 * @spec openspec/specs/data-sync-harvesting/spec.md
 */
final class SourcesDomain {
	/**
	 * Bundle the services.
	 *
	 * @param SourceFetcherLookupInterface          $fetcherRegistry      Resolves the transport for a source type
	 * @param HarvestPipelineServiceInterface       $pipeline             Harvest pipeline orchestrator
	 * @param DbalConnectionFactoryInterface                 $connectionFactory    Opens read-only DBAL connections for database sources
	 * @param DatabaseIntrospectionServiceInterface $introspectionService Introspects a database source into a virtual register
	 * @param QueryPreviewService|null              $queryPreview         Previews a query-backed schema's SQL (null: built on demand)
	 * @param SourceService|null                    $sourceService        Validates and writes sources and their flows (null: raw mapper writes)
	 * @param FlowServiceInterface|null             $flows                Starts a scheduled source's flow on "sync now" (null: inline run)
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function __construct(
		public readonly SourceFetcherLookupInterface $fetcherRegistry,
		public readonly HarvestPipelineServiceInterface $pipeline,
		public readonly DbalConnectionFactoryInterface $connectionFactory,
		public readonly DatabaseIntrospectionServiceInterface $introspectionService,
		public readonly ?QueryPreviewService $queryPreview = null,
		public readonly ?SourceService $sourceService = null,
		public readonly ?FlowServiceInterface $flows = null,
	) {
	}//end __construct()
}//end class
