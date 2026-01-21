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

use OC\Files\AppData\Factory;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\NotFoundException;
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
     * @param Factory         $appDataFactory  App data factory.
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly SettingsService $settingsService,
        private readonly IndexService $indexService,
        private readonly LoggerInterface $logger,
        private readonly Factory $appDataFactory,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Get comprehensive cache statistics and performance metrics.
     *
     * This method provides detailed insights into cache usage, performance, memory consumption,
     * hit/miss rates, and object name cache statistics for admin monitoring.
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with cache statistics or error
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
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with clear cache result
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
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with warmup result or error
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
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|422, array{success: bool, message: mixed|string, collection: string},
     *     array<never, never>>
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
            }

            return new JSONResponse(
                data: [
                    'success'    => false,
                    'message'    => $result['message'] ?? 'Failed to clear collection',
                    'collection' => $name,
                ],
                statusCode: 422
            );
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

    /**
     * Invalidate the Nextcloud app store cache.
     *
     * This forces Nextcloud to fetch fresh app data from apps.nextcloud.com
     * on the next request to Settings > Apps. Instead of deleting the cache
     * files (which can cause permission issues), this method sets the cached
     * timestamp to 0, making the cache appear expired.
     *
     * The cache files that can be invalidated:
     * - apps.json: Main app catalog (default)
     * - categories.json: App categories
     * - discover.json: Featured/discover section
     * - all: All app store cache files
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with invalidation result
     */
    public function clearAppStoreCache(): JSONResponse
    {
        try {
            $data = $this->request->getParams();
            $type = $data['type'] ?? 'apps';

            $appData          = $this->appDataFactory->get('appstore');
            $folder           = $appData->getFolder('/');
            $invalidatedFiles = [];
            $errors           = [];

            // Define which files to invalidate based on type.
            $filesToInvalidate = match ($type) {
                'apps'       => ['apps.json'],
                'categories' => ['categories.json'],
                'discover'   => ['discover.json'],
                'all'        => ['apps.json', 'categories.json', 'discover.json'],
                default      => ['apps.json'],
            };

            foreach ($filesToInvalidate as $fileName) {
                try {
                    $file    = $folder->getFile($fileName);
                    $content = $file->getContent();
                    $json    = json_decode($content, true);

                    if (is_array($json) === true && isset($json['timestamp']) === true) {
                        // Set timestamp to 0 to force cache expiration.
                        // The Fetcher checks: timestamp > (now - TTL)
                        // With timestamp=0, this will always be false, triggering a refresh.
                        $json['timestamp'] = 0;
                        $file->putContent(json_encode($json));
                        $invalidatedFiles[] = $fileName;
                    } else {
                        $errors[] = $fileName.' (invalid format)';
                    }
                } catch (NotFoundException $e) {
                    // File doesn't exist, nothing to invalidate.
                    $errors[] = $fileName.' (not found)';
                }
            }

            return new JSONResponse(
                data: [
                    'success'     => true,
                    'message'     => 'App store cache invalidated successfully',
                    'invalidated' => $invalidatedFiles,
                    'skipped'     => $errors,
                    'note'        => 'Fresh data will be fetched on the next visit to Settings > Apps',
                ],
                statusCode: 200
            );
        } catch (NotFoundException $e) {
            // The appstore folder doesn't exist yet (no cache).
            return new JSONResponse(
                data: [
                    'success'     => true,
                    'message'     => 'No app store cache exists yet',
                    'invalidated' => [],
                    'skipped'     => [],
                ],
                statusCode: 200
            );
        } catch (Exception $e) {
            $this->logger->error('Failed to invalidate app store cache: '.$e->getMessage(), [
                'exception' => $e,
            ]);

            return new JSONResponse(
                data: [
                    'success' => false,
                    'error'   => 'Failed to invalidate app store cache: '.$e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end clearAppStoreCache()
}//end class
