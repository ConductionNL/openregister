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
use OCA\OpenRegister\Service\Dbal\DbalConnectionFactory;
use OCA\OpenRegister\Service\Sync\HarvestPipelineService;
use OCA\OpenRegister\Service\Sync\SourceFetcherRegistry;

/**
 * The domain services of the sources API.
 *
 * @spec openspec/specs/data-sync-harvesting/spec.md
 */
final class SourcesDomain {
	/**
	 * Bundle the services.
	 *
	 * @param SourceFetcherRegistry                 $fetcherRegistry      Resolves the transport for a source type
	 * @param HarvestPipelineService                $pipeline             Harvest pipeline orchestrator
	 * @param DbalConnectionFactory                 $connectionFactory    Opens read-only DBAL connections for database sources
	 * @param DatabaseIntrospectionServiceInterface $introspectionService Introspects a database source into a virtual register
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function __construct(
		public readonly SourceFetcherRegistry $fetcherRegistry,
		public readonly HarvestPipelineService $pipeline,
		public readonly DbalConnectionFactory $connectionFactory,
		public readonly DatabaseIntrospectionServiceInterface $introspectionService,
	) {
	}//end __construct()
}//end class
