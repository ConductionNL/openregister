<?php

/**
 * OpenRegister SOLR Settings Controller
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller\Settings
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Settings;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Exception;
use OCA\OpenRegister\Service\IndexService;
use OCA\OpenRegister\Service\SettingsService;
use Psr\Log\LoggerInterface;

/**
 * Controller for SOLR configuration settings.
 *
 * Handles:
 * - SOLR connection settings (get/update)
 * - SOLR facet configuration
 * - Facet discovery
 * - SOLR info and statistics
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Settings
 */
class SolrSettingsController extends Controller
{


    /**
     * Constructor.
     *
     * @param string          $appName         The app name.
     * @param IRequest        $request         The request.
     * @param SettingsService $settingsService Settings service.
     * @param LoggerInterface $logger          Logger.
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()


    /**
     * Get SOLR settings only
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse SOLR configuration
     *
     * @psalm-return JSONResponse<200|500, array, array<never, never>>
     */
    public function getSolrSettings(): JSONResponse
    {
        try {
            $data = $this->settingsService->getSolrSettingsOnly();
            return new JSONResponse(data: $data);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end getSolrSettings()


    /**
     * Update SOLR settings only
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Updated SOLR configuration
     *
     * @psalm-return JSONResponse<200|500, array, array<never, never>>
     */
    public function updateSolrSettings(): JSONResponse
    {
        try {
            $data   = $this->request->getParams();
            $result = $this->settingsService->updateSolrSettingsOnly($data);
            return new JSONResponse(data: $result);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end updateSolrSettings()


    /**
     * Get Solr information and vector search capabilities
     *
     * Returns information about Solr availability, version, and vector search support.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Solr information
     *
     * @psalm-return JSONResponse<200|500, array{success: bool, error?: string, solr?: array{available: false|mixed, version: '9.x (detection pending)'|'Unknown', vectorSupport: false, collections: array<array{id: mixed, name: mixed, documentCount: 0|mixed, shards: 0|mixed, health: 'unknown'|mixed}>, error: null|string}}, array<never, never>>
     */
    public function getSolrInfo(): JSONResponse
    {
        try {
            $solrAvailable = false;
            $solrVersion   = 'Unknown';
            $vectorSupport = false;
            $collections   = [];
            $errorMessage  = null;

            // Check if Solr service is available.
            try {
                // Get IndexService from container.
                $guzzleSolrService = $this->container->get(IndexService::class);
                $solrAvailable     = $guzzleSolrService->isAvailable();

                if ($solrAvailable === true) {
                    // Try to detect version from Solr admin API.
                    // Note: Dashboard stats not currently used but available via $guzzleSolrService->getDashboardStats()
                    // For now, assume if it's available, it could support vectors.
                    // TODO: Add actual version detection from Solr admin API.
                    $solrVersion   = '9.x (detection pending)';
                    $vectorSupport = false;
                    // Set to false until we implement it.
                    // Get list of collections from Solr.
                    try {
                        $collectionsList = $guzzleSolrService->listCollections();
                        // Transform to format expected by frontend (array of objects with 'name' and 'id').
                        $collections = array_map(
                                function (array $collection): array {
                                    return [
                                        'id'            => $collection['name'],
                                        'name'          => $collection['name'],
                                        'documentCount' => $collection['documentCount'] ?? 0,
                                        'shards'        => $collection['shards'] ?? 0,
                                        'health'        => $collection['health'] ?? 'unknown',
                                    ];
                                },
                                $collectionsList
                                );
                    } catch (Exception $e) {
                        $this->logger->warning(
                                '[SettingsController] Failed to list Solr collections',
                                [
                                    'error' => $e->getMessage(),
                                ]
                                );
                        $collections = [];
                    }//end try
                }//end if
            } catch (Exception $e) {
                $errorMessage = $e->getMessage();
            }//end try

            return new JSONResponse(
                    data: [
                        'success' => true,
                        'solr'    => [
                            'available'     => $solrAvailable,
                            'version'       => $solrVersion,
                            'vectorSupport' => $vectorSupport,
                            'collections'   => $collections,
                            'error'         => $errorMessage,
                        ],
                    ]
                    );
        } catch (Exception $e) {
            $this->logger->error(
                    '[SettingsController] Failed to get Solr info',
                    [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]
                    );

            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => 'Failed to get Solr information: '.$e->getMessage(),
                    ],
                    statusCode: 500
                );
        }//end try

    }//end getSolrInfo()


    /**
     * Get comprehensive SOLR dashboard statistics
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse SOLR dashboard metrics and statistics
     *
     * @psalm-return JSONResponse<200, array<array-key, mixed>, array<never, never>>|JSONResponse<500, array{error: string}, array<never, never>>
     */
    public function getSolrDashboardStats(): JSONResponse
    {
        try {
            // Phase 1: Use IndexService directly for SOLR operations.
            $guzzleSolrService = $this->container->get(IndexService::class);
            $stats = $guzzleSolrService->getDashboardStats();
            return new JSONResponse(data: $stats);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end getSolrDashboardStats()


    /**
     * Get SOLR facet configuration
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse SOLR facet configuration
     *
     * @psalm-return JSONResponse<200|500, array, array<never, never>>
     */
    public function getSolrFacetConfiguration(): JSONResponse
    {
        try {
            $data = $this->settingsService->getSolrFacetConfiguration();
            return new JSONResponse(data: $data);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end getSolrFacetConfiguration()


    /**
     * Update SOLR facet configuration
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Updated SOLR facet configuration
     *
     * @psalm-return JSONResponse<200|500, array, array<never, never>>
     */
    public function updateSolrFacetConfiguration(): JSONResponse
    {
        try {
            $data   = $this->request->getParams();
            $result = $this->settingsService->updateSolrFacetConfiguration($data);
            return new JSONResponse(data: $result);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end updateSolrFacetConfiguration()


    /**
     * Discover available SOLR facets
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Available SOLR facets
     *
     * @psalm-return JSONResponse<200|422, array{success: bool, message: string, facets: array<never, never>|mixed}, array<never, never>>
     */
    public function discoverSolrFacets(): JSONResponse
    {
        try {
            // Get IndexService from container.
            $guzzleSolrService = $this->container->get(IndexService::class);

            // Check if SOLR is available.
            if ($guzzleSolrService->isAvailable() === false) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'message' => 'SOLR is not available or not configured',
                            'facets'  => [],
                        ],
                        statusCode: 422
                        );
            }

            // Get raw SOLR field information for facet configuration.
            $facetableFields = $guzzleSolrService->getRawSolrFieldsForFacetConfiguration();

            return new JSONResponse(
                    data: [
                        'success' => true,
                        'message' => 'Facets discovered successfully',
                        'facets'  => $facetableFields,
                    ]
                    );
        } catch (Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'Failed to discover facets: '.$e->getMessage(),
                        'facets'  => [],
                    ],
                    statusCode: 422
                );
        }//end try

    }//end discoverSolrFacets()


    /**
     * Get SOLR facet configuration with discovery
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Discovered facets merged with current configuration
     *
     * @psalm-return JSONResponse<200|422|500, array{success: bool, message: string, error?: string, facets?: array{'@self'?: array<array>, object_fields?: array<array>}, global_settings?: array{show_count: true, show_empty: false, max_items: 10}|mixed}, array<never, never>>
     */
    public function getSolrFacetConfigWithDiscovery(): JSONResponse
    {
        try {
            // Get IndexService from container.
            $guzzleSolrService = $this->container->get(IndexService::class);

            // Check if SOLR is available.
            if ($guzzleSolrService->isAvailable() === false) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'message' => 'SOLR is not available or not configured',
                            'facets'  => [],
                        ],
                        statusCode: 422
                        );
            }

            // Get discovered facets.
            $discoveredFacets = $guzzleSolrService->getRawSolrFieldsForFacetConfiguration();

            // Get existing configuration.
            $existingConfig = $this->settingsService->getSolrFacetConfiguration();
            $existingFacets = $existingConfig['facets'] ?? [];

            // Merge discovered facets with existing configuration.
            $mergedFacets = [
                '@self'         => [],
                'object_fields' => [],
            ];

            // Process metadata facets.
            if (($discoveredFacets['@self'] ?? null) !== null) {
                $index = 0;
                foreach ($discoveredFacets['@self'] as $key => $facetInfo) {
                    $fieldName           = "self_{$key}";
                    $existingFacetConfig = $existingFacets[$fieldName] ?? [];

                    $mergedFacets['@self'][$key] = array_merge(
                            $facetInfo,
                            [
                                'config' => [
                                    'enabled'     => $existingFacetConfig['enabled'] ?? true,
                                    'title'       => $existingFacetConfig['title'] ?? $facetInfo['displayName'] ?? $key,
                                    'description' => $existingFacetConfig['description'] ?? ($facetInfo['category'] ?? 'metadata')." field: ".($facetInfo['displayName'] ?? $key),
                                    'order'       => $existingFacetConfig['order'] ?? $index,
                                    'maxItems'    => $existingFacetConfig['max_items'] ?? $existingFacetConfig['maxItems'] ?? 10,
                                    'facetType'   => $existingFacetConfig['facet_type'] ?? $existingFacetConfig['facetType'] ?? $facetInfo['suggestedFacetType'] ?? 'terms',
                                    'displayType' => $existingFacetConfig['display_type'] ?? $existingFacetConfig['displayType'] ?? ($facetInfo['suggestedDisplayTypes'][0] ?? 'select'),
                                    'showCount'   => $existingFacetConfig['show_count'] ?? $existingFacetConfig['showCount'] ?? true,
                                ],
                            ]
                            );
                    $index++;
                }//end foreach
            }//end if

            // Process object field facets.
            if (($discoveredFacets['object_fields'] ?? null) !== null) {
                $index = 0;
                foreach ($discoveredFacets['object_fields'] as $key => $facetInfo) {
                    $fieldName           = $key;
                    $existingFacetConfig = $existingFacets[$fieldName] ?? [];

                    $mergedFacets['object_fields'][$key] = array_merge(
                            $facetInfo,
                            [
                                'config' => [
                                    'enabled'     => $existingFacetConfig['enabled'] ?? false,
                                    'title'       => $existingFacetConfig['title'] ?? $facetInfo['displayName'] ?? $key,
                                    'description' => $existingFacetConfig['description'] ?? ($facetInfo['category'] ?? 'object')." field: ".($facetInfo['displayName'] ?? $key),
                                    'order'       => $existingFacetConfig['order'] ?? (100 + $index),
                                    'maxItems'    => $existingFacetConfig['max_items'] ?? $existingFacetConfig['maxItems'] ?? 10,
                                    'facetType'   => $existingFacetConfig['facet_type'] ?? $existingFacetConfig['facetType'] ?? $facetInfo['suggestedFacetType'] ?? 'terms',
                                    'displayType' => $existingFacetConfig['display_type'] ?? $existingFacetConfig['displayType'] ?? ($facetInfo['suggestedDisplayTypes'][0] ?? 'select'),
                                    'showCount'   => $existingFacetConfig['show_count'] ?? $existingFacetConfig['showCount'] ?? true,
                                ],
                            ]
                            );
                    $index++;
                }//end foreach
            }//end if

            return new JSONResponse(
                    data: [
                        'success'         => true,
                        'message'         => 'Facets discovered and configured successfully',
                        'facets'          => $mergedFacets,
                        'global_settings' => $existingConfig['default_settings'] ?? [
                            'show_count' => true,
                            'show_empty' => false,
                            'max_items'  => 10,
                        ],
                    ]
                    );
        } catch (Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'Failed to get facet configuration: '.$e->getMessage(),
                        'error'   => $e->getMessage(),
                    ],
                    statusCode: 500
                );
        }//end try

    }//end getSolrFacetConfigWithDiscovery()


    /**
     * Update SOLR facet configuration with discovery
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Updated facet configuration
     *
     * @psalm-return JSONResponse<200|500, array{success: bool, message: string, error?: string, config?: array}, array<never, never>>
     */
    public function updateSolrFacetConfigWithDiscovery(): JSONResponse
    {
        try {
            $data   = $this->request->getParams();
            $result = $this->settingsService->updateSolrFacetConfiguration($data);

            return new JSONResponse(
                    data: [
                        'success' => true,
                        'message' => 'Facet configuration updated successfully',
                        'config'  => $result,
                    ]
                    );
        } catch (Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'Failed to update facet configuration: '.$e->getMessage(),
                        'error'   => $e->getMessage(),
                    ],
                    statusCode: 500
                );
        }//end try

    }//end updateSolrFacetConfigWithDiscovery()


}//end class
