<?php

/**
 * SearchQueryHandler - Search and Query Operations Handler
 *
 * Handles all search query building, execution, and pagination operations.
 * This handler separates search-related business logic from the main ObjectService,
 * improving code organization and maintainability.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Objects
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use Exception;
use OCA\OpenRegister\Db\ViewMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\SettingsService;
use Psr\Log\LoggerInterface;

/**
 * SearchQueryHandler class
 *
 * Handles search query operations including:
 * - Query building and parameter normalization
 * - View-based filtering
 * - Search execution (sync/async/database)
 * - Pagination URL generation
 * - Search trail logging
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Objects
 */
class SearchQueryHandler
{
    /**
     * SearchQueryHandler constructor.
     *
     * @param ViewMapper      $viewMapper      Mapper for view operations.
     * @param SchemaMapper    $schemaMapper    Mapper for schema operations.
     * @param SettingsService $settingsService Service for settings operations.
     * @param LoggerInterface $logger          Logger for performance monitoring.
     */
    public function __construct(
        private readonly ViewMapper $viewMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Build search query from request parameters
     *
     * Converts HTTP request parameters into a structured query array for searchObjectsPaginated.
     * Handles PHP's dot-to-underscore parameter name conversion, extracts metadata filters,
     * and separates object field filters from system parameters.
     *
     * @param array                 $requestParams Request parameters from HTTP request.
     * @param int|string|array|null $register      Optional register ID(s) to filter by.
     * @param int|string|array|null $schema        Optional schema ID(s) to filter by.
     * @param array|null            $ids           Optional array of object IDs to filter by.
     *
     * @return array<string, mixed> Structured query array
     */
    public function buildSearchQuery(
        array $requestParams,
        int | string | array | null $register = null,
        int | string | array | null $schema = null,
        ?array $ids = null
    ): array {
        // STEP 1: Fix PHP's dot-to-underscore mangling in query parameter names.
        // PHP converts dots to underscores in parameter names, e.g.:.
        // @self.register → @self_register.
        // Person.address.street → person_address_street.
        // We need to reconstruct nested arrays from underscore-separated paths.
        $fixedParams = [];
        foreach ($requestParams as $key => $value) {
            // Skip parameters that start with underscore (system parameters like _limit, _offset).
            if (str_starts_with(haystack: $key, needle: '_') === true) {
                $fixedParams[$key] = $value;
                continue;
            }

            // Check if key contains underscores (indicating PHP mangled dots).
            if (str_contains($key, '_') === true) {
                // Split by underscore to reconstruct nested structure.
                $parts = explode('_', $key);

                // Build nested array structure.
                $current   = &$fixedParams;
                $lastIndex = count($parts) - 1;

                foreach ($parts as $index => $part) {
                    if ($index === $lastIndex) {
                        // Last part: assign the value.
                        $current[$part] = $value;
                        continue;
                    }

                    // Intermediate part: create nested array if needed.
                    if (isset($current[$part]) === false) {
                        $current[$part] = [];
                    }

                    if (isset($current[$part]) === true) {
                        // Ensure it's an array, reset if not.
                        /*
                         * @psalm-suppress TypeDoesNotContainType - $current[$part] may have been set to non-array earlier
                         */

                        if (is_array($current[$part]) === false) {
                            $current[$part] = [];
                        }
                    }

                    $current = &$current[$part];
                }//end foreach

                continue;
            }//end if

            // No underscores: use as-is.
            $fixedParams[$key] = $value;
        }//end foreach

        // STEP 2: Remove system parameters that shouldn't be used as filters.
        $params = $fixedParams;
        unset(
            $params['id'],
            $params['_route'],
            $params['rbac'],
            $params['multi'],
            $params['published'],
            $params['deleted']
        );

        // Build the query structure for searchObjectsPaginated.
        $query = [];

        // Extract metadata filters into @self.
        $metadataFields = [
            'register',
            'schema',
            'uuid',
            'organisation',
            'owner',
            'application',
            'created',
            'updated',
            'published',
            'depublished',
            'deleted',
        ];
        $query['@self'] = [];

        // Add register and schema to @self if provided.
        // Support both single values and arrays for multi-register/schema filtering.
        if ($register !== null) {
            /*
             * @var int|string|array $registerValue
             */

            $registerValue = $register;
            $query['@self']['register'] = (int) $registerValue;
            if (is_array($registerValue) === true) {
                // Convert array values to integers.
                $query['@self']['register'] = array_map('intval', $registerValue);
            }
        }

        if ($schema !== null) {
            /*
             * @var int|string|array $schemaValue
             */

            $schemaValue = $schema;
            $query['@self']['schema'] = (int) $schemaValue;
            if (is_array($schemaValue) === true) {
                // Convert array values to integers.
                $query['@self']['schema'] = array_map('intval', $schemaValue);
            }
        }

        // Query structure built successfully.
        // Extract special underscore parameters.
        $specialParams = [];
        $objectFilters = [];

        foreach ($params as $key => $value) {
            if (str_starts_with(haystack: $key, needle: '_') === true) {
                $specialParams[$key] = $value;
            } elseif (in_array(needle: $key, haystack: $metadataFields) === true) {
                // Only add to @self if not already set from function parameters.
                if (isset($query['@self'][$key]) === false) {
                    $query['@self'][$key] = $value;
                }

                continue;
            }

            // This is an object field filter.
            $objectFilters[$key] = $value;
        }

        // Add object field filters directly to query.
        $query = array_merge($query, $objectFilters);

        // Add IDs if provided.
        if ($ids !== null) {
            $query['_ids'] = $ids;
        }

        // Support both 'ids' and '_ids' parameters for flexibility.
        if (isset($specialParams['ids']) === true) {
            $query['_ids'] = $specialParams['ids'];
            // Remove to avoid duplication.
            unset($specialParams['ids']);
        }

        // Add all special parameters (they'll be handled by searchObjectsPaginated).
        // Convert boolean-like parameters to actual booleans for consistency.
        if (isset($specialParams['_published']) === true) {
            $specialParams['_published'] = filter_var(
                $specialParams['_published'],
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            ) ?? false;
        }

        $query = array_merge($query, $specialParams);

        return $query;
    }//end buildSearchQuery()

    /**
     * Apply view filters to a query
     *
     * Converts view definitions into query parameters by merging view->query into the base query.
     * Supports multiple views - their filters are combined (OR logic for same field, AND for different fields).
     *
     * @param array<string, mixed> $query   Base query parameters.
     * @param array<int>           $viewIds View IDs to apply.
     *
     * @return array<string, mixed> Query with view filters applied
     */
    public function applyViewsToQuery(array $query, array $viewIds): array
    {
        if (empty($viewIds) === true) {
            return $query;
        }

        $this->logger->debug(
            message: '[SearchQueryHandler] Applying views to query',
            context: [
                'viewIds'       => $viewIds,
                'originalQuery' => array_keys($query),
            ]
        );

        foreach ($viewIds as $viewId) {
            try {
                $view      = $this->viewMapper->find($viewId);
                $viewQuery = $view->getQuery();

                // Apply registers filter using @self metadata (format ObjectEntityMapper understands).
                if (empty($viewQuery['registers']) === false) {
                    if (isset($query['@self']) === false) {
                        $query['@self'] = [];
                    }

                    $registerValue = $query['@self']['register'] ?? null;
                    $registerArray = [];
                    if (is_array($registerValue) === true) {
                        $registerArray = $registerValue;
                    } elseif ($registerValue !== null && $registerValue !== false) {
                        $registerArray = [$registerValue];
                    }

                    $query['@self']['register'] = array_unique(
                        array_merge(
                            $registerArray,
                            $viewQuery['registers']
                        )
                    );
                }//end if

                // Apply schemas filter using @self metadata (format ObjectEntityMapper understands).
                if (empty($viewQuery['schemas']) === false) {
                    if (isset($query['@self']) === false) {
                        $query['@self'] = [];
                    }

                    $schemaValue = $query['@self']['schema'] ?? null;
                    $schemaArray = [];
                    if (is_array($schemaValue) === true) {
                        $schemaArray = $schemaValue;
                    } elseif ($schemaValue !== null && $schemaValue !== false) {
                        $schemaArray = [$schemaValue];
                    }

                    $query['@self']['schema'] = array_unique(
                        array_merge(
                            $schemaArray,
                            $viewQuery['schemas']
                        )
                    );
                }//end if

                // Apply search terms.
                if (empty($viewQuery['searchTerms']) === false) {
                    $searchTerms = $viewQuery['searchTerms'];
                    if (is_array($viewQuery['searchTerms']) === true) {
                        $searchTerms = implode(' ', $viewQuery['searchTerms']);
                    }

                    // Merge with existing search if present.
                    $query['_search'] = $searchTerms;
                    if (isset($query['_search']) === true && empty($query['_search']) === false) {
                        $query['_search'] .= ' ' . $searchTerms;
                    }
                }//end if

                $this->logger->debug(
                    message: '[SearchQueryHandler] Applied view to query',
                    context: [
                        'viewId'         => $viewId,
                        'registers'      => $viewQuery['registers'] ?? [],
                        'schemas'        => $viewQuery['schemas'] ?? [],
                        'hasSearchTerms' => empty($viewQuery['searchTerms']) === false,
                    ]
                );
            } catch (Exception $e) {
                $this->logger->warning(
                    message: '[SearchQueryHandler] Failed to apply view',
                    context: [
                        'viewId' => $viewId,
                        'error'  => $e->getMessage(),
                    ]
                );
            }//end try
        }//end foreach

        return $query;
    }//end applyViewsToQuery()

    /**
     * Check if SOLR search engine is available
     *
     * @return bool True if SOLR is enabled and available, false otherwise
     */
    public function isSolrAvailable(): bool
    {
        try {
            $solrSettings = $this->settingsService->getSolrSettings();
            return $solrSettings['enabled'] ?? false;
        } catch (Exception $e) {
            return false;
        }
    }//end isSolrAvailable()

    /**
     * Clean and normalize query parameters
     *
     * Converts legacy query parameter formats to the standard format used by ObjectEntityMapper.
     * Handles ordering, operator suffixes (_in, _gt, _lt, etc.), and normalizes parameter names.
     *
     * @param array<string, mixed> $parameters Query parameters to clean.
     *
     * @return array<string, mixed> Cleaned query parameters
     */
    public function cleanQuery(array $parameters): array
    {
        $newParameters = [];

        // 1. Handle ordering.
        if (isset($parameters['ordering']) === true) {
            $ordering  = $parameters['ordering'];
            $direction = 'ASC';
            if (str_starts_with($ordering, '-') === true) {
                $direction = 'DESC';
            }

            $field = ltrim($ordering, '-');
            $newParameters['_order'] = [$field => $direction];
            unset($parameters['ordering']);
        }

        // 2. Normalize keys: replace '__' with '_'.
        $normalized = [];
        foreach ($parameters as $key => $value) {
            $normalized[str_replace('__', '_', $key)] = $value;
        }

        // 3. Process parameters (no nested loops).
        foreach ($normalized as $key => $value) {
            if (preg_match('/^(.*)_(in|gt|lt|gte|lte|isnull)$/', $key, $matches) === 1) {
                // Suppress unused variable warning for $matches[0] (full match).
                unset($matches[0]);
                [$base, $suffix] = array_values($matches);

                switch ($suffix) {
                    case 'in':
                    case 'gt':
                    case 'lt':
                    case 'gte':
                    case 'lte':
                        $newParameters[$base][$suffix] = $value;
                        break;

                    case 'isnull':
                        $newParameters[$base] = 'IS NOT NULL';
                        if ($value === true) {
                            $newParameters[$base] = 'IS NULL';
                        }
                        break;
                }//end switch

                continue;
            }//end if

            $newParameters[$key] = $value;
        }//end foreach

        return $newParameters;
    }//end cleanQuery()

    /**
     * Add pagination URLs to search results
     *
     * Generates next and previous page URLs based on current page and total pages.
     * Only adds URLs when pagination is needed (pages > 1).
     *
     * @param array<string, mixed> $paginatedResults Search results array (passed by reference).
     * @param int                  $page             Current page number.
     * @param int                  $pages            Total number of pages.
     *
     * @return void
     */
    public function addPaginationUrls(array &$paginatedResults, int $page, int $pages): void
    {
        // **PERFORMANCE OPTIMIZATION**: Only generate URLs if pagination is needed.
        if ($pages <= 1) {
            return;
        }

        $currentUrl = $_SERVER['REQUEST_URI'];

        // Add next page link if there are more pages.
        if ($page < $pages) {
            $nextPage = ($page + 1);
            $nextUrl  = preg_replace('/([?&])page=\d+/', '$1page=' . $nextPage, $currentUrl);
            if (strpos($nextUrl, 'page=') === false) {
                $nextUrl .= $this->getUrlSeparator($nextUrl) . 'page=' . $nextPage;
            }

            $paginatedResults['next'] = $nextUrl;
        }

        // Add previous page link if not on first page.
        if ($page > 1) {
            $prevPage = ($page - 1);
            $prevUrl  = preg_replace('/([?&])page=\d+/', '$1page=' . $prevPage, $currentUrl);
            if (strpos($prevUrl, 'page=') === false) {
                $prevUrl .= $this->getUrlSeparator($prevUrl) . 'page=' . $prevPage;
            }

            $paginatedResults['prev'] = $prevUrl;
        }
    }//end addPaginationUrls()

    /**
     * Get URL separator character (? or &)
     *
     * Determines whether to use '?' or '&' when adding query parameters to a URL.
     *
     * @param string $url URL to check.
     *
     * @return string '?' if URL has no query string, '&' otherwise
     *
     * @psalm-return '&'|'?'
     */
    private function getUrlSeparator(string $url): string
    {
        if (strpos($url, '?') === false) {
            return '?';
        }

        return '&';
    }//end getUrlSeparator()

    /**
     * Log search trail entry
     *
     * Creates a search trail entry if search trails are enabled in settings.
     * Logs query, result counts, and execution time for analytics and debugging.
     *
     * @param array<string, mixed> $query         Search query array.
     * @param int                  $resultCount   Number of results returned.
     * @param int                  $totalResults  Total number of matching results.
     * @param float                $executionTime Execution time in milliseconds.
     * @param string               $executionType Type of execution (sync, async, optimized, etc.).
     *
     * @return void
     */
    public function logSearchTrail(
        array $query,
        int $resultCount,
        int $totalResults,
        float $executionTime,
        string $executionType = 'sync'
    ): void {
        try {
            // Only create search trail if search trails are enabled.
            if ($this->isSearchTrailsEnabled() === true) {
                // Create the search trail entry using the service with actual execution time.
                // TODO
                // $this->searchTrailService->createSearchTrail(
                // Query: $query,
                // ResultCount: $resultCount,
                // TotalResults: $totalResults,
                // ResponseTime: $executionTime,
                // ExecutionType: $executionType.
                // );.
            }
        } catch (Exception $e) {
            // Log the error but don't fail the request.
        }
    }//end logSearchTrail()

    /**
     * Check if search trails are enabled in the settings
     *
     * @return bool True if search trails are enabled, false otherwise
     */
    public function isSearchTrailsEnabled(): bool
    {
        try {
            $retentionSettings = $this->settingsService->getRetentionSettingsOnly();
            return $retentionSettings['searchTrailsEnabled'] ?? true;
        } catch (Exception $e) {
            // If we can't get settings, default to enabled for safety.
            $this->logger->warning(
                message: 'Failed to check search trails setting, defaulting to enabled',
                context: ['error' => $e->getMessage()]
            );
            return true;
        }
    }//end isSearchTrailsEnabled()
}//end class
