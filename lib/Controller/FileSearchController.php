<?php

/**
 * FileSearchController
 *
 * Controller for file search operations.
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\GuzzleSolrService;
use OCA\OpenRegister\Service\VectorEmbeddingService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * FileSearchController
 *
 * Controller for file search operations (keyword, semantic, hybrid).
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 */
class FileSearchController extends Controller
{


    /**
     * Constructor
     *
     * @param string                 $appName           App name
     * @param IRequest               $request           Request object
     * @param GuzzleSolrService      $guzzleSolrService SOLR service
     * @param VectorEmbeddingService $vectorService     Vector service
     * @param SettingsService        $settingsService   Settings service
     * @param LoggerInterface        $logger            Logger
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly GuzzleSolrService $guzzleSolrService,
        private readonly VectorEmbeddingService $vectorService,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()


    /**
     * Keyword search in file contents (SOLR full-text search)
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Search results
     */
    public function keywordSearch(): JSONResponse
    {
        try {
            $query     = $this->request->getParam('query', '');
            $limit     = (int) $this->request->getParam('limit', 10);
            $offset    = (int) $this->request->getParam('offset', 0);
            $fileTypes = $this->request->getParam('file_types', []);

            if (empty($query) === true) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'message' => 'Query parameter is required',
                        ],
                        statusCode: 400
                        );
            }

            // Get file collection.
            $settings       = $this->settingsService->getSettings();
            $fileCollection = $settings['solr']['fileCollection'] ?? null;
            if ($fileCollection === null || $fileCollection === '') {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'message' => 'File collection not configured',
                        ],
                        statusCode: 422
                        );
            }

            // Build SOLR query.
            $solrQuery = [
                'q'     => "text_content:($query)",
                'rows'  => $limit,
                'start' => $offset,
                'fl'    => 'file_id,file_name,file_path,mime_type,chunk_index,chunk_text,score',
                'sort'  => 'score desc',
            ];

            // Add file type filter if specified.
            if (empty($fileTypes) === false) {
                $typeFilter      = implode(' OR ', array_map(fn($t) => "mime_type:\"$t\"", $fileTypes));
                $solrQuery['fq'] = $typeFilter;
            }

            // Execute SOLR search.
            $queryUrl   = $this->guzzleSolrService->buildSolrBaseUrl()."/{$fileCollection}/select";
            $solrConfig = $this->settingsService->getSettings()['solr'] ?? [];

            $requestOptions = [
                'query'   => $solrQuery,
                'timeout' => $solrConfig['timeout'] ?? 30,
            ];

            // Add authentication.
            if (empty($solrConfig['username']) === false && empty($solrConfig['password']) === false) {
                $requestOptions['auth'] = [$solrConfig['username'], $solrConfig['password']];
            }

            // @psalm-suppress UndefinedClass
            // @var \OCP\Http\Client\IClientService $clientService
            $clientService = \OC::$server->get(\OCP\Http\Client\IClientService::class);
            $httpClient    = $clientService->newClient();
            $response      = $httpClient->get(uri: $queryUrl, options: $requestOptions);
            $result        = json_decode($response->getBody()->getContents(), true);

            $results  = $result['response']['docs'] ?? [];
            $numFound = $result['response']['numFound'] ?? 0;

            // Group results by file_id.
            $groupedResults = [];
            foreach ($results as $doc) {
                $fileId = $doc['file_id'];
                if (isset($groupedResults[$fileId]) === false) {
                    $groupedResults[$fileId] = [
                        'file_id'   => $fileId,
                        'file_name' => $doc['file_name'] ?? '',
                        'file_path' => $doc['file_path'] ?? '',
                        'mime_type' => $doc['mime_type'] ?? '',
                        'score'     => $doc['score'] ?? 0,
                        'chunks'    => [],
                    ];
                }

                $groupedResults[$fileId]['chunks'][] = [
                    'chunk_index' => $doc['chunk_index'] ?? 0,
                    'text'        => $doc['chunk_text'] ?? '',
                    'score'       => $doc['score'] ?? 0,
                ];
            }

            return new JSONResponse(
                    data: [
                        'success'     => true,
                        'query'       => $query,
                        'total'       => $numFound,
                        'results'     => array_values($groupedResults),
                        'search_type' => 'keyword',
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    message: '[FileSearchController] Keyword search failed',
                    context: [
                        'error' => $e->getMessage(),
                    ]
                    );

            return new JSONResponse(
                    data: [
                        'success' => false,
            'message' => 'Search failed: '.$e->getMessage(),
                    ],
                    statusCode: 500
                    );
        }//end try

    }//end keywordSearch()


    /**
     * Semantic search in file contents (vector similarity search)
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Search results
     *
     * @psalm-return JSONResponse<
     *     200|400|500,
     *     array{
     *         success: bool,
     *         message?: string,
     *         query?: string,
     *         total?: int<0, max>,
     *         results?: array<int, array<string, mixed>>,
     *         search_type?: 'semantic'
     *     },
     *     array<never, never>
     * >
     */
    public function semanticSearch(): JSONResponse
    {
        try {
            $query = $this->request->getParam('query', '');
            $limit = (int) $this->request->getParam('limit', 10);

            if (empty($query) === true) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'message' => 'Query parameter is required',
                        ],
                        statusCode: 400
                    );
            }

            // Use existing semanticSearch method from VectorEmbeddingService.
            $results = $this->vectorService->semanticSearch(
                query: $query,
                limit: $limit,
                filters: ['entityType' => 'file']
            );

            return new JSONResponse(
                    data: [
                        'success'     => true,
            'query'       => $query,
                        'total'       => count($results),
                        'results'     => $results,
                        'search_type' => 'semantic',
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    message: '[FileSearchController] Semantic search failed',
                    context: [
                        'error' => $e->getMessage(),
                    ]
                    );

            return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'Semantic search failed: '.$e->getMessage(),
                    ],
                    statusCode: 500
                );
        }//end try

    }//end semanticSearch()


    /**
     * Hybrid search - Combines keyword (SOLR) and semantic (vector) search
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Search results
     *
     * @psalm-return JSONResponse<
     *     200|400|500,
     *     array{
     *         success: bool,
     *         message?: string,
     *         query?: string,
     *         total?: int<0, max>,
     *         results?: array,
     *         search_type?: 'hybrid',
     *         weights?: array{keyword: float, semantic: float}
     *     },
     *     array<never, never>
     * >
     */
    public function hybridSearch(): JSONResponse
    {
        try {
            $query          = $this->request->getParam('query', '');
            $limit          = (int) $this->request->getParam('limit', 10);
            $keywordWeight  = (float) $this->request->getParam('keyword_weight', 0.5);
            $semanticWeight = (float) $this->request->getParam('semantic_weight', 0.5);

            if (empty($query) === true) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'message' => 'Query parameter is required',
                        ],
                        statusCode: 400
                        );
            }

            // Use existing hybridSearch method from VectorEmbeddingService.
            $results = $this->vectorService->hybridSearch(
                query: $query,
                solrFilters: ['entityType' => 'file'],
                limit: $limit,
                weights: ['solr' => $keywordWeight, 'vector' => $semanticWeight]
            );

            return new JSONResponse(
                    data: [
                        'success'     => true,
                        'query'       => $query,
                        'total'       => count($results),
                        'results'     => $results,
                        'search_type' => 'hybrid',
                        'weights'     => [
                            'keyword'  => $keywordWeight,
                            'semantic' => $semanticWeight,
                        ],
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    message: '[FileSearchController] Hybrid search failed',
                    context: [
                        'error' => $e->getMessage(),
                    ]
                    );

            return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'Hybrid search failed: '.$e->getMessage(),
                    ],
                    statusCode: 500
                );
        }//end try

    }//end hybridSearch()


}//end class
