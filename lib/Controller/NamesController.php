<?php

/**
 * OpenRegister Names Controller
 *
 * Ultra-fast object name lookup endpoints for frontend name resolution.
 * Provides optimized endpoints:
 * - GET /names - Get all object names or specific IDs via query parameter
 * - GET /names/{id} - Get name for specific object ID
 *
 * Utilizes aggressive caching for sub-10ms response times to enable
 * seamless frontend rendering of object names instead of UUIDs.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
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

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\Object\CacheHandler;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for ultra-fast object name lookup operations
 *
 * Provides cached name resolution endpoints optimized for frontend
 * performance and user experience.
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction b.v. <info@conduction.nl>
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link      https://github.com/OpenCatalogi/OpenRegister
 * @version   GIT: <git_id>
 * @copyright 2024 Conduction b.v.
 *
 * @psalm-suppress UnusedClass
 */
class NamesController extends Controller
{
    /**
     * Constructor for NamesController.
     *
     * @param string          $appName            Application name
     * @param IRequest        $request            HTTP request object
     * @param CacheHandler    $objectCacheService Object cache service for name operations
     * @param LoggerInterface $logger             Logger for performance monitoring
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly CacheHandler $objectCacheService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Get all object names or names for specific IDs
     *
     * PERFORMANCE ENDPOINT**: Returns object names with aggressive caching.
     *
     * Query Parameters:**
     * - `ids` (array): Optional. Array of object IDs/UUIDs to get names for
     * - If provided: returns only names for specified IDs
     * - If omitted: returns all object names (triggers cache warmup)
     *
     * Response Format:**
     * ```json
     * {
     * "names": {
     * "uuid-1": "Object Name 1",
     * "uuid-2": "Object Name 2"
     * },
     * "total": 2,
     * "cached": true,
     * "execution_time": "5.23ms"
     * }
     * ```
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @PublicPage
     *
     * @throws \Exception If name lookup fails
     *
     * @return JSONResponse JSON response with object names or error
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    public function index(): JSONResponse
    {
        $startTime = microtime(true);

        try {
            // Check if specific IDs were requested.
            $requestedIds = $this->request->getParam('ids');

            /*
             * Initialize names array before conditional assignment.
             *
             * @var array<string, string> $names
             */

            $names = [];

            // Handle different input formats for IDs.
            if ($requestedIds !== null) {
                // Parse IDs from different possible formats.
                if (is_string($requestedIds) === true) {
                    // Handle comma-separated string or JSON array string.
                    if (str_starts_with($requestedIds, '[') === true) {
                        $requestedIds = json_decode($requestedIds, true) ?? [];
                    }

                    if (is_string($requestedIds) === true) {
                        $requestedIds = array_map('trim', explode(',', $requestedIds));
                    }
                }

                if (is_string($requestedIds) === false && is_array($requestedIds) === false) {
                    $requestedIds = [(string) $requestedIds];
                }

                /*
                 * Get names for specific IDs.
                 *
                 * @var array<string, string> $names
                 */

                $names = $this->objectCacheService->getMultipleObjectNames($requestedIds);

                $this->logger->debug(
                    '📦 BULK NAME LOOKUP REQUEST',
                    [
                        'requested_count' => count($requestedIds),
                        'found_count'     => count($names),
                        'execution_time'  => round((microtime(true) - $startTime) * 1000, 2).'ms',
                    ]
                );
            }//end if

            if ($requestedIds === null) {
                // Get all object names (triggers warmup if needed).
                $names = $this->objectCacheService->getAllObjectNames();

                $this->logger->debug(
                    '📋 ALL NAMES REQUEST',
                    [
                        'total_names'    => count($names),
                        'execution_time' => round((microtime(true) - $startTime) * 1000, 2).'ms',
                    ]
                );
            }

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            return new JSONResponse(
                data: [
                    'names'          => $names,
                    'total'          => count($names),
                    'cached'         => true,
                    'execution_time' => $executionTime.'ms',
                    'cache_stats'    => $this->objectCacheService->getStats(),
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: 'Names endpoint failed',
                context: [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(
                data: [
                    'error'   => 'Failed to retrieve object names',
                    'message' => $e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end index()

    /**
     * Get multiple object names via POST request with JSON body
     *
     * PERFORMANCE ENDPOINT**: Handles large ID arrays that exceed URL length limits.
     * Accepts JSON body with 'ids' array to avoid URL length restrictions with UUIDs.
     *
     * Request Format:**
     * ```json
     * {
     * "ids": ["uuid-1", "uuid-2", "uuid-3"]
     * }
     * ```
     *
     * Response Format:**
     * ```json
     * {
     * "names": {
     * "uuid-1": "Object Name 1",
     * "uuid-2": "Object Name 2"
     * },
     * "total": 2,
     * "requested": 3,
     * "execution_time": "8.45ms"
     * }
     * ```
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @PublicPage
     *
     * @throws \Exception If name lookup fails
     *
     * @return JSONResponse JSON response with object names or error
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    public function create(): JSONResponse
    {
        $startTime = microtime(true);

        try {
            // Get JSON body content.
            $inputData = $this->request->getParams();

            // Support both 'ids' in JSON body and form data.
            $requestedIds = $inputData['ids'] ?? null;

            if ($requestedIds === null || is_array($requestedIds) === false) {
                return new JSONResponse(
                    data: [
                        'error'   => 'Invalid request: ids array is required in request body',
                        'example' => ['ids' => ['uuid-1', 'uuid-2', 'uuid-3']],
                    ],
                    statusCode: 400
                );
            }

            // Filter and validate IDs.
            $requestedIds = array_filter(array_map('trim', $requestedIds));

            if (empty($requestedIds) === true) {
                return new JSONResponse(
                    data: [
                        'error' => 'No valid IDs provided in request',
                    ],
                    statusCode: 400
                );
            }

            $names         = $this->objectCacheService->getMultipleObjectNames($requestedIds);
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger->debug(
                '📦 BULK NAME POST REQUEST',
                [
                    'requested_count' => count($requestedIds),
                    'found_count'     => count($names),
                    'execution_time'  => $executionTime.'ms',
                ]
            );

            return new JSONResponse(
                data: [
                    'names'          => $names,
                    'total'          => count($names),
                    'requested'      => count($requestedIds),
                    'cached'         => true,
                    'execution_time' => $executionTime.'ms',
                    'cache_stats'    => $this->objectCacheService->getStats(),
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: 'POST names endpoint failed',
                context: [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(
                data: [
                    'error'   => 'Failed to retrieve object names',
                    'message' => $e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end create()

    /**
     * Get name for specific object ID
     *
     * ULTRA-FAST ENDPOINT**: Single object name lookup with aggressive caching.
     * Optimized for individual name resolution needs.
     *
     * Response Format:**
     * ```json
     * {
     * "id": "uuid-123",
     * "name": "Object Name",
     * "cached": true,
     * "execution_time": "1.5ms"
     * }
     * ```
     *
     * @param string $id Object ID or UUID to get name for
     *
     * @throws \Exception If name lookup fails
     *
     * @return JSONResponse JSON response with object name or error
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    public function show(string $id): JSONResponse
    {
        $startTime = microtime(true);

        try {
            $name = $this->objectCacheService->getSingleObjectName($id);

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            if ($name === null) {
                $this->logger->debug(
                    '❌ SINGLE NAME NOT FOUND',
                    [
                        'id'             => $id,
                        'execution_time' => $executionTime.'ms',
                    ]
                );

                return new JSONResponse(
                    data: [
                        'id'             => $id,
                        'name'           => null,
                        'found'          => false,
                        'execution_time' => $executionTime.'ms',
                    ],
                    statusCode: 404
                );
            }

            $this->logger->debug(
                message: '🚀 SINGLE NAME LOOKUP',
                context: [
                    'id'             => $id,
                    'name'           => $name,
                    'execution_time' => $executionTime.'ms',
                ]
            );

            return new JSONResponse(
                data: [
                    'id'             => $id,
                    'name'           => $name,
                    'found'          => true,
                    'cached'         => true,
                    'execution_time' => $executionTime.'ms',
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: 'Single name lookup failed',
                context: [
                    'id'    => $id,
                    'error' => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                data: [
                    'id'      => $id,
                    'error'   => 'Failed to retrieve object name',
                    'message' => $e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end show()

    /**
     * Get cache statistics and performance metrics
     *
     * ADMINISTRATIVE ENDPOINT**: Provides cache performance insights
     * for monitoring and optimization.
     *
     * @return JSONResponse A JSON response with cache statistics and performance metrics
     *
     * @psalm-return JSONResponse<200|500,
     *     array{error?: 'Failed to retrieve cache statistics', message?: string,
     *     cache_statistics?: array{hits: int, misses: int, preloads: int,
     *     query_hits: int, query_misses: int, name_hits: int, name_misses: int,
     *     name_warmups: int, hit_rate: float, query_hit_rate: float,
     *     name_hit_rate: float, cache_size: int<0, max>,
     *     query_cache_size: int<0, max>, name_cache_size: int<0, max>},
     *     performance_metrics?: array{name_cache_enabled: true,
     *     distributed_cache_available: true, warmup_available: true}},
     *     array<never, never>>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    public function stats(): JSONResponse
    {
        try {
            $stats = $this->objectCacheService->getStats();

            return new JSONResponse(
                data: [
                    'cache_statistics'    => $stats,
                    'performance_metrics' => [
                        'name_cache_enabled'          => true,
                        'distributed_cache_available' => true,
                        'warmup_available'            => true,
                    ],
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: 'Failed to get cache statistics',
                context: [
                    'error' => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                data: [
                    'error'   => 'Failed to retrieve cache statistics',
                    'message' => $e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end stats()

    /**
     * Warmup name cache manually
     *
     * ADMINISTRATIVE ENDPOINT**: Triggers manual cache warmup
     * for improved performance after system maintenance.
     *
     * @return JSONResponse JSON response with warmup result or error
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[PublicPage]
    public function warmup(): JSONResponse
    {
        $startTime = microtime(true);

        try {
            // Clear existing name cache before warmup
            $this->objectCacheService->clearNameCache();

            $loadedCount   = $this->objectCacheService->warmupNameCache();
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger->info(
                message: 'Manual name cache warmup completed',
                context: [
                    'loaded_names'   => $loadedCount,
                    'execution_time' => $executionTime.'ms',
                ]
            );

            return new JSONResponse(
                data: [
                    'success'        => true,
                    'loaded_names'   => $loadedCount,
                    'execution_time' => $executionTime.'ms',
                    'cache_stats'    => $this->objectCacheService->getStats(),
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                'Manual cache warmup failed',
                [
                    'error' => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                data: [
                    'success' => false,
                    'error'   => 'Cache warmup failed',
                    'message' => $e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end warmup()
}//end class
