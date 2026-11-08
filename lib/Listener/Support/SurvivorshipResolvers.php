<?php

/**
 * OpenRegister survivorship resolvers
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener\Support
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

namespace OCA\OpenRegister\Listener\Support;

use OCA\OpenRegister\Service\Survivorship\SourceRecordResolverInterface;
use OCA\OpenRegister\Service\Survivorship\SurvivorshipResolverInterface;
use OCA\OpenRegister\Service\Survivorship\TrustTierResolverInterface;

/**
 * The survivorship, trust-tier and source-record resolvers a master recompute decides each field with.
 *
 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#4.1
 */
final readonly class SurvivorshipResolvers {
	/**
	 * Bundle the services.
	 *
	 * @param SurvivorshipResolverInterface $resolver             Picks the surviving value per field.
	 * @param TrustTierResolverInterface    $trustResolver        Ranks the sources.
	 * @param SourceRecordResolverInterface $sourceRecordResolver Finds a master's source records.
	 *
	 * @spec openspec/changes/mdm-survivorship-engine/tasks.md#4.1
	 */
	public function __construct(
		public SurvivorshipResolverInterface $resolver,
		public TrustTierResolverInterface $trustResolver,
		public SourceRecordResolverInterface $sourceRecordResolver,
	) {
	}//end __construct()
}//end class
