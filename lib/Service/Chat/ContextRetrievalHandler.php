<?php

/**
 * OpenRegister Chat Context Retrieval Handler
 *
 * Handler for RAG (Retrieval Augmented Generation) context retrieval.
 * Manages semantic search, keyword search, and source extraction for chat context.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 *
 * @spec openspec/specs/chat-ai/spec.md
 */

namespace OCA\OpenRegister\Service\Chat;

use Exception;
use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Vectorization\VectorEmbeddings;
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
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complex RAG context retrieval with multiple search strategies
 */
class ContextRetrievalHandler {

	/**
	 * Vector embeddings service
	 *
	 * @var VectorEmbeddings
	 */
	private VectorEmbeddings $vectorService;

	/**
	 * Object service for database search
	 *
	 * @var ObjectService
	 */
	private ObjectService $objectService;

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
	 * @param ObjectService $objectService Object service for database search.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function __construct(
		VectorEmbeddings $vectorService,
		ObjectService $objectService,
		LoggerInterface $logger,
	) {
		$this->vectorService = $vectorService;
		$this->objectService = $objectService;
		$this->logger = $logger;
	}//end __construct()

	/**
	 * Retrieve context for RAG chat using semantic/hybrid/keyword search
	 *
	 * This method performs the core context retrieval for Retrieval Augmented Generation.
	 * It searches for relevant documents/objects/files based on the query and agent settings.
	 *
	 * @param string $query User query text.
	 * @param Agent|null $agent Agent configuration (optional).
	 * @param array $selectedViews View filters for multitenancy (optional).
	 * @param array $ragSettings RAG configuration overrides (optional).
	 *
	 * @return array Retrieved context with semantic results, chunks, and metadata.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  RAG context retrieval requires many search strategies
	 * @SuppressWarnings(PHPMD.NPathComplexity)       RAG context retrieval requires many search strategies
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Complex RAG logic cannot be easily split
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function retrieveContext(
		string $query,
		?Agent $agent,
		array $selectedViews = [],
		array $ragSettings = [],
	): array {
		$this->logger->info(
			message: '[ContextRetrievalHandler] Retrieving context',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'query' => substr($query, 0, 100),
				'hasAgent' => $agent !== null,
				'ragSettings' => $ragSettings,
			]
		);

		// Get search settings from agent or use defaults, then apply RAG settings overrides.
		$searchMode = $agent?->getRagSearchMode() ?? 'hybrid';
		$numSources = $agent?->getRagNumSources() ?? 5;
		$includeFiles = $ragSettings['includeFiles'] ?? ($agent?->getSearchFiles() ?? true);
		$includeObjects = $ragSettings['includeObjects'] ?? ($agent?->getSearchObjects() ?? true);
		$numSourcesFiles = $ragSettings['numSourcesFiles'] ?? $numSources;
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
					message: '[ContextRetrievalHandler] Using filtered views',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'agentViews' => count($agentViews),
						'selectedViews' => count($selectedViews),
						'filteredViews' => count($viewFilters),
					]
				);
			}

			if (empty($selectedViews) === true) {
				// Use all agent views.
				$viewFilters = $agentViews;
				$this->logger->info(
					message: '[ContextRetrievalHandler] Using all agent views',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'views' => count($viewFilters),
					]
				);
			}
		} elseif (empty($selectedViews) === false) {
			// User selected views but agent has no views configured - use selected ones.
			$viewFilters = $selectedViews;
			$this->logger->info(
				message: '[ContextRetrievalHandler] Using user-selected views (agent has none)',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'views' => count($viewFilters),
				]
			);
		}//end if

		$sources = [];
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

			// Initialize results before conditional assignment.
			$results = [];

			// Keyword search (default).
			$results = $this->searchKeywordOnly(query: $query, _limit: $fetchLimit);

			if ($searchMode === 'semantic') {
				$results = $this->vectorService->semanticSearch(
					query: $query,
					limit: $fetchLimit,
					filters: $vectorFilters
					// Pass filters array instead of 0.7.
				);
			} elseif ($searchMode === 'hybrid') {
				// Fuse the already-fetched keyword results with vector search.
				$hybridResponse = $this->vectorService->hybridSearch(
					query: $query,
					keywordResults: $results,
					limit: $fetchLimit
				);
				// Extract results array from hybrid search response.
				$results = $hybridResponse['results'] ?? [];
			}//end if

			// Ensure results is an array.
			if (is_array($results) === false) {
				$this->logger->warning(
					message: '[ContextRetrievalHandler] Search returned non-array result',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'searchMode' => $searchMode,
						'resultType' => gettype($results),
						'resultValue' => $results,
					]
				);
				$results = [];
			}

			// Determine raw results count for logging. $results is always an
			// array here, so the old gettype() fallback was unreachable.
			$rawResultsCount = count($results);

			// Filter and build context - track file and object counts separately.
			$fileSourceCount = 0;
			$objectSourceCount = 0;

			foreach ($results as $result) {
				// Skip if result is not an array.
				if (is_array($result) === false) {
					$this->logger->warning(
						message: '[ContextRetrievalHandler] Skipping non-array result',
						context: [
							'file' => __FILE__,
							'line' => __LINE__,
							'resultType' => gettype($result),
							'resultValue' => $result,
						]
					);
					continue;
				}

				$isFile = ($result['entity_type'] ?? '') === 'file';
				$isObject = ($result['entity_type'] ?? '') === 'object';

				// Check type filters.
				$skipFile = $isFile === true && $includeFiles === false;
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
					'id' => $result['entity_id'] ?? null,
					'type' => $result['entity_type'] ?? 'unknown',
					'name' => $this->extractSourceName(result: $result),
					'similarity' => $result['similarity'] ?? $result['score'] ?? 1.0,
					'text' => $result['chunk_text'] ?? $result['text'] ?? '',
				];

				// Add type-specific metadata.
				$metadata = $result['metadata'] ?? [];
				if (is_string($metadata) === true) {
					$metadata = json_decode($metadata, true) ?? [];
				}

				// For objects: add UUID, register, schema.
				if ($source['type'] === 'object') {
					$source['uuid'] = $metadata['uuid'] ?? null;
					$source['register'] = $metadata['register_id'] ?? $metadata['register'] ?? null;
					$source['schema'] = $metadata['schema_id'] ?? $metadata['schema'] ?? null;
					$source['uri'] = $metadata['uri'] ?? null;
				}

				// For files: add file_id, path.
				if ($source['type'] === 'file') {
					$source['file_id'] = $metadata['file_id'] ?? $source['id'];
					$source['file_path'] = $metadata['file_path'] ?? null;
					$source['mime_type'] = $metadata['mime_type'] ?? null;
				}

				$sources[] = $source;

				// Increment the appropriate counter.
				if ($isFile === true) {
					$fileSourceCount++;
				} elseif ($isObject === true) {
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
				message: '[ContextRetrievalHandler] Context retrieved',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'numSources' => count($sources),
					'fileSources' => $fileSourceCount,
					'objectSources' => $objectSourceCount,
					'contextLength' => strlen($contextText),
					'searchMode' => $searchMode,
					'includeObjects' => $includeObjects,
					'includeFiles' => $includeFiles,
					'numSourcesFiles' => $numSourcesFiles,
					'numSourcesObjects' => $numSourcesObjects,
					'rawResultsCount' => $rawResultsCount,
				]
			);

			// DEBUG: Log first source.
			if (empty($sources) === false) {
				$this->logger->info(
					message: '[ContextRetrievalHandler] First source details',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'source' => $sources[0],
					]
				);
			}

			return [
				'text' => $contextText,
				'sources' => $sources,
			];
		} catch (Exception $e) {
			$this->logger->error(
				message: '[ContextRetrievalHandler] Failed to retrieve context',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
				]
			);

			return [
				'text' => '',
				'sources' => [],
			];
		}//end try
	}//end retrieveContext()

	/**
	 * Search using keyword only (database)
	 *
	 * Performs keyword-based search using the database without vector embeddings.
	 *
	 * @param string $query Query text.
	 * @param int $_limit Result limit.
	 *
	 * @return array Search results in standardized format
	 *
	 * @psalm-return list<array{entity_id: mixed, entity_type: string, text: string, score: float}>
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function searchKeywordOnly(string $query, int $_limit): array {
		$results = $this->objectService->searchObjectsPaginated(
			query: ['_search' => $query, '_limit' => $_limit]
		);

		$transformed = [];
		foreach ($results['results'] ?? [] as $result) {
			$transformed[] = [
				'entity_id' => $result['id'] ?? $result['uuid'] ?? null,
				'entity_type' => 'object',
				'text' => $result['_source']['data'] ?? json_encode($result),
				'score' => $result['_score'] ?? 1.0,
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
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Name extraction requires checking many possible fields
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Name extraction requires checking many possible fields
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function extractSourceName(array $result): string {
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
			$metadata = json_decode($result['metadata'], true);
			if (is_array($result['metadata']) === true) {
				$metadata = $result['metadata'];
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
			return $type . ' #' . substr($result['entity_id'], 0, 8);
		}

		// Final fallback.
		return 'Unknown Source';
	}//end extractSourceName()
}//end class
