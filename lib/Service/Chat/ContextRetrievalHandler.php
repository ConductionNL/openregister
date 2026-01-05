<?php

/**
 * OpenRegister Chat Context Retrieval Handler
 *
 * Handler for RAG (Retrieval Augmented Generation) context retrieval.
 * Manages semantic search, keyword search, and source extraction for chat context.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Chat
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Service\Chat;

use Exception;
use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Service\Vectorization\VectorEmbeddings;
use OCA\OpenRegister\Service\IndexService;
use Psr\Log\LoggerInterface;

/**
 * ContextRetrievalHandler
 *
 * Handles context retrieval for RAG chat responses.
 * Supports semantic search, hybrid search, and keyword search modes.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Chat
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */
class ContextRetrievalHandler
{

    /**
     * Vector embeddings service
     *
     * @var VectorEmbeddings
     */
    private VectorEmbeddings $vectorService;

    /**
     * Index service for SOLR search
     *
     * @var IndexService
     */
    private IndexService $solrService;

    /**
     * Logger
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Constructor
     *
     * @param VectorEmbeddings $vectorService Vector embeddings service.
     * @param IndexService     $solrService   SOLR index service.
     * @param LoggerInterface  $logger        Logger.
     *
     * @return void
     */
    public function __construct(
        VectorEmbeddings $vectorService,
        IndexService $solrService,
        LoggerInterface $logger
    ) {
        $this->vectorService = $vectorService;
        $this->solrService   = $solrService;
        $this->logger        = $logger;
    }//end __construct()

    /**
     * Retrieve context for RAG chat using semantic/hybrid/keyword search
     *
     * This method performs the core context retrieval for Retrieval Augmented Generation.
     * It searches for relevant documents/objects/files based on the query and agent settings.
     *
     * @param string     $query         User query text.
     * @param Agent|null $agent         Agent configuration (optional).
     * @param array      $selectedViews View filters for multitenancy (optional).
     * @param array      $ragSettings   RAG configuration overrides (optional).
     *
     * @return ((float|mixed|null|string)[][]|string)[]
     *
     * @psalm-return array{text: string,
     *     sources: list<array{file_id?: mixed|null, file_path?: mixed|null,
     *     id: mixed|null, mime_type?: mixed|null, name: string,
     *     register?: mixed|null, schema?: mixed|null,
     *     similarity: float(1)|mixed, text: ''|mixed,
     *     type: 'unknown'|mixed, uri?: mixed|null, uuid?: mixed|null}>}
     */
    public function retrieveContext(string $query, ?Agent $agent, array $selectedViews=[], array $ragSettings=[]): array
    {
        $this->logger->info(
            message: '[ChatService] Retrieving context',
            context: [
                'query'       => substr($query, 0, 100),
                'hasAgent'    => $agent !== null,
                'ragSettings' => $ragSettings,
            ]
        );

        // Get search settings from agent or use defaults, then apply RAG settings overrides.
        $searchMode        = $agent?->getRagSearchMode() ?? 'hybrid';
        $numSources        = $agent?->getRagNumSources() ?? 5;
        $includeFiles      = $ragSettings['includeFiles'] ?? ($agent?->getSearchFiles() ?? true);
        $includeObjects    = $ragSettings['includeObjects'] ?? ($agent?->getSearchObjects() ?? true);
        $numSourcesFiles   = $ragSettings['numSourcesFiles'] ?? $numSources;
        $numSourcesObjects = $ragSettings['numSourcesObjects'] ?? $numSources;

        // Calculate total sources needed (will be filtered by type later).
        $totalSources = max($numSourcesFiles, $numSourcesObjects);

        // Get view filters if agent has views configured.
        if ($agent !== null && $agent->getViews() !== null && empty($agent->getViews()) === false) {
            $agentViews = $agent->getViews();

            // If selectedViews provided, filter to only those views.
            if (empty($selectedViews) === false) {
                $viewFilters = array_intersect($agentViews, $selectedViews);
                $this->logger->info(
                    message: '[ChatService] Using filtered views',
                    context: [
                        'agentViews'    => count($agentViews),
                        'selectedViews' => count($selectedViews),
                        'filteredViews' => count($viewFilters),
                    ]
                );
            } else {
                // Use all agent views.
                $viewFilters = $agentViews;
                $this->logger->info(
                    message: '[ChatService] Using all agent views',
                    context: [
                        'views' => count($viewFilters),
                    ]
                );
            }
        } else if (empty($selectedViews) === false) {
            // User selected views but agent has no views configured - use selected ones.
            $viewFilters = $selectedViews;
            $this->logger->info(
                message: '[ChatService] Using user-selected views (agent has none)',
                context: [
                    'views' => count($viewFilters),
                ]
            );
        }//end if

        $sources     = [];
        $contextText = '';

        try {
            // Build filters for vector search.
            $vectorFilters = [];

            // Filter by entity types based on agent settings.
            $entityTypes = [];
            if ($includeObjects === true) {
                $entityTypes[] = 'object';
            }

            if ($includeFiles === true) {
                $entityTypes[] = 'file';
            }

            // Only add entity_type filter if we're filtering.
            if (empty($entityTypes) === false && count($entityTypes) < 2) {
                $vectorFilters['entity_type'] = $entityTypes;
            }

            // Determine search method - fetch more results than needed for filtering.
            $fetchLimit = $totalSources * 2;

            if ($searchMode === 'semantic') {
                $results = $this->vectorService->semanticSearch(
                    query: $query,
                    limit: $fetchLimit,
                    filters: $vectorFilters
                    // Pass filters array instead of 0.7.
                );
            } else if ($searchMode === 'hybrid') {
                $hybridResponse = $this->vectorService->hybridSearch(
                    query: $query,
                    solrFilters: ['vector_filters' => $vectorFilters],
                    // Pass filters in SOLR filters array.
                    limit: $fetchLimit
                    // Limit parameter.
                );
                // Extract results array from hybrid search response.
                $results = $hybridResponse['results'] ?? [];
            } else {
                // Keyword search.
                $results = $this->searchKeywordOnly(query: $query, _limit: $fetchLimit);
            }//end if

            // Ensure results is an array.
            if (is_array($results) === false) {
                $this->logger->warning(
                    message: '[ChatService] Search returned non-array result',
                    context: [
                        'searchMode'  => $searchMode,
                        'resultType'  => gettype($results),
                        'resultValue' => $results,
                    ]
                );
                $results = [];
            }

            // Determine raw results count for logging.
            if (is_array($results) === true) {
                $rawResultsCount = count($results);
            } else {
                $rawResultsCount = gettype($results);
            }

            // Filter and build context - track file and object counts separately.
            $fileSourceCount   = 0;
            $objectSourceCount = 0;

            foreach ($results as $result) {
                // Skip if result is not an array.
                if (is_array($result) === false) {
                    $this->logger->warning(
                        message: '[ChatService] Skipping non-array result',
                        context: [
                            'resultType'  => gettype($result),
                            'resultValue' => $result,
                        ]
                    );
                    continue;
                }

                $isFile   = ($result['entity_type'] ?? '') === 'file';
                $isObject = ($result['entity_type'] ?? '') === 'object';

                // Check type filters.
                $skipFile   = $isFile === true && $includeFiles === false;
                $skipObject = $isObject === true && $includeObjects === false;
                if ($skipFile === true || $skipObject === true) {
                    continue;
                }

                // Check if we've reached the limit for this source type.
                if (($isFile === true) === true && ($fileSourceCount >= $numSourcesFiles) === true) {
                    continue;
                }

                if (($isObject === true) === true && ($objectSourceCount >= $numSourcesObjects) === true) {
                    continue;
                }

                // TODO: Apply view filters here when view filtering is implemented.
                // For now, we'll skip view filtering and implement it later.
                // Extract source information.
                $source = [
                    'id'         => $result['entity_id'] ?? null,
                    'type'       => $result['entity_type'] ?? 'unknown',
                    'name'       => $this->extractSourceName($result),
                    'similarity' => $result['similarity'] ?? $result['score'] ?? 1.0,
                    'text'       => $result['chunk_text'] ?? $result['text'] ?? '',
                ];

                // Add type-specific metadata.
                $metadata = $result['metadata'] ?? [];
                if (is_string($metadata) === true) {
                    $metadata = json_decode($metadata, true) ?? [];
                }

                // For objects: add UUID, register, schema.
                if ($source['type'] === 'object') {
                    $source['uuid']     = $metadata['uuid'] ?? null;
                    $source['register'] = $metadata['register_id'] ?? $metadata['register'] ?? null;
                    $source['schema']   = $metadata['schema_id'] ?? $metadata['schema'] ?? null;
                    $source['uri']      = $metadata['uri'] ?? null;
                }

                // For files: add file_id, path.
                if ($source['type'] === 'file') {
                    $source['file_id']   = $metadata['file_id'] ?? $source['id'];
                    $source['file_path'] = $metadata['file_path'] ?? null;
                    $source['mime_type'] = $metadata['mime_type'] ?? null;
                }

                $sources[] = $source;

                // Increment the appropriate counter.
                if ($isFile === true) {
                    $fileSourceCount++;
                } else if ($isObject === true) {
                    $objectSourceCount++;
                }

                // Add to context text.
                $contextText .= "Source: {$source['name']}\n";
                $contextText .= "{$source['text']}\n\n";

                // Stop if we've reached limits for both types.
                if ((($includeFiles === false) === true || $fileSourceCount >= $numSourcesFiles)
                    && (($includeObjects === false) === true || $objectSourceCount >= $numSourcesObjects)
                ) {
                    break;
                }
            }//end foreach

            $this->logger->info(
                message: '[ChatService] Context retrieved',
                context: [
                    'numSources'        => count($sources),
                    'fileSources'       => $fileSourceCount,
                    'objectSources'     => $objectSourceCount,
                    'contextLength'     => strlen($contextText),
                    'searchMode'        => $searchMode,
                    'includeObjects'    => $includeObjects,
                    'includeFiles'      => $includeFiles,
                    'numSourcesFiles'   => $numSourcesFiles,
                    'numSourcesObjects' => $numSourcesObjects,
                    'rawResultsCount'   => $rawResultsCount,
                ]
            );

            // DEBUG: Log first source.
            if (empty($sources) === false) {
                $this->logger->info(
                    message: '[ChatService] First source details',
                    context: [
                        'source' => $sources[0],
                    ]
                );
            }

            return [
                'text'    => $contextText,
                'sources' => $sources,
            ];
        } catch (Exception $e) {
            $this->logger->error(
                message: '[ChatService] Failed to retrieve context',
                context: [
                    'error' => $e->getMessage(),
                ]
            );

            return [
                'text'    => '',
                'sources' => [],
            ];
        }//end try
    }//end retrieveContext()

    /**
     * Search using keyword only (SOLR)
     *
     * Performs keyword-based search using SOLR without vector embeddings.
     *
     * @param string $query  Query text.
     * @param int    $_limit Result limit (unused, for interface compatibility).
     *
     * @return array Search results in standardized format
     *
     * @psalm-return list<array{entity_id: mixed, entity_type: string, text: string, score: float}>
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private function searchKeywordOnly(string $query, int $_limit): array
    {
        $results = $this->solrService->searchObjectsPaginated(
            query: ['_search' => $query],
            limit: $_limit,
            offset: 0,
            facets: [],
            collection: null,
            includeTotal: true
        );

        $transformed = [];
        foreach ($results['results'] ?? [] as $result) {
            $transformed[] = [
                'entity_id'   => $result['id'] ?? null,
                'entity_type' => 'object',
                'text'        => $result['_source']['data'] ?? json_encode($result),
                'score'       => $result['_score'] ?? 1.0,
            ];
        }

        return $transformed;
    }//end searchKeywordOnly()

    /**
     * Extract a human-readable name from search result
     *
     * Attempts to find a display name from various fields in the result.
     * Falls back to entity type and ID if no name is found.
     *
     * @param array $result Search result array.
     *
     * @return string Human-readable source name
     */
    private function extractSourceName(array $result): string
    {
        // First check top-level fields.
        if (empty($result['title']) === false) {
            return $result['title'];
        }

        if (empty($result['name']) === false) {
            return $result['name'];
        }

        if (empty($result['filename']) === false) {
            return $result['filename'];
        }

        // Check metadata for object_title, file_name, etc.
        if (empty($result['metadata']) === false) {
            if (is_array($result['metadata']) === true) {
                $metadata = $result['metadata'];
            } else {
                $metadata = json_decode($result['metadata'], true);
            }

            if (empty($metadata['object_title']) === false) {
                return $metadata['object_title'];
            }

            if (empty($metadata['file_name']) === false) {
                return $metadata['file_name'];
            }

            if (empty($metadata['name']) === false) {
                return $metadata['name'];
            }

            if (empty($metadata['title']) === false) {
                return $metadata['title'];
            }
        }//end if

        // Fallback to entity ID.
        if (empty($result['entity_id']) === false) {
            $type = $result['entity_type'] ?? 'Item';
            // Capitalize first letter for display.
            $type = ucfirst($type);
            return $type.' #'.substr($result['entity_id'], 0, 8);
        }

        // Final fallback.
        return 'Unknown Source';
    }//end extractSourceName()
}//end class
