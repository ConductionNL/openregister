<?php

/**
 * OpenRegister AggregationSources
 *
 * Where the aggregation runner reads figures from: the schema catalogue, native SQL, the result cache and external DBAL registers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Aggregation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationCacheInterface;
use OCA\OpenRegister\Service\ObjectSource\DbalObjectSourceProvider;
use OCP\IDBConnection;

/**
 * Where the aggregation runner reads figures from: the schema catalogue, native SQL, the result cache and external DBAL registers.
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */
final readonly class AggregationSources {
	/**
	 * Bundle the collaborators.
	 *
	 * @param SchemaCatalogInterface        $catalog            The register and schema loaders and the magic-table mapper.
	 * @param IDBConnection                 $db                 Database connection for the Postgres-native fast path.
	 * @param AggregationCacheInterface     $cache              60s aggregation result cache.
	 * @param DbalObjectSourceProvider|null $dbalSourceProvider Aggregates an external DBAL register; null disables that path.
	 */
	public function __construct(
		public SchemaCatalogInterface $catalog,
		public IDBConnection $db,
		public AggregationCacheInterface $cache,
		public ?DbalObjectSourceProvider $dbalSourceProvider = null,
	) {
	}//end __construct()
}//end class
