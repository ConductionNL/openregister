<?php

/**
 * PerformanceOptimizationHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
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
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class PerformanceOptimizationHandler
{
    /**
     * Constructor for PerformanceOptimizationHandler.
     *
     * @param OrganisationService $organisationService Organisation service for context.
     * @param LoggerInterface     $logger              Logger for debugging.
     */
    public function __construct(
        private readonly OrganisationService $organisationService,
        private readonly LoggerInterface $logger
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
     */
    public function getActiveOrganisationForContext(): ?string
    {
        try {
            $activeOrganisation = $this->organisationService->getActiveOrganisation();
            if ($activeOrganisation !== null) {
                $uuid = $activeOrganisation->getUuid();
                $this->logger->debug(
                    '[PerformanceOptimizationHandler] Got active organisation for context',
                    ['organisationUuid' => $uuid, 'organisationName' => $activeOrganisation->getName()]
                );
                return $uuid;
            }

            $this->logger->debug('[PerformanceOptimizationHandler] No active organisation for current user');
            return null;
        } catch (Exception $e) {
            // Log error but continue without organization context.
            $this->logger->warning(
                '[PerformanceOptimizationHandler] Failed to get active organisation',
                ['error' => $e->getMessage()]
            );
            return null;
        }
    }//end getActiveOrganisationForContext()

    /**
     * Get performance recommendations based on query timings.
     *
     * This method analyzes performance metrics and provides actionable recommendations
     * for optimizing slow queries and improving response times.
     *
     * @param float $totalTime   Total query execution time in milliseconds.
     * @param array $perfTimings Detailed timing breakdown by operation.
     * @param array $query       Query parameters for context.
     *
     * @return array List of performance recommendations with type, issue, message, and suggestions.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Multiple recommendation thresholds require branching
     * @SuppressWarnings(PHPMD.NPathComplexity)       Different timing scenarios generate different recommendations
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Comprehensive recommendations require detailed analysis
     */
    public function getPerformanceRecommendations(float $totalTime, array $perfTimings, array $query): array
    {
        $recommendations = [];

        // Time-based recommendations.
        if ($totalTime > 2000) {
            $recommendations[] = [
                'type'        => 'critical',
                'issue'       => 'Very slow response time',
                'message'     => "Total time {$totalTime}ms exceeds 2s threshold",
                'suggestions' => [
                    'Enable caching with appropriate TTL',
                    'Reduce _extend complexity or use selective loading',
                    'Consider database indexing optimization',
                    'Implement pagination with smaller page sizes',
                ],
            ];
        } else if ($totalTime > 500) {
            $recommendations[] = [
                'type'        => 'warning',
                'issue'       => 'Slow response time',
                'message'     => "Total time {$totalTime}ms exceeds 500ms target",
                'suggestions' => [
                    'Consider enabling caching',
                    'Optimize _extend usage',
                    'Review database query complexity',
                ],
            ];
        }//end if

        // Database query optimization.
        if (($perfTimings['database_query'] ?? 0) > 200) {
            $recommendations[] = [
                'type'        => 'warning',
                'issue'       => 'Slow database queries',
                'message'     => "Database query time {$perfTimings['database_query']}ms is high",
                'suggestions' => [
                    'Add database indexes for frequently filtered columns',
                    'Optimize WHERE clauses',
                    'Consider selective field loading',
                ],
            ];
        }

        // Relationship loading optimization.
        if (($perfTimings['relationship_loading'] ?? 0) > 1000) {
            $recommendations[] = [
                'type'        => 'critical',
                'issue'       => 'Very slow relationship loading',
                'message'     => "Relationship loading time {$perfTimings['relationship_loading']}ms is excessive",
                'suggestions' => [
                    'Reduce number of _extend relationships',
                    'Use selective relationship loading',
                    'Consider relationship caching',
                    'Implement relationship pagination if applicable',
                ],
            ];
        }

        // Extend usage recommendations.
        $extendCount = 0;
        if (empty($query['_extend']) === false) {
            // Calculate extend count - count array elements or string length.
            $extendCount = 1;
            if (is_array($query['_extend']) === true) {
                $extendCount = count($query['_extend']);
            }
        }

        if ($extendCount > 3) {
            $recommendations[] = [
                'type'        => 'warning',
                'issue'       => 'High _extend usage',
                'message'     => 'Loading many relationships simultaneously',
                'suggestions' => [
                    'Consider reducing the number of _extend parameters',
                    'Use selective loading for only required relationships',
                    'Implement client-side lazy loading for secondary data',
                ],
            ];
        }

        // JSON processing optimization.
        if (($perfTimings['json_processing'] ?? 0) > 100) {
            $recommendations[] = [
                'type'        => 'info',
                'issue'       => 'JSON processing overhead',
                'message'     => "JSON processing time {$perfTimings['json_processing']}ms could be optimized",
                'suggestions' => [
                    'Consider JSON field truncation for large objects',
                    'Implement selective JSON field loading',
                    'Use lightweight object serialization',
                ],
            ];
        }

        // Success case.
        if ($totalTime <= 500 && empty($recommendations) === true) {
            $recommendations[] = [
                'type'        => 'success',
                'issue'       => 'Excellent performance',
                'message'     => "Response time {$totalTime}ms meets performance target",
                'suggestions' => [
                    'Current optimization level is excellent',
                    'Consider this configuration as a performance baseline',
                ],
            ];
        }

        return $recommendations;
    }//end getPerformanceRecommendations()
}//end class
