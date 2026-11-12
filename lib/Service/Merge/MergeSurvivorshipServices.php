<?php

/**
 * OpenRegister MergeSurvivorshipServices
 *
 * The schema lookup and survivorship resolvers a merge recomputes its survivor with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Merge
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Merge;

use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Survivorship\SourceRecordResolver;
use OCA\OpenRegister\Service\Survivorship\SurvivorshipResolver;
use OCA\OpenRegister\Service\Survivorship\TrustTierResolver;

/**
 * The schema lookup and survivorship resolvers a merge recomputes its survivor with.
 *
 * @spec openspec/changes/mdm-merge-engine/tasks.md#4.1
 */
final readonly class MergeSurvivorshipServices {

	/**
	 * Bundle the services.
	 *
	 * @param SchemaMapperInterface $schemaMapper         Schema lookup for the merge + survivorship annotations.
	 * @param SurvivorshipResolver  $resolver             Pure golden-record resolver, reused for recompute.
	 * @param TrustTierResolver     $trustResolver        Pure trust-tier lookup + decay engine.
	 * @param SourceRecordResolver  $sourceRecordResolver Mode-aware source-record resolver (embedded | reverseFk).
	 *
	 * @spec openspec/changes/mdm-merge-engine/tasks.md#4.1
	 */
	public function __construct(
		public SchemaMapperInterface $schemaMapper,
		public SurvivorshipResolver $resolver,
		public TrustTierResolver $trustResolver,
		public SourceRecordResolver $sourceRecordResolver,
	) {
	}//end __construct()
}//end class
