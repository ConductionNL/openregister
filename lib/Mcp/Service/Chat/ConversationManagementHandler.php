<?php

/**
 * OpenRegister Chat Conversation Management Handler
 *
 * Handler for conversation lifecycle management.
 * Manages conversation titles, summaries, and history management.
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

use DateTime;
use Exception;
use LLPhant\Chat\OllamaChat;
use LLPhant\Chat\OpenAIChat;
use LLPhant\OllamaConfig;
use LLPhant\OpenAIConfig;
use OCA\OpenRegister\Mcp\Db\Conversation;
use OCA\OpenRegister\Mcp\Db\ConversationMapper;
use OCA\OpenRegister\Notifications\Db\Message;
use OCA\OpenRegister\Notifications\Db\MessageMapperInterface;
use OCA\OpenRegister\Service\SettingsServiceInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * ConversationManagementHandler
 *
 * Handles conversation lifecycle including title generation, summarization,
 * and conversation history management.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Chat
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ConversationManagementHandler {
	/**
	 * Maximum tokens before triggering summarization
	 *
	 * @var int
	 */
	private const MAX_TOKENS_BEFORE_SUMMARY = 4000;

	/**
	 * Number of recent messages to keep when summarizing
	 *
	 * @var int
	 */
	private const RECENT_MESSAGES_COUNT = 10;

	/**
	 * Summary refusal (message, code) per provider when its URL or API key is missing
	 *
	 * @var array<string, array{0: string, 1: int}>
	 */
	private const MISSING_SUMMARY_CREDENTIAL = [
		'ollama' => ['Ollama URL not configured', 0],
		'openai' => ['OpenAI API key not configured', 503],
		'fireworks' => ['Fireworks AI API key not configured', 503],
	];

	/**
	 * Constructor
	 *
	 * @param ConversationStores        $stores          Conversation and message rows.
	 * @param SettingsServiceInterface  $settingsService Settings service.
	 * @param ResponseGenerationHandler $responseHandler Response handler for API calls.
	 * @param LoggerInterface           $logger          Logger.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function __construct(
		private readonly ConversationStores $stores,
		private readonly SettingsServiceInterface $settingsService,
		private readonly ResponseGenerationHandler $responseHandler,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Generate a conversation title from the first user message
	 *
	 * Uses configured LLM to generate a descriptive title.
	 * Falls back to extracting first 60 characters if LLM fails.
	 *
	 * @param string $firstMessage First user message.
	 *
	 * @return string Generated title
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function generateConversationTitle(string $firstMessage): string {
		$this->logger->info(
			message: '[ConversationManagementHandler] Generating conversation title',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
			]
		);

		try {
			return $this->generateTitleWithLlm(firstMessage: $firstMessage);
		} catch (Exception $e) {
			$this->logger->warning(
				message: '[ConversationManagementHandler] Failed to generate title, using fallback',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
				]
			);

			return $this->generateFallbackTitle(message: $firstMessage);
		}//end try
	}//end generateConversationTitle()

	/**
	 * Ask the configured LLM for a title, or fall back when none is usable.
	 *
	 * @param string $firstMessage First user message.
	 *
	 * @return string Generated (or fallback) title
	 *
	 * @throws Exception When the LLM call fails
	 */
	private function generateTitleWithLlm(string $firstMessage): string {
		// Get LLM configuration.
		$llmConfig = $this->settingsService->getLLMSettingsOnly();
		$chatProvider = $llmConfig['chatProvider'] ?? null;

		$config = $this->buildTitleConfig(chatProvider: $chatProvider, llmConfig: $llmConfig);
		if ($config === null) {
			return $this->generateFallbackTitle(message: $firstMessage);
		}

		// Generate title.
		$prompt = 'Generate a short, descriptive title (max 60 characters) for a conversation ';
		$prompt .= "that starts with this message:\n\n";
		$prompt .= "\"{$firstMessage}\"\n\n";
		$prompt .= 'Title:';

		if ($chatProvider === 'fireworks') {
			// The OpenAI-compatible call has always run first for Fireworks;
			// its result is replaced by the Fireworks call below.
			$chat = new OpenAIChat($config);
			$chat->generateText($prompt);
		}

		$title = trim($this->runPrompt(target: new PromptTarget(chatProvider: $chatProvider, config: $config), prompt: $prompt), '"\'');

		// Ensure title isn't too long.
		if (strlen($title) > 60) {
			$title = substr($title, 0, 57) . '...';
		}

		return $title;
	}//end generateTitleWithLlm()

	/**
	 * Build the LLM configuration used for title generation.
	 *
	 * @param mixed $chatProvider Configured chat provider.
	 * @param array $llmConfig    LLM settings.
	 *
	 * @return OllamaConfig|OpenAIConfig|null Null when the title must fall back
	 */
	private function buildTitleConfig(mixed $chatProvider, array $llmConfig): OllamaConfig|OpenAIConfig|null {
		// Try to use configured LLM, fallback if not available.
		if (empty($chatProvider) === true || $this->isKnownProvider(chatProvider: $chatProvider) === false) {
			return null;
		}

		$config = $this->buildProviderConfig(chatProvider: $chatProvider, llmConfig: $llmConfig);
		if ($config !== null) {
			// Through modelOptions, NOT a property: OpenAIChat reads
			// $config->modelOptions and nothing in LLPhant reads a
			// temperature property, so the old form was discarded silently.
			$config->modelOptions['temperature'] = 0.7;
		}

		return $config;
	}//end buildTitleConfig()

	/**
	 * Whether the provider is one this handler can configure.
	 *
	 * @param mixed $chatProvider Configured chat provider.
	 *
	 * @return bool
	 */
	private function isKnownProvider(mixed $chatProvider): bool {
		return in_array($chatProvider, ['ollama', 'openai', 'fireworks'], true);
	}//end isKnownProvider()

	/**
	 * Build the provider configuration for a known provider.
	 *
	 * @param string $chatProvider One of ollama, openai or fireworks.
	 * @param array  $llmConfig    LLM settings.
	 *
	 * @return OllamaConfig|OpenAIConfig|null Null when the provider's URL or API key is missing
	 */
	private function buildProviderConfig(string $chatProvider, array $llmConfig): OllamaConfig|OpenAIConfig|null {
		return match ($chatProvider) {
			'ollama' => $this->buildOllamaConfig(llmConfig: $llmConfig),
			'openai' => $this->buildOpenAiConfig(llmConfig: $llmConfig),
			default => $this->buildFireworksConfig(llmConfig: $llmConfig),
		};
	}//end buildProviderConfig()

	/**
	 * Build the native Ollama configuration.
	 *
	 * @param array $llmConfig LLM settings.
	 *
	 * @return OllamaConfig|null Null when no URL is configured
	 */
	private function buildOllamaConfig(array $llmConfig): ?OllamaConfig {
		$ollamaConfig = $llmConfig['ollamaConfig'] ?? [];
		if (empty($ollamaConfig['url']) === true) {
			return null;
		}

		// Use native Ollama configuration.
		$config = new OllamaConfig();
		$config->url = rtrim($ollamaConfig['url'], '/') . '/api/';
		$config->model = $ollamaConfig['chatModel'] ?? 'llama2';

		return $config;
	}//end buildOllamaConfig()

	/**
	 * Build the OpenAI configuration.
	 *
	 * @param array $llmConfig LLM settings.
	 *
	 * @return OpenAIConfig|null Null when no API key is configured
	 */
	private function buildOpenAiConfig(array $llmConfig): ?OpenAIConfig {
		$openaiConfig = $llmConfig['openaiConfig'] ?? [];
		if (empty($openaiConfig['apiKey']) === true) {
			return null;
		}

		$config = new OpenAIConfig();
		$config->apiKey = $openaiConfig['apiKey'];
		$config->model = 'gpt-4o-mini';

		return $config;
	}//end buildOpenAiConfig()

	/**
	 * Build the Fireworks (OpenAI-compatible) configuration.
	 *
	 * @param array $llmConfig LLM settings.
	 *
	 * @return OpenAIConfig|null Null when no API key is configured
	 */
	private function buildFireworksConfig(array $llmConfig): ?OpenAIConfig {
		$fireworksConfig = $llmConfig['fireworksConfig'] ?? [];
		if (empty($fireworksConfig['apiKey']) === true) {
			return null;
		}

		$config = new OpenAIConfig();
		$config->apiKey = $fireworksConfig['apiKey'];
		$config->model = 'accounts/fireworks/models/llama-v3p1-8b-instruct';
		$baseUrl = rtrim($fireworksConfig['baseUrl'] ?? 'https://api.fireworks.ai/inference/v1', '/');
		if (str_ends_with($baseUrl, '/v1') === false) {
			$baseUrl .= '/v1';
		}

		$config->url = $baseUrl;

		return $config;
	}//end buildFireworksConfig()

	/**
	 * Run a prompt against the provider and return the generated text.
	 *
	 * @param PromptTarget $target The configured chat provider and its configuration.
	 * @param string       $prompt Prompt text.
	 *
	 * @return string Generated text
	 *
	 * @throws Exception When the call fails
	 */
	private function runPrompt(PromptTarget $target, string $prompt): string {
		$chatProvider = $target->chatProvider;
		$config = $target->config;
		if ($chatProvider === 'fireworks') {
			// Use ResponseGenerationHandler's Fireworks method via reflection.
			$reflectionClass = new ReflectionClass($this->responseHandler);
			$method = $reflectionClass->getMethod('callFireworksChatAPI');

			/*
			 * @psalm-suppress UndefinedPropertyFetch LLPhant\OpenAIConfig has dynamic properties
			 */

			return $method->invoke(
				$this->responseHandler,
				$config->apiKey,
				$config->model,
				$config->url,
				$prompt
			);
		}

		if ($chatProvider === 'ollama') {
			// Use native Ollama chat.
			$chat = new OllamaChat($config);
			return $chat->generateText($prompt);
		}

		// OpenAI chat.
		$chat = new OpenAIChat($config);
		return $chat->generateText($prompt);
	}//end runPrompt()
	/**
	 * Generate fallback title from message
	 *
	 * Extracts first 60 characters from message as title.
	 *
	 * @param string $message Message text.
	 *
	 * @return string Fallback title
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function generateFallbackTitle(string $message): string {
		// Take first 60 characters.
		$title = substr($message, 0, 60);

		// If we cut off mid-word, go back to last space.
		if (strlen($message) > 60) {
			$lastSpace = strrpos($title, ' ');
			if ($lastSpace !== false && $lastSpace > 30) {
				$title = substr($title, 0, $lastSpace);
			}

			$title .= '...';
		}

		return $title;
	}//end generateFallbackTitle()

	/**
	 * Ensure conversation title is unique for user-agent combination
	 *
	 * If a conversation with the same title already exists for this user and agent,
	 * appends a number (e.g., "Title (2)", "Title (3)") to make it unique.
	 *
	 * @param TitleClaim $claim The wanted title and the user and agent it must be unique for.
	 *
	 * @return string Unique title with number suffix if needed
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function ensureUniqueTitle(TitleClaim $claim): string {
		$baseTitle = $claim->baseTitle;
		$this->logTitleStep(
			message: '[ConversationManagementHandler] Ensuring unique title',
			details: ['baseTitle' => $baseTitle, 'userId' => $claim->userId, 'agentId' => $claim->agentId]
		);

		// Find all existing titles that match this pattern.
		// Using LIKE with % to catch both exact matches and numbered variants.
		$existingTitles = $this->stores->conversationMapper->findTitlesByUserAgent(
			userId: $claim->userId,
			agentId: $claim->agentId,
			titlePattern: $baseTitle . '%'
		);

		// If no matches, or the base title itself is not taken, it is unique.
		if (empty($existingTitles) === true || in_array($baseTitle, $existingTitles, true) === false) {
			return $baseTitle;
		}

		return $this->numberedTitle(baseTitle: $baseTitle, existingTitles: $existingTitles);
	}//end ensureUniqueTitle()

	/**
	 * The base title with the next free "(N)" suffix, logged.
	 *
	 * @param string $baseTitle      Base title.
	 * @param array  $existingTitles Titles that already match the base title.
	 *
	 * @return string The numbered title.
	 */
	private function numberedTitle(string $baseTitle, array $existingTitles): string {
		$maxNumber = $this->highestTitleSuffix(baseTitle: $baseTitle, existingTitles: $existingTitles);
		$uniqueTitle = $baseTitle . ' (' . ($maxNumber + 1) . ')';

		$this->logTitleStep(
			message: '[ConversationManagementHandler] Generated unique title',
			details: ['baseTitle' => $baseTitle, 'uniqueTitle' => $uniqueTitle, 'foundTitles' => count($existingTitles)]
		);

		return $uniqueTitle;
	}//end numberedTitle()

	/**
	 * Log one step of making a title unique.
	 *
	 * @param string $message The log message.
	 * @param array  $details The step's details.
	 *
	 * @return void
	 */
	private function logTitleStep(string $message, array $details): void {
		$this->logger->info(
			message: $message,
			context: array_merge(['file' => __FILE__, 'line' => __LINE__], $details)
		);
	}//end logTitleStep()

	/**
	 * Find the highest "Title (N)" suffix among existing titles (at least 1).
	 *
	 * @param string $baseTitle      Base title.
	 * @param array  $existingTitles Existing titles for the user-agent pair.
	 *
	 * @return int Highest suffix number
	 */
	private function highestTitleSuffix(string $baseTitle, array $existingTitles): int {
		$maxNumber = 1;
		$baseTitleEscaped = preg_quote($baseTitle, '/');

		foreach ($existingTitles as $title) {
			// Match "Title (N)" pattern.
			if (preg_match('/^' . $baseTitleEscaped . ' \((\d+)\)$/', $title, $matches) === 1) {
				$maxNumber = max($maxNumber, (int)$matches[1]);
			}
		}

		return $maxNumber;
	}//end highestTitleSuffix()

	/**
	 * Check if conversation needs summarization and create summary
	 *
	 * Triggers summarization when token count exceeds threshold.
	 *
	 * @param Conversation $conversation Conversation entity.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function checkAndSummarize(Conversation $conversation): void {
		// Get metadata.
		$metadata = $conversation->getMetadata() ?? [];
		$tokenCount = $metadata['token_count'] ?? 0;

		if ($this->needsSummary(metadata: $metadata) === false) {
			return;
		}

		$this->logger->info(
			message: '[ConversationManagementHandler] Triggering conversation summarization',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'conversationId' => $conversation->getId(),
				'tokenCount' => $tokenCount,
			]
		);

		try {
			$this->summarizeConversation(conversation: $conversation, metadata: $metadata);
		} catch (Exception $e) {
			$this->logger->error(
				message: '[ConversationManagementHandler] Failed to summarize conversation',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
				]
			);
		}//end try
	}//end checkAndSummarize()

	/**
	 * Whether the token count is over the threshold and no summary ran in the last hour.
	 *
	 * @param array $metadata Conversation metadata.
	 *
	 * @return bool
	 */
	private function needsSummary(array $metadata): bool {
		$tokenCount = $metadata['token_count'] ?? 0;

		// Check if we need to summarize.
		if ($tokenCount < self::MAX_TOKENS_BEFORE_SUMMARY) {
			return false;
		}

		// Check if we recently summarized.
		$lastSummary = $metadata['last_summary_at'] ?? null;
		if ($lastSummary === null) {
			return true;
		}

		$lastSummaryTime = new DateTime($lastSummary);
		$hoursSinceSummary = (time() - $lastSummaryTime->getTimestamp()) / 3600;

		// Don't summarize more than once per hour.
		return $hoursSinceSummary >= 1;
	}//end needsSummary()

	/**
	 * Summarize all but the most recent messages and store the summary.
	 *
	 * @param Conversation $conversation Conversation entity.
	 * @param array        $metadata     Conversation metadata.
	 *
	 * @return void
	 *
	 * @throws Exception When summary generation or storage fails
	 */
	private function summarizeConversation(Conversation $conversation, array $metadata): void {
		// Get all messages except recent ones.
		$allMessages = $this->stores->messageMapper->findByConversation($conversation->getId());
		$messagesToSummarize = array_slice($allMessages, 0, -self::RECENT_MESSAGES_COUNT);

		if (empty($messagesToSummarize) === true) {
			return;
		}

		// Generate summary.
		$summary = $this->generateSummary(messages: $messagesToSummarize);

		// Update metadata.
		$metadata['summary'] = $summary;
		$metadata['last_summary_at'] = (new DateTime())->format('c');
		$metadata['summarized_messages'] = count($messagesToSummarize);

		$conversation->setMetadata($metadata);
		$conversation->setUpdated(new DateTime());
		$this->stores->conversationMapper->update($conversation);

		$this->logger->info(
			message: '[ConversationManagementHandler] Conversation summarized',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'conversationId' => $conversation->getId(),
				'summaryLength' => strlen($summary),
			]
		);
	}//end summarizeConversation()

	/**
	 * Generate summary of messages
	 *
	 * Uses configured LLM to generate a concise summary of conversation messages.
	 *
	 * @param array $messages Array of Message entities.
	 *
	 * @return string Summary text
	 *
	 * @throws \Exception If summary generation fails
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	private function generateSummary(array $messages): string {
		// Get LLM configuration.
		$llmConfig = $this->settingsService->getLLMSettingsOnly();
		$chatProvider = $llmConfig['chatProvider'] ?? null;

		if (empty($chatProvider) === true) {
			throw new Exception('Chat provider not configured');
		}

		// Build conversation text.
		$conversationText = $this->buildConversationText(messages: $messages);

		$config = $this->buildSummaryConfig(chatProvider: $chatProvider, llmConfig: $llmConfig);

		// Generate summary.
		$prompt = 'Summarize the following conversation concisely. ';
		$prompt .= "Focus on key topics, decisions, and information discussed:\n\n";
		$prompt .= $conversationText;
		$prompt .= "\n\nSummary:";

		return $this->runPrompt(target: new PromptTarget(chatProvider: $chatProvider, config: $config), prompt: $prompt);
	}//end generateSummary()

	/**
	 * Render messages as "Role: content" blocks.
	 *
	 * @param array $messages Array of Message entities.
	 *
	 * @return string Conversation text
	 */
	private function buildConversationText(array $messages): string {
		$conversationText = '';
		foreach ($messages as $message) {
			$role = 'Assistant';
			if ($message->getRole() === Message::ROLE_USER) {
				$role = 'User';
			}

			$conversationText .= "{$role}: {$message->getContent()}\n\n";
		}

		return $conversationText;
	}//end buildConversationText()

	/**
	 * Build the LLM configuration for summarization.
	 *
	 * Unknown providers get a default OpenAIConfig; a known provider without
	 * its URL or API key is refused.
	 *
	 * @param mixed $chatProvider Configured chat provider.
	 * @param array $llmConfig    LLM settings.
	 *
	 * @return OllamaConfig|OpenAIConfig
	 *
	 * @throws Exception When a known provider is missing its URL or API key
	 */
	private function buildSummaryConfig(mixed $chatProvider, array $llmConfig): OllamaConfig|OpenAIConfig {
		if ($this->isKnownProvider(chatProvider: $chatProvider) === false) {
			return new OpenAIConfig();
		}

		$config = $this->buildProviderConfig(chatProvider: $chatProvider, llmConfig: $llmConfig);
		if ($config === null) {
			[$message, $code] = self::MISSING_SUMMARY_CREDENTIAL[$chatProvider];
			throw new Exception($message, $code);
		}

		return $config;
	}//end buildSummaryConfig()
}//end class
