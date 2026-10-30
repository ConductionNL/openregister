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

		$settings = $this->searchSettings(agent: $agent, ragSettings: $ragSettings);
		$viewFilters = $this->resolveViewFilters(agent: $agent, selectedViews: $selectedViews);

		try {
			$results = $this->searchResults(query: $query, settings: $settings);
			$settings['rawResultsCount'] = count($results);

			// An agent with views reads only the objects inside them.
			if ($viewFilters !== null) {
				$results = $this->limitObjectsToViews(results: $results, views: $viewFilters);
			}

			$context = $this->buildContext(results: $results, settings: $settings);
			$this->logRetrievedContext(context: $context, settings: $settings);

			return [
				'text' => $context['text'],
				'sources' => $context['sources'],
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
	 * Read the search settings from the agent or the defaults, with the RAG settings as overrides.
	 *
	 * @param Agent|null $agent       Agent configuration.
	 * @param array      $ragSettings RAG configuration overrides.
	 *
	 * @return array{searchMode: string, includeFiles: mixed, includeObjects: mixed, numSourcesFiles: mixed, numSourcesObjects: mixed}
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function searchSettings(?Agent $agent, array $ragSettings): array {
		$numSources = $agent?->getRagNumSources() ?? 5;
		return [
			'searchMode' => $agent?->getRagSearchMode() ?? 'hybrid',
			'includeFiles' => $ragSettings['includeFiles'] ?? ($agent?->getSearchFiles() ?? true),
			'includeObjects' => $ragSettings['includeObjects'] ?? ($agent?->getSearchObjects() ?? true),
			'numSourcesFiles' => $ragSettings['numSourcesFiles'] ?? $numSources,
			'numSourcesObjects' => $ragSettings['numSourcesObjects'] ?? $numSources,
		];
	}//end searchSettings()

	/**
	 * Decide which views bound the search.
	 *
	 * Null means no view bounds this search; an empty list means a view bounds
	 * it and none is left (the chat picked only views the agent is not granted).
	 *
	 * @param Agent|null $agent         Agent configuration.
	 * @param array      $selectedViews The views the chat selected.
	 *
	 * @return array|null The view ids, or null when no view bounds the search.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function resolveViewFilters(?Agent $agent, array $selectedViews): ?array {
		$agentViews = $agent?->getViews();
		if (empty($agentViews) === false && empty($selectedViews) === false) {
			$viewFilters = array_values(array_intersect($agentViews, $selectedViews));
			$this->logViews(
				message: '[ContextRetrievalHandler] Using filtered views',
				counts: [
					'agentViews' => count($agentViews),
					'selectedViews' => count($selectedViews),
					'filteredViews' => count($viewFilters),
				]
			);
			return $viewFilters;
		}

		if (empty($agentViews) === false) {
			$this->logViews(message: '[ContextRetrievalHandler] Using all agent views', counts: ['views' => count($agentViews)]);
			return $agentViews;
		}

		if (empty($selectedViews) === false) {
			// User selected views but agent has no views configured - use selected ones.
			$this->logViews(
				message: '[ContextRetrievalHandler] Using user-selected views (agent has none)',
				counts: ['views' => count($selectedViews)]
			);
			return $selectedViews;
		}

		return null;
	}//end resolveViewFilters()

	/**
	 * Log which views bound the search.
	 *
	 * @param string             $message The log message.
	 * @param array<string, int> $counts  The view counts to log.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function logViews(string $message, array $counts): void {
		$this->logger->info(
			message: $message,
			context: ['file' => __FILE__, 'line' => __LINE__] + $counts
		);
	}//end logViews()

	/**
	 * Run the search the settings ask for, fetching twice the sources needed so filtering leaves enough.
	 *
	 * Keyword search always runs; semantic search replaces its results and
	 * hybrid search fuses them with vector search.
	 *
	 * @param string $query    User query text.
	 * @param array  $settings The search settings.
	 *
	 * @return array The search results.
	 *
	 * @throws Exception When a search fails.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function searchResults(string $query, array $settings): array {
		$fetchLimit = max($settings['numSourcesFiles'], $settings['numSourcesObjects']) * 2;
		$results = $this->searchKeywordOnly(query: $query, _limit: $fetchLimit);

		if ($settings['searchMode'] === 'semantic') {
			$results = $this->vectorService->semanticSearch(
				query: $query,
				limit: $fetchLimit,
				filters: $this->entityTypeFilter(settings: $settings)
			);
		} elseif ($settings['searchMode'] === 'hybrid') {
			$hybridResponse = $this->vectorService->hybridSearch(
				query: $query,
				keywordResults: $results,
				limit: $fetchLimit
			);
			$results = $hybridResponse['results'] ?? [];
		}

		if (is_array($results) === false) {
			$this->logger->warning(
				message: '[ContextRetrievalHandler] Search returned non-array result',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'searchMode' => $settings['searchMode'],
					'resultType' => gettype($results),
					'resultValue' => $results,
				]
			);
			$results = [];
		}

		return $results;
	}//end searchResults()

	/**
	 * The vector filter on entity type: set only when exactly one of objects and files is searched.
	 *
	 * @param array $settings The search settings.
	 *
	 * @return array The vector search filters.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function entityTypeFilter(array $settings): array {
		$entityTypes = [];
		if ($settings['includeObjects'] === true) {
			$entityTypes[] = 'object';
		}

		if ($settings['includeFiles'] === true) {
			$entityTypes[] = 'file';
		}

		if (count($entityTypes) === 1) {
			return ['entity_type' => $entityTypes];
		}

		return [];
	}//end entityTypeFilter()

	/**
	 * Turn the search results into sources and context text, up to the limit per source type.
	 *
	 * @param array $results  The search results.
	 * @param array $settings The search settings.
	 *
	 * @return array{text: string, sources: list<array<string, mixed>>, fileSources: int, objectSources: int}
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function buildContext(array $results, array $settings): array {
		$context = ['text' => '', 'sources' => [], 'fileSources' => 0, 'objectSources' => 0, 'settings' => $settings];
		foreach ($results as $result) {
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

			if ($this->acceptsResult(result: $result, context: $context) === false) {
				continue;
			}

			$source = $this->buildSource(result: $result);
			$context['sources'][] = $source;
			if (($result['entity_type'] ?? '') === 'file') {
				$context['fileSources']++;
			} elseif (($result['entity_type'] ?? '') === 'object') {
				$context['objectSources']++;
			}

			$context['text'] .= "Source: {$source['name']}\n";
			$context['text'] .= "{$source['text']}\n\n";
			if ($this->hasAllSources(context: $context) === true) {
				break;
			}
		}//end foreach

		unset($context['settings']);
		return $context;
	}//end buildContext()

	/**
	 * Whether a result's type is searched and its type still has room under its limit.
	 *
	 * @param array $result  The search result.
	 * @param array $context The context so far, with the settings and the counts per type.
	 *
	 * @return bool True when the result becomes a source.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function acceptsResult(array $result, array $context): bool {
		$settings = $context['settings'];
		$type = ($result['entity_type'] ?? '');
		if ($type === 'file') {
			return $settings['includeFiles'] !== false && ($context['fileSources'] >= $settings['numSourcesFiles']) === false;
		}

		if ($type === 'object') {
			return $settings['includeObjects'] !== false && ($context['objectSources'] >= $settings['numSourcesObjects']) === false;
		}

		return true;
	}//end acceptsResult()

	/**
	 * Whether every searched source type has reached its limit.
	 *
	 * @param array $context The context so far, with the settings and the counts per type.
	 *
	 * @return bool True when no more sources are needed.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function hasAllSources(array $context): bool {
		$settings = $context['settings'];
		$filesDone = $settings['includeFiles'] === false || $context['fileSources'] >= $settings['numSourcesFiles'];
		$objectsDone = $settings['includeObjects'] === false || $context['objectSources'] >= $settings['numSourcesObjects'];
		return $filesDone === true && $objectsDone === true;
	}//end hasAllSources()

	/**
	 * Build one source from a search result, with the metadata of its type.
	 *
	 * @param array $result The search result.
	 *
	 * @return array<string, mixed> The source.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function buildSource(array $result): array {
		$source = [
			'id' => $result['entity_id'] ?? null,
			'type' => $result['entity_type'] ?? 'unknown',
			'name' => $this->extractSourceName(result: $result),
			'similarity' => $result['similarity'] ?? $result['score'] ?? 1.0,
			'text' => $result['chunk_text'] ?? $result['text'] ?? '',
		];

		$metadata = $result['metadata'] ?? [];
		if (is_string($metadata) === true) {
			$metadata = json_decode($metadata, true) ?? [];
		}

		if ($source['type'] === 'object') {
			$source['uuid'] = $metadata['uuid'] ?? null;
			$source['register'] = $metadata['register_id'] ?? $metadata['register'] ?? null;
			$source['schema'] = $metadata['schema_id'] ?? $metadata['schema'] ?? null;
			$source['uri'] = $metadata['uri'] ?? null;
		}

		if ($source['type'] === 'file') {
			$source['file_id'] = $metadata['file_id'] ?? $source['id'];
			$source['file_path'] = $metadata['file_path'] ?? null;
			$source['mime_type'] = $metadata['mime_type'] ?? null;
		}

		return $source;
	}//end buildSource()

	/**
	 * Log what the retrieval found, and the first source.
	 *
	 * @param array $context  The built context with the counts per type.
	 * @param array $settings The search settings with the raw result count.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function logRetrievedContext(array $context, array $settings): void {
		$this->logger->info(
			message: '[ContextRetrievalHandler] Context retrieved',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'numSources' => count($context['sources']),
				'fileSources' => $context['fileSources'],
				'objectSources' => $context['objectSources'],
				'contextLength' => strlen($context['text']),
				'searchMode' => $settings['searchMode'],
				'includeObjects' => $settings['includeObjects'],
				'includeFiles' => $settings['includeFiles'],
				'numSourcesFiles' => $settings['numSourcesFiles'],
				'numSourcesObjects' => $settings['numSourcesObjects'],
				'rawResultsCount' => $settings['rawResultsCount'],
			]
		);

		if (empty($context['sources']) === false) {
			$this->logger->info(
				message: '[ContextRetrievalHandler] First source details',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'source' => $context['sources'][0],
				]
			);
		}
	}//end logRetrievedContext()

	/**
	 * Drop every object result that lies outside the given views.
	 *
	 * Membership is asked of the view-scoped object search, with RBAC on and
	 * the view as a required bound, so a view the user cannot read or that
	 * cannot be resolved withholds the objects instead of widening the search.
	 * File results are not objects of a view and pass unchanged.
	 *
	 * @param array    $results The search results (entity_type, entity_id, metadata).
	 * @param string[] $views   The view ids that bound the search; empty means none is left.
	 *
	 * @return array The results without the objects outside the views.
	 *
	 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-an-agent-reads-only-the-views-it-is-granted
	 */
	private function limitObjectsToViews(array $results, array $views): array {
		$uuids = [];
		foreach ($results as $result) {
			$uuid = $this->resultObjectUuid(result: $result);
			if ($uuid !== null) {
				$uuids[] = $uuid;
			}
		}

		$allowed = $this->uuidsInsideViews(uuids: array_values(array_unique($uuids)), views: $views);

		$kept = [];
		foreach ($results as $result) {
			$uuid = $this->resultObjectUuid(result: $result);
			if ($uuid === null || in_array($uuid, $allowed, true) === true) {
				$kept[] = $result;
			}
		}

		return $kept;
	}//end limitObjectsToViews()

	/**
	 * The uuids among the given ones that lie inside the views.
	 *
	 * Asked of the view-scoped object search with RBAC on and the view as a
	 * required bound; a failing search withholds every object (fail closed).
	 *
	 * @param string[] $uuids The object uuids of the hits.
	 * @param string[] $views The view ids that bound the search; empty means none is left.
	 *
	 * @return string[] The uuids inside the views.
	 *
	 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-an-agent-reads-only-the-views-it-is-granted
	 */
	private function uuidsInsideViews(array $uuids, array $views): array {
		if ($uuids === [] || $views === []) {
			return [];
		}

		$allowed = [];
		try {
			$found = $this->objectService->searchObjects(
				query: ['_limit' => count($uuids)],
				ids: $uuids,
				views: array_values($views),
				_viewScopeRequired: true
			);
			if (is_array($found) === false) {
				$found = [];
			}

			foreach ($found as $object) {
				$allowed[] = $object->getUuid();
			}
		} catch (Exception $e) {
			$this->logger->warning(
				message: '[ContextRetrievalHandler] Agent views could not be applied; objects withheld',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
		}

		return $allowed;
	}//end uuidsInsideViews()

	/**
	 * The object uuid of a search result, or null when the result is not an object.
	 *
	 * @param mixed $result One search result.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-an-agent-reads-only-the-views-it-is-granted
	 */
	private function resultObjectUuid(mixed $result): ?string {
		if (is_array($result) === false || ($result['entity_type'] ?? '') !== 'object') {
			return null;
		}

		$metadata = ($result['metadata'] ?? []);
		if (is_string($metadata) === true) {
			$metadata = (json_decode($metadata, true) ?? []);
		}

		$uuid = ($metadata['uuid'] ?? $result['entity_id'] ?? null);
		if ($uuid === null) {
			return '';
		}

		return (string) $uuid;
	}//end resultObjectUuid()

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
			// Vector hits carry metadata already decoded; only a string is JSON.
			$metadata = $result['metadata'];
			if (is_string($metadata) === true) {
				$metadata = json_decode($metadata, true);
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
