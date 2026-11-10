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

namespace OCA\OpenRegister\Mcp\Service\Chat;

use Exception;
use OCA\OpenRegister\Mcp\Db\Agent;
use OCA\OpenRegister\Mcp\Service\ChatMessageRequest;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Search\Service\Vectorization\VectorEmbeddingsInterface;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Search\Service\Vectorization\HybridSearchRequest;
use OCA\OpenRegister\Search\Service\Vectorization\SemanticSearchRequest;

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
	 * Settings that the context log reports, in the order it reports them.
	 */
	private const LOGGED_SETTINGS = [
		'searchMode',
		'includeObjects',
		'includeFiles',
		'numSourcesFiles',
		'numSourcesObjects',
		'rawResultsCount',
	];

	/**
	 * Constructor
	 *
	 * @param VectorEmbeddingsInterface $vectorService Vector embeddings service.
	 * @param ObjectOperationsInterface $objectService Object service for database search.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function __construct(
		private readonly VectorEmbeddingsInterface $vectorService,
		private readonly ObjectOperationsInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Retrieve context for RAG chat using semantic/hybrid/keyword search
	 *
	 * This method performs the core context retrieval for Retrieval Augmented Generation.
	 * It searches for relevant documents/objects/files based on the query and agent settings.
	 *
	 * @param ChatMessageRequest $request The turn: the query text, the selected views and the RAG
	 *                                    configuration overrides.
	 * @param Agent|null         $agent   Agent configuration (optional).
	 *
	 * @return array Retrieved context with semantic results, chunks, and metadata.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function retrieveContext(ChatMessageRequest $request, ?Agent $agent): array {
		$query = $request->userMessage;
		$ragSettings = $request->ragSettings;
		$this->info(
			message: '[ContextRetrievalHandler] Retrieving context',
			context: ['query' => substr($query, 0, 100), 'hasAgent' => $agent !== null, 'ragSettings' => $ragSettings]
		);
		$settings = $this->searchSettings(agent: $agent, ragSettings: $ragSettings);
		$settings['viewFilters'] = $this->resolveViewFilters(agent: $agent, selectedViews: $request->selectedViews);

		try {
			return $this->contextFor(query: $query, settings: $settings);
		} catch (Exception $e) {
			return $this->failedContext(error: $e);
		}
	}//end retrieveContext()

	/**
	 * Log a failed retrieval and answer with an empty context.
	 *
	 * @param Exception $error Why the retrieval failed.
	 *
	 * @return array{text: string, sources: array{}} The empty context.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function failedContext(Exception $error): array {
		$this->logger->error(
			message: '[ContextRetrievalHandler] Failed to retrieve context',
			context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $error->getMessage()]
		);
		return ['text' => '', 'sources' => []];
	}//end failedContext()

	/**
	 * Search, keep the objects inside the view filters, and build and log the context.
	 *
	 * @param string $query    User query text.
	 * @param array  $settings The search settings with the view filters (null when no view bounds the search).
	 *
	 * @return array{text: string, sources: list<array<string, mixed>>} The context text and its sources.
	 *
	 * @throws Exception When a search fails.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function contextFor(string $query, array $settings): array {
		$results = $this->searchResults(query: $query, settings: $settings);
		$settings['rawResultsCount'] = count($results);

		// An agent with views reads only the objects inside them.
		if ($settings['viewFilters'] !== null) {
			$results = $this->limitObjectsToViews(results: $results, views: $settings['viewFilters']);
		}

		$context = $this->buildContext(results: $results, settings: $settings);
		$this->logRetrievedContext(context: $context, settings: $settings);

		return ['text' => $context['text'], 'sources' => $context['sources']];
	}//end contextFor()

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
		$this->info(message: $message, context: $counts);
	}//end logViews()

	/**
	 * Log at info level, with the file and line in front of the context.
	 *
	 * @param string               $message The log message.
	 * @param array<string, mixed> $context The log context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function info(string $message, array $context): void {
		$this->logger->info(message: $message, context: ['file' => __FILE__, 'line' => __LINE__] + $context);
	}//end info()

	/**
	 * Log at warning level, with the file and line in front of the context.
	 *
	 * @param string               $message The log message.
	 * @param array<string, mixed> $context The log context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function warning(string $message, array $context): void {
		$this->logger->warning(message: $message, context: ['file' => __FILE__, 'line' => __LINE__] + $context);
	}//end warning()

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
			$filters = $this->entityTypeFilter(settings: $settings);
			$results = $this->vectorService->semanticSearch(request: new SemanticSearchRequest(query: $query, limit: $fetchLimit, filters: $filters));
		} elseif ($settings['searchMode'] === 'hybrid') {
			$request = new HybridSearchRequest(query: $query, keywordResults: $results, limit: $fetchLimit);
			$hybrid  = $this->vectorService->hybridSearch(request: $request);
			$results = $hybrid['results'] ?? [];
		}

		return $this->arrayResults(results: $results, searchMode: $settings['searchMode']);
	}//end searchResults()

	/**
	 * The search results when they are an array; otherwise a logged warning and no results.
	 *
	 * @param mixed  $results    What the search returned.
	 * @param string $searchMode The search mode that returned it.
	 *
	 * @return array The search results.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function arrayResults(mixed $results, string $searchMode): array {
		if (is_array($results) === true) {
			return $results;
		}

		$this->warning(
			message: '[ContextRetrievalHandler] Search returned non-array result',
			context: ['searchMode' => $searchMode, 'resultType' => gettype($results), 'resultValue' => $results]
		);
		return [];
	}//end arrayResults()

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
		$searched = ['object' => $settings['includeObjects'] === true, 'file' => $settings['includeFiles'] === true];
		$entityTypes = array_keys(array_filter($searched));
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
			if ($this->acceptsResult(result: $result, context: $context) === false) {
				continue;
			}
			$context = $this->withSource(context: $context, result: $result);
			if ($this->hasAllSources(context: $context) === true) {
				break;
			}
		}//end foreach

		unset($context['settings']);
		return $context;
	}//end buildContext()

	/**
	 * Whether a result becomes a source: it is an array, and its type is searched and has room.
	 *
	 * A result that is not an array is logged and skipped.
	 *
	 * @param mixed $result  The search result.
	 * @param array $context The context so far, with the settings and the counts per type.
	 *
	 * @return bool True when the result becomes a source.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function acceptsResult(mixed $result, array $context): bool {
		if (is_array($result) === false) {
			$this->warning(
				message: '[ContextRetrievalHandler] Skipping non-array result',
				context: ['resultType' => gettype($result), 'resultValue' => $result]
			);
			return false;
		}

		return $this->typeHasRoom(result: $result, context: $context);
	}//end acceptsResult()

	/**
	 * Whether a result's type is searched and its type still has room under its limit.
	 *
	 * @param array $result  The search result.
	 * @param array $context The context so far, with the settings and the counts per type.
	 *
	 * @return bool True when the result's type takes another source.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function typeHasRoom(array $result, array $context): bool {
		$settings = $context['settings'];
		$type = ($result['entity_type'] ?? '');
		if ($type === 'file') {
			return $settings['includeFiles'] !== false && ($context['fileSources'] >= $settings['numSourcesFiles']) === false;
		}

		if ($type === 'object') {
			return $settings['includeObjects'] !== false && ($context['objectSources'] >= $settings['numSourcesObjects']) === false;
		}

		return true;
	}//end typeHasRoom()

	/**
	 * The context with the result added as a source: in the sources, the count of its type and the text.
	 *
	 * @param array $context The context so far.
	 * @param array $result  The search result.
	 *
	 * @return array The context with the source added.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function withSource(array $context, array $result): array {
		$source = (new RetrievedSource(result: $result))->toArray();
		$context['sources'][] = $source;
		$type = ($result['entity_type'] ?? '');
		if ($type === 'file') {
			$context['fileSources']++;
		} elseif ($type === 'object') {
			$context['objectSources']++;
		}

		$context['text'] .= "Source: {$source['name']}\n";
		$context['text'] .= "{$source['text']}\n\n";
		return $context;
	}//end withSource()

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
		$logged = array_flip(self::LOGGED_SETTINGS);
		$this->info(
			message: '[ContextRetrievalHandler] Context retrieved',
			context: $this->contextCounts(context: $context) + array_merge($logged, array_intersect_key($settings, $logged))
		);

		if (empty($context['sources']) === false) {
			$this->info(message: '[ContextRetrievalHandler] First source details', context: ['source' => $context['sources'][0]]);
		}
	}//end logRetrievedContext()

	/**
	 * The counts the context log reports: sources in all and per type, and the text length.
	 *
	 * @param array $context The built context with the counts per type.
	 *
	 * @return array<string, int> The counts.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function contextCounts(array $context): array {
		return [
			'numSources' => count($context['sources']),
			'fileSources' => $context['fileSources'],
			'objectSources' => $context['objectSources'],
			'contextLength' => strlen($context['text']),
		];
	}//end contextCounts()

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
			$transformed[] = $this->keywordHit(result: $result);
		}

		return $transformed;
	}//end searchKeywordOnly()

	/**
	 * One keyword search result in the shape of a vector hit.
	 *
	 * @param mixed $result One object from the keyword search.
	 *
	 * @return array{entity_id: mixed, entity_type: string, text: mixed, score: mixed}
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function keywordHit(mixed $result): array {
		return [
			'entity_id' => $result['id'] ?? $result['uuid'] ?? null,
			'entity_type' => 'object',
			'text' => $result['_source']['data'] ?? json_encode($result),
			'score' => $result['_score'] ?? 1.0,
		];
	}//end keywordHit()

}//end class
