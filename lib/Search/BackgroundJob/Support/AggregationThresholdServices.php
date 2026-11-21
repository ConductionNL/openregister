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
use OCA\OpenRegister\Service\Deferral\DeferredEntryObjectResolverInterface;
use OCA\OpenRegister\Service\Rbac\ProcessRevealAuditInterface;

/**
 * What re-fetches a deferred entry, finds its schema and evaluates the schema's thresholds.
 *
 * @spec openspec/specs/event-driven-architecture/spec.md
 */
final class AggregationThresholdServices {
	/**
	 * Bundle the services.
	 *
	 * @param DeferredEntryObjectResolverInterface $resolver     Stale-safe entry re-fetch.
	 * @param SchemaMapperInterface       $schemaMapper Schema lookup mapper.
	 * @param ThresholdEvaluationService  $evaluator    Shared threshold evaluation logic.
	 * @param ProcessRevealAuditInterface|null $processAudit Records, once per chunk, that the job revealed audited values.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function __construct(
		public readonly DeferredEntryObjectResolverInterface $resolver,
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly ThresholdEvaluationService $evaluator,
		public readonly ?ProcessRevealAuditInterface $processAudit = null,
	) {
	}//end __construct()
}//end class
