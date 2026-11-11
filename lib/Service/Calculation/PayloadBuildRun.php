<?php

/**
 * OpenRegister PayloadBuildRun
 *
 * One build of a calculation payload: the object being enriched and the schema
 * that declares its reference blocks.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calculation;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * The state CalculationPayloadBuilder::build() hands to its enrichment steps.
 * It never leaves CalculationPayloadBuilder.
 *
 * @spec openspec/specs/computed-fields/spec.md
 */
final class PayloadBuildRun {

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $object The object whose data is enriched.
	 * @param Schema       $schema The object's schema, which declares the reference blocks.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function __construct(
		public readonly ObjectEntity $object,
		public readonly Schema $schema,
	) {
	}//end __construct()
}//end class
