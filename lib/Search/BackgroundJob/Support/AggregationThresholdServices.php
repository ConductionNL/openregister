<?php

/**
 * OpenRegister aggregation threshold services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\Search\BackgroundJob\Support
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

namespace OCA\OpenRegister\Search\BackgroundJob\Support;

use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Search\Service\Aggregation\ThresholdEvaluationService;
use OCA\OpenRegister\Service\Deferral\DeferredEntryObjectResolver;

/**
 * What re-fetches a deferred entry, finds its schema and evaluates the schema's thresholds.
 *
 * @spec openspec/specs/event-driven-architecture/spec.md
 */
final class AggregationThresholdServices {
	/**
	 * Bundle the services.
	 *
	 * @param DeferredEntryObjectResolver $resolver     Stale-safe entry re-fetch.
	 * @param SchemaMapperInterface       $schemaMapper Schema lookup mapper.
	 * @param ThresholdEvaluationService  $evaluator    Shared threshold evaluation logic.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function __construct(
		public readonly DeferredEntryObjectResolver $resolver,
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly ThresholdEvaluationService $evaluator,
	) {
	}//end __construct()
}//end class
