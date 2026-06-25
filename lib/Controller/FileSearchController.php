<?php

/**
 * FileSearchController
 *
 * Controller for file search operations.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-87
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\VectorizationService;
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
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 *
 * @psalm-suppress UnusedClass
 */
class FileSearchController extends Controller
{
    /**
     * Constructor
     *
     * @param string               $appName       App name
     * @param IRequest             $request       Request object
     * @param VectorizationService $vectorService Vectorization service
     * @param LoggerInterface      $logger        Logger
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly VectorizationService $vectorService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Semantic search in file contents (vector similarity search)
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with search results or error
     *
     * @psalm-return JSONResponse<200|400|500,
     *     array{success: bool, message?: string, query?: string,
     *     total?: int<0, max>, results?: array<int, array<string, mixed>>,
     *     search_type?: 'semantic'},
     *     array<never, never>>
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-2
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

            // Use existing semanticSearch method from VectorizationService.
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
                    'file'  => __FILE__,
                    'line'  => __LINE__,
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
     * @return JSONResponse JSON response with hybrid search results or error
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-2
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

            // Use existing hybridSearch method from VectorizationService.
            $results = $this->vectorService->hybridSearch(
                query: $query,
                keywordResults: [],
                limit: $limit,
                weights: ['keyword' => $keywordWeight, 'vector' => $semanticWeight]
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
                    'file'  => __FILE__,
                    'line'  => __LINE__,
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
