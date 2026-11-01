<?php

/**
 * PerformanceOptimizationHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Service\Object;

use Exception;
use OCA\OpenRegister\Service\OrganisationService;
use Psr\Log\LoggerInterface;

/**
 * Handles performance optimization utilities for ObjectService.
 *
 * This handler provides:
 * - Active organization context retrieval
 * - Request optimization for performance
 * - Performance monitoring utilities
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class PerformanceOptimizationHandler {

	/**
	 * The recommendation each measure earns above its threshold (and at most its ceiling), in report order.
	 *
	 * The message carries the measured value where it has a `%s`.
	 *
	 * @var array<int, array{measure: string, over: int, atMost?: int, type: string, issue: string, message: string, suggestions: array<int, string>}>
	 */
	private const RECOMMENDATION_RULES = [
		[
			'measure' => 'total',
			'over' => 2000,
			'type' => 'critical',
			'issue' => 'Very slow response time',
			'message' => 'Total time %sms exceeds 2s threshold',
			'suggestions' => [
				'Enable caching with appropriate TTL',
				'Reduce _extend complexity or use selective loading',
				'Consider database indexing optimization',
				'Implement pagination with smaller page sizes',
			],
		],
		[
			'measure' => 'total',
			'over' => 500,
			'atMost' => 2000,
			'type' => 'warning',
			'issue' => 'Slow response time',
			'message' => 'Total time %sms exceeds 500ms target',
			'suggestions' => [
				'Consider enabling caching',
				'Optimize _extend usage',
				'Review database query complexity',
			],
		],
		[
			'measure' => 'database_query',
			'over' => 200,
			'type' => 'warning',
			'issue' => 'Slow database queries',
			'message' => 'Database query time %sms is high',
			'suggestions' => [
				'Add database indexes for frequently filtered columns',
				'Optimize WHERE clauses',
				'Consider selective field loading',
			],
		],
		[
			'measure' => 'relationship_loading',
			'over' => 1000,
			'type' => 'critical',
			'issue' => 'Very slow relationship loading',
			'message' => 'Relationship loading time %sms is excessive',
			'suggestions' => [
				'Reduce number of _extend relationships',
				'Use selective relationship loading',
				'Consider relationship caching',
				'Implement relationship pagination if applicable',
			],
		],
		[
			'measure' => 'extend',
			'over' => 3,
			'type' => 'warning',
			'issue' => 'High _extend usage',
			'message' => 'Loading many relationships simultaneously',
			'suggestions' => [
				'Consider reducing the number of _extend parameters',
				'Use selective loading for only required relationships',
				'Implement client-side lazy loading for secondary data',
			],
		],
		[
			'measure' => 'json_processing',
			'over' => 100,
			'type' => 'info',
			'issue' => 'JSON processing overhead',
			'message' => 'JSON processing time %sms could be optimized',
			'suggestions' => [
				'Consider JSON field truncation for large objects',
				'Implement selective JSON field loading',
				'Use lightweight object serialization',
			],
		],
	];

	/**
	 * Constructor for PerformanceOptimizationHandler.
	 *
	 * @param OrganisationService $organisationService Organisation service for context.
	 * @param LoggerInterface $logger Logger for debugging.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly OrganisationService $organisationService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the active organization for the current user context.
	 *
	 * This method determines the active organization using the OrganisationService
	 * to ensure consistency between save and retrieval operations.
	 *
	 * @return string|null The active organisation UUID or null if not available.
	 *
	 * @psalm-return   string|null
	 * @phpstan-return string|null
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getActiveOrganisationForContext(): ?string {
		try {
			$activeOrganisation = $this->organisationService->getActiveOrganisation();
			if ($activeOrganisation !== null) {
				$uuid = $activeOrganisation->getUuid();
				$this->logger->debug(
					message: '[PerformanceOptimizationHandler] Got active organisation for context',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'organisationUuid' => $uuid,
						'organisationName' => $activeOrganisation->getName(),
					]
				);
				return $uuid;
			}

			$this->logger->debug(
				message: '[PerformanceOptimizationHandler] No active organisation for current user',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		} catch (Exception $e) {
			// Log error but continue without organization context.
			$this->logger->warning(
				message: '[PerformanceOptimizationHandler] Failed to get active organisation',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return null;
		}//end try
	}//end getActiveOrganisationForContext()

	/**
	 * Get performance recommendations based on query timings.
	 *
	 * This method analyzes performance metrics and provides actionable recommendations
	 * for optimizing slow queries and improving response times.
	 *
	 * @param float $totalTime Total query execution time in milliseconds.
	 * @param array $perfTimings Detailed timing breakdown by operation.
	 * @param array $query Query parameters for context.
	 *
	 * @return array List of performance recommendations with type, issue, message, and suggestions.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getPerformanceRecommendations(float $totalTime, array $perfTimings, array $query): array {
		$measures = [
			'total' => $totalTime,
			'database_query' => ($perfTimings['database_query'] ?? 0),
			'relationship_loading' => ($perfTimings['relationship_loading'] ?? 0),
			'extend' => $this->extendCount(query: $query),
			'json_processing' => ($perfTimings['json_processing'] ?? 0),
		];

		$recommendations = [];
		foreach (self::RECOMMENDATION_RULES as $rule) {
			$value = $measures[$rule['measure']];
			if ($value > $rule['over'] && (isset($rule['atMost']) === false || $value <= $rule['atMost'])) {
				$recommendations[] = [
					'type' => $rule['type'],
					'issue' => $rule['issue'],
					'message' => sprintf($rule['message'], $value),
					'suggestions' => $rule['suggestions'],
				];
			}
		}

		if ($totalTime <= 500 && empty($recommendations) === true) {
			$recommendations[] = [
				'type' => 'success',
				'issue' => 'Excellent performance',
				'message' => "Response time {$totalTime}ms meets performance target",
				'suggestions' => [
					'Current optimization level is excellent',
					'Consider this configuration as a performance baseline',
				],
			];
		}

		return $recommendations;
	}//end getPerformanceRecommendations()

	/**
	 * How many relationships a query extends: the list length, or 1 for a non-empty string.
	 *
	 * @param array $query Query parameters.
	 *
	 * @return int The extend count.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function extendCount(array $query): int {
		if (empty($query['_extend']) === true) {
			return 0;
		}

		if (is_array($query['_extend']) === true) {
			return count($query['_extend']);
		}

		return 1;
	}//end extendCount()
}//end class
