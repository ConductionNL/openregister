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
 * cumulative and rowRbac; a key the caller leaves out takes its DEFAULTS value.
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */
final class NativeAggregationRequest {

	/**
	 * The values a spec key takes when the caller does not ask for it.
	 */
	public const DEFAULTS = [
		'field'      => null,
		'filter'     => [],
		'groupBy'    => null,
		'dateBucket' => null,
		'metrics'    => null,
		'cumulative' => false,
		'rowRbac'    => true,
	];

	/**
	 * The aggregation asked: metric, field, filter, group spec, dateBucket,
	 * metrics, cumulative, rowRbac, every key present.
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $spec;

	/**
	 * Constructor.
	 *
	 * @param Register             $register The register the schema belongs to.
	 * @param Schema               $schema   The schema being aggregated.
	 * @param array<string, mixed> $spec     The aggregation asked: metric plus any of the DEFAULTS keys.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	public function __construct(
		public readonly Register $register,
		public readonly Schema $schema,
		array $spec,
	) {
		$this->spec = $spec + self::DEFAULTS;
	}//end __construct()
}//end class
