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
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-full-text-search-across-object-properties
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\ChunkMapper;
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
     * @param ChunkMapper          $chunkMapper   Chunk mapper (ranked keyword arm)
     * @param LoggerInterface      $logger        Logger
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly VectorizationService $vectorService,
        private readonly ChunkMapper $chunkMapper,
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
     * @no-admin-idor-exempt No caller-supplied id: free-text semantic search over the file vector index;
     *   takes a query string, not an object or file id.
     *
     * @return JSONResponse JSON response with search results or error
     *
     * @psalm-return JSONResponse<200|400|500,
     *     array{success: bool, message?: string, query?: string,
     *     total?: int<0, max>, results?: array<int, array<string, mixed>>,
     *     search_type?: 'semantic'},
     *     array<never, never>>
     *
     * @spec openspec/changes/hybrid-document-search/tasks.md#4.1
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

            // File-only scope: `entity_type` (snake_case) is the key
            // VectorSearchHandler::fetchVectors() actually reads — the former
            // `entityType` key was silently ignored (or#277).
            $results = $this->vectorService->semanticSearch(
                query: $query,
                limit: $limit,
                filters: ['entity_type' => 'file']
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
     * Hybrid search - fuses ranked keyword (tsvector/ts_rank) and semantic (vector) results
     *
     * The keyword arm is real (ChunkMapper::searchByKeyword() over file chunks,
     * empty on non-PostgreSQL platforms) and is fused with the vector arm via
     * Reciprocal Rank Fusion. The response is flat `{results, total, ...}`:
     * `results` is the fused result list and `total` its count — not the nested
     * service response with a wrong outer-key count (or#277).
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with hybrid search results or error
     *
     * @spec openspec/changes/hybrid-document-search/tasks.md#4.2
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

            // Real keyword arm: ranked ts_rank results over file chunks,
            // fetched with the same candidate-pool size as the vector leg.
            $keywordResults = $this->chunkMapper->searchByKeyword(
                query: $query,
                limit: $limit * 2,
                filters: ['source_type' => 'file']
            );

            $serviceResponse = $this->vectorService->hybridSearch(
                query: $query,
                keywordResults: $keywordResults,
                limit: $limit,
                weights: ['keyword' => $keywordWeight, 'vector' => $semanticWeight]
            );

            return new JSONResponse(
                data: [
                    'success'          => true,
                    'query'            => $query,
                    'results'          => $serviceResponse['results'] ?? [],
                    'total'            => $serviceResponse['total'] ?? count($serviceResponse['results'] ?? []),
                    'search_time_ms'   => $serviceResponse['search_time_ms'] ?? null,
                    'source_breakdown' => $serviceResponse['source_breakdown'] ?? [],
                    'weights'          => $serviceResponse['weights'] ?? [
                        'keyword' => $keywordWeight,
                        'vector'  => $semanticWeight,
                    ],
                    'search_type'      => 'hybrid',
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
