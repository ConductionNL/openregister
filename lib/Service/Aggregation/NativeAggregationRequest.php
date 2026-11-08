<?php

/**
 * OpenRegister NativeAggregationRequest
 *
 * One request to the native SQL aggregation path: the register and schema
 * and the aggregation asked of them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Aggregation
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

namespace OCA\OpenRegister\Service\Aggregation;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * Carries a native aggregation request through AggregationRunner.
 *
 * The spec holds metric, field, filter, the group spec, dateBucket, metrics,
 * cumulative and rowRbac, exactly as AggregationRunner::tryNativeAggregation()
 * received them.
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */
final class NativeAggregationRequest {

	/**
	 * Constructor.
	 *
	 * @param Register             $register The register the schema belongs to.
	 * @param Schema               $schema   The schema being aggregated.
	 * @param array<string, mixed> $spec     The aggregation asked: metric, field, filter, group spec,
	 *                                       dateBucket, metrics, cumulative, rowRbac.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	public function __construct(
		public readonly Register $register,
		public readonly Schema $schema,
		public readonly array $spec,
	) {
	}//end __construct()
}//end class
