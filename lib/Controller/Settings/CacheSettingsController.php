<?php

/**
 * OpenRegister Cache Settings Controller
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
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\IndexService;
use Psr\Log\LoggerInterface;

/**
 * Controller for cache management.
 *
 * Handles:
 * - Cache statistics
 * - Cache clearing operations
 * - Cache warmup operations
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Settings
 */
class CacheSettingsController extends Controller
{
    /**
     * Constructor.
     *
     * @param string          $appName         The app name.
     * @param IRequest        $request         The request.
     * @param SettingsService $settingsService Settings service.
     * @param IndexService    $indexService    Index service.
     * @param LoggerInterface $logger          Logger.
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly SettingsService $settingsService,
        private readonly IndexService $indexService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * Get comprehensive cache statistics and performance metrics.
     *
     * This method provides detailed insights into cache usage, performance, memory consumption,
     * hit/miss rates, and object name cache statistics for admin monitoring.
     *
     * @return JSONResponse JSON response containing cache statistics data.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|500, array, array<never, never>>
     */
    public function getCacheStats(): JSONResponse
    {
        try {
            $result = $this->settingsService->getCacheStats();
            return new JSONResponse(data: $result);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end getCacheStats()

    /**
     * Clear cache with granular control.
     *
     * This method supports clearing different types of caches: 'all', 'object', 'schema', 'facet', 'distributed', 'names'.
     * It accepts a JSON body with 'type' parameter to specify which cache to clear.
     *
     * @return JSONResponse JSON response containing cache clearing results.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|500, array, array<never, never>>
     */
    public function clearCache(): JSONResponse
    {
        try {
            $data = $this->request->getParams();
            $type = $data['type'] ?? 'all';

            $result = $this->settingsService->clearCache($type);
            return new JSONResponse(data: $result);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }

    }//end clearCache()

    /**
     * Warmup object names cache manually.
     *
     * This method triggers manual cache warmup for object names to improve performance
     * after system maintenance or during off-peak hours.
     *
     * @return JSONResponse JSON response containing warmup operation results.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|422, array, array<never, never>>
     */
    public function warmupNamesCache(): JSONResponse
    {
        try {
            $result = $this->settingsService->warmupNamesCache();
            return new JSONResponse(data: $result);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 422);
        }

    }//end warmupNamesCache()

    /**
     * Clear a specific SOLR collection by name
     *
     * @param string $name The name of the collection to clear
     *
     * @return JSONResponse The clear result
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|422, array{success: bool, message: mixed|string, collection: string}, array<never, never>>
     */
    public function clearSpecificCollection(string $name): JSONResponse
    {
        try {
            $guzzleSolrService = $this->indexService;

            // Clear the specific collection.
            $result = $guzzleSolrService->clearIndex($name);

            if ($result['success'] === true) {
                return new JSONResponse(
                        data: [
                            'success'    => true,
                            'message'    => 'Collection cleared successfully',
                            'collection' => $name,
                        ],
                        statusCode: 200
                        );
            } else {
                return new JSONResponse(
                        data: [
                            'success'    => false,
                            'message'    => $result['message'] ?? 'Failed to clear collection',
                            'collection' => $name,
                        ],
                        statusCode: 422
                        );
            }
        } catch (Exception $e) {
            return new JSONResponse(
                    data: [
                        'success'    => false,
                        'message'    => 'Collection clear failed: '.$e->getMessage(),
                        'collection' => $name,
                    ],
                    statusCode: 422
                    );
        }//end try

    }//end clearSpecificCollection()
}//end class
