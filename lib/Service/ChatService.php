<?php

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Service\VectorEmbeddingService;
use OCA\OpenRegister\Service\GuzzleSolrService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use LLPhant\Chat\OpenAIChat;
use LLPhant\OpenAIConfig;

/**
 * ChatService
 *
 * Service for managing AI chat conversations with RAG (Retrieval Augmented Generation)
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://opensource.org/licenses/EUPL-1.2
 * @link     https://www.conduction.nl
 */
class ChatService
{
	/**
	 * @var IDBConnection Database connection
	 */
	private IDBConnection $db;

	/**
	 * @var VectorEmbeddingService Vector embedding service
	 */
	private VectorEmbeddingService $vectorService;

	/**
	 * @var GuzzleSolrService SOLR service
	 */
	private GuzzleSolrService $solrService;

	/**
	 * @var SettingsService Settings service
	 */
	private SettingsService $settingsService;

	/**
	 * @var LoggerInterface Logger
	 */
	private LoggerInterface $logger;

	/**
	 * Constructor
	 *
	 * @param IDBConnection          $db              Database connection
	 * @param VectorEmbeddingService $vectorService   Vector embedding service
	 * @param GuzzleSolrService      $solrService     SOLR service
	 * @param SettingsService        $settingsService Settings service
	 * @param LoggerInterface        $logger          Logger
	 */
	public function __construct(
		IDBConnection $db,
		VectorEmbeddingService $vectorService,
		GuzzleSolrService $solrService,
		SettingsService $settingsService,
		LoggerInterface $logger
	) {
		$this->db = $db;
		$this->vectorService = $vectorService;
		$this->solrService = $solrService;
		$this->settingsService = $settingsService;
		$this->logger = $logger;
	}

	/**
	 * Process a chat message and generate a response using RAG
	 *
	 * @param string $userId         User ID
	 * @param string $message        User message
	 * @param string $searchMode     Search mode: 'hybrid', 'semantic', or 'keyword'
	 * @param int    $numSources     Number of sources to retrieve for context
	 * @param bool   $includeFiles   Whether to search in files
	 * @param bool   $includeObjects Whether to search in objects
	 *
	 * @return array Response data with 'response', 'sources', 'conversationId'
	 * @throws \Exception If chat processing fails
	 */
	public function processMessage(
		string $userId,
		string $message,
		string $searchMode = 'hybrid',
		int $numSources = 5,
		bool $includeFiles = true,
		bool $includeObjects = true
	): array {
		$this->logger->info('[ChatService] Processing message', [
			'userId' => $userId,
			'message' => substr($message, 0, 100),
			'searchMode' => $searchMode,
		]);

		try {
			// Step 1: Retrieve relevant context using RAG
			$context = $this->retrieveContext(
				$message,
				$searchMode,
				$numSources,
				$includeFiles,
				$includeObjects
			);

			// Step 2: Generate response using LLM with context
			$response = $this->generateResponse($message, $context);

			// Step 3: Store conversation history
			$conversationId = $this->storeMessage($userId, $message, $response, $context);

			return [
				'response' => $response,
				'sources' => $context['sources'],
				'conversationId' => $conversationId,
				'searchMode' => $searchMode,
			];
		} catch (\Exception $e) {
			$this->logger->error('[ChatService] Failed to process message', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			]);
			throw $e;
		}
	}

	/**
	 * Retrieve relevant context for a user query using RAG
	 *
	 * @param string $query          User query
	 * @param string $searchMode     Search mode: 'hybrid', 'semantic', or 'keyword'
	 * @param int    $numSources     Number of sources to retrieve
	 * @param bool   $includeFiles   Whether to search in files
	 * @param bool   $includeObjects Whether to search in objects
	 *
	 * @return array Context data with 'text', 'sources'
	 */
	private function retrieveContext(
		string $query,
		string $searchMode,
		int $numSources,
		bool $includeFiles,
		bool $includeObjects
	): array {
		$this->logger->info('[ChatService] Retrieving context', [
			'query' => substr($query, 0, 100),
			'searchMode' => $searchMode,
			'numSources' => $numSources,
		]);

		$sources = [];
		$contextText = '';

		try {
			// Determine which search method to use
			if ($searchMode === 'semantic') {
				// Use semantic search only
				$results = $this->vectorService->semanticSearch(
					$query,
					$numSources * 2, // Get more results to filter by type
					0.7 // Similarity threshold
				);
			} elseif ($searchMode === 'hybrid') {
				// Use hybrid search (combines keyword and semantic)
				$results = $this->vectorService->hybridSearch(
					$query,
					$numSources * 2
				);
			} else {
				// Use keyword search only (SOLR)
				$results = $this->searchKeywordOnly($query, $numSources * 2);
			}

			// Filter results by type (file vs object) and build context
			foreach ($results as $result) {
				// Check if we should include this result type
				$isFile = ($result['entity_type'] ?? '') === 'file';
				$isObject = ($result['entity_type'] ?? '') === 'object';

				if (($isFile && !$includeFiles) || ($isObject && !$includeObjects)) {
					continue;
				}

				// Stop if we have enough sources
				if (count($sources) >= $numSources) {
					break;
				}

				// Extract relevant information
				$source = [
					'id' => $result['entity_id'] ?? null,
					'type' => $result['entity_type'] ?? 'unknown',
					'name' => $this->extractSourceName($result),
					'similarity' => $result['similarity'] ?? $result['score'] ?? 1.0,
					'text' => $result['chunk_text'] ?? $result['text'] ?? '',
				];

				$sources[] = $source;

				// Add to context text
				$contextText .= "Source: {$source['name']}\n";
				$contextText .= "{$source['text']}\n\n";
			}

			$this->logger->info('[ChatService] Context retrieved', [
				'numSources' => count($sources),
				'contextLength' => strlen($contextText),
			]);

			return [
				'text' => $contextText,
				'sources' => $sources,
			];
		} catch (\Exception $e) {
			$this->logger->error('[ChatService] Failed to retrieve context', [
				'error' => $e->getMessage(),
			]);
			// Return empty context on error
			return [
				'text' => '',
				'sources' => [],
			];
		}
	}

	/**
	 * Search using keyword only (SOLR)
	 *
	 * @param string $query User query
	 * @param int    $limit Number of results
	 *
	 * @return array Search results
	 */
	private function searchKeywordOnly(string $query, int $limit): array {
		// Use SOLR search for keyword-based retrieval
		$results = $this->solrService->searchObjectsPaginated(
			$query,
			0, // offset
			$limit,
			[], // filters
			'score desc', // sort
			null, // fields
			null, // facets
			true, // published only
			false // include deleted
		);

		// Transform SOLR results to match expected format
		$transformed = [];
		foreach ($results['results'] ?? [] as $result) {
			$transformed[] = [
				'entity_id' => $result['id'] ?? null,
				'entity_type' => 'object',
				'text' => $result['_source']['data'] ?? json_encode($result),
				'score' => $result['_score'] ?? 1.0,
			];
		}

		return $transformed;
	}

	/**
	 * Extract a human-readable name from a search result
	 *
	 * @param array $result Search result
	 *
	 * @return string Source name
	 */
	private function extractSourceName(array $result): string {
		// Try various fields to get a meaningful name
		if (!empty($result['title'])) {
			return $result['title'];
		}
		if (!empty($result['name'])) {
			return $result['name'];
		}
		if (!empty($result['filename'])) {
			return $result['filename'];
		}
		if (!empty($result['entity_id'])) {
			return ($result['entity_type'] ?? 'Item') . ' #' . $result['entity_id'];
		}
		return 'Unknown Source';
	}

	/**
	 * Generate a response using LLM with provided context
	 *
	 * @param string $userMessage User message
	 * @param array  $context     Context data with 'text' and 'sources'
	 *
	 * @return string Generated response
	 * @throws \Exception If LLM configuration is missing or generation fails
	 */
	private function generateResponse(string $userMessage, array $context): string {
		$this->logger->info('[ChatService] Generating response', [
			'messageLength' => strlen($userMessage),
			'contextLength' => strlen($context['text']),
			'numSources' => count($context['sources']),
		]);

		// Get LLM configuration
		$settings = $this->settingsService->getSettings();
		$llmConfig = $settings['llm'] ?? [];

		// Check if OpenAI is configured
		if (empty($llmConfig['chat_provider']) || $llmConfig['chat_provider'] !== 'openai') {
			throw new \Exception('OpenAI chat provider is not configured. Please configure it in Settings > AI Configuration.');
		}

		if (empty($llmConfig['openai_api_key'])) {
			throw new \Exception('OpenAI API key is not configured. Please add it in Settings > AI Configuration.');
		}

		try {
			// Configure OpenAI
			$config = new OpenAIConfig();
			$config->apiKey = $llmConfig['openai_api_key'];
			$config->model = $llmConfig['chat_model'] ?? 'gpt-4o-mini';

			// Create chat instance
			$chat = new OpenAIChat($config);

			// Build system prompt with context
			$systemPrompt = "You are a helpful AI assistant that helps users find and understand information in their data. ";
			$systemPrompt .= "Use the following context to answer the user's question accurately and concisely.\n\n";

			if (!empty($context['text'])) {
				$systemPrompt .= "CONTEXT:\n" . $context['text'] . "\n\n";
				$systemPrompt .= "If the context doesn't contain relevant information to answer the question, say so honestly. ";
				$systemPrompt .= "Always cite which sources you used when answering.";
			} else {
				$systemPrompt .= "No specific context was found for this query. Provide a helpful general response.";
			}

			// Generate response
			$response = $chat->generateText($systemPrompt . "\n\nUser: " . $userMessage);

			$this->logger->info('[ChatService] Response generated', [
				'responseLength' => strlen($response),
			]);

			return $response;
		} catch (\Exception $e) {
			$this->logger->error('[ChatService] Failed to generate response', [
				'error' => $e->getMessage(),
			]);
			throw new \Exception('Failed to generate response: ' . $e->getMessage());
		}
	}

	/**
	 * Store a message and response in conversation history
	 *
	 * @param string $userId   User ID
	 * @param string $message  User message
	 * @param string $response AI response
	 * @param array  $context  Context data used for response
	 *
	 * @return int Conversation ID
	 */
	private function storeMessage(string $userId, string $message, string $response, array $context): int {
		$qb = $this->db->getQueryBuilder();

		$qb->insert('openregister_chat_history')
			->values([
				'user_id' => $qb->createNamedParameter($userId),
				'user_message' => $qb->createNamedParameter($message),
				'ai_response' => $qb->createNamedParameter($response),
				'context_sources' => $qb->createNamedParameter(json_encode($context['sources'])),
				'created_at' => $qb->createNamedParameter(time()),
			]);

		$qb->execute();

		return (int)$qb->getLastInsertId();
	}

	/**
	 * Get conversation history for a user
	 *
	 * @param string $userId User ID
	 * @param int    $limit  Maximum number of messages to retrieve
	 * @param int    $offset Offset for pagination
	 *
	 * @return array Array of conversation messages
	 */
	public function getConversationHistory(string $userId, int $limit = 50, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from('openregister_chat_history')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('created_at', 'DESC')
			->setMaxResults($limit)
			->setFirstResult($offset);

		$result = $qb->execute();
		$rows = $result->fetchAll();
		$result->closeCursor();

		// Transform to chat format
		$messages = [];
		foreach (array_reverse($rows) as $row) {
			// Add user message
			$messages[] = [
				'role' => 'user',
				'content' => $row['user_message'],
				'timestamp' => date('c', $row['created_at']),
			];

			// Add AI response
			$messages[] = [
				'role' => 'assistant',
				'content' => $row['ai_response'],
				'sources' => json_decode($row['context_sources'], true) ?? [],
				'timestamp' => date('c', $row['created_at']),
			];
		}

		return $messages;
	}

	/**
	 * Clear conversation history for a user
	 *
	 * @param string $userId User ID
	 *
	 * @return int Number of messages deleted
	 */
	public function clearConversationHistory(string $userId): int {
		$qb = $this->db->getQueryBuilder();

		$qb->delete('openregister_chat_history')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		return $qb->execute();
	}

	/**
	 * Store feedback for a message
	 *
	 * @param string $userId    User ID
	 * @param int    $messageId Message ID
	 * @param string $feedback  Feedback type: 'positive' or 'negative'
	 *
	 * @return void
	 */
	public function storeFeedback(string $userId, int $messageId, string $feedback): void {
		$qb = $this->db->getQueryBuilder();

		$qb->update('openregister_chat_history')
			->set('feedback', $qb->createNamedParameter($feedback))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($messageId)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		$qb->execute();

		$this->logger->info('[ChatService] Feedback stored', [
			'messageId' => $messageId,
			'feedback' => $feedback,
		]);
	}
}

