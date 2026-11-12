<?php

/**
 * ChatServiceInterface: the public surface of ChatService, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Every public method of ChatService. Callers depend on this interface, not on the class.
 */
interface ChatServiceInterface {

	/**
	 * Process a chat message and generate AI response
	 *
	 * Main orchestration method that coordinates all handlers.
	 *
	 * @param int $conversationId Conversation ID.
	 * @param string $userId User ID.
	 * @param string $userMessage User message text.
	 * @param array $selectedViews View filters for multitenancy (optional).
	 * @param array $selectedTools Tool UUIDs to use (optional).
	 * @param array $ragSettings RAG configuration overrides (optional).
	 * @param array $context CnAiContext snapshot the frontend sent
	 *                       (orchestrator §8). Persisted on the
	 *                       user-authored Message row when non-empty.
	 * @param ChatStreamChannelInterface|null $channel Streaming channel forwarded to the response
	 *                                         handler so SSE consumers (ChatStreamController)
	 *                                         can interleave `token` / `tool_call` /
	 *                                         `tool_result` frames as the LLM yields. Null
	 *                                         for blocking callers (POST /api/chat/send,
	 *                                         background workers) — behaviour unchanged.
	 *
	 * @return ((array|string)[]|string)[]
	 *
	 * @throws \Exception If processing fails
	 *
	 * @psalm-return array{message: string, messageId: string, sources: list<array>,
	 *     timings: array{context: string, history: string, llm: string,
	 *     total: string}}
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function processMessage(
		int $conversationId,
		string $userId,
		string $userMessage,
		array $selectedViews = [],
		array $selectedTools = [],
		array $ragSettings = [],
		array $context = [],
		?ChatStreamChannelInterface $channel = null,
	): array;

	/**
	 * Ensure conversation title is unique
	 *
	 * Delegates to ConversationManagementHandler.
	 *
	 * @param string $baseTitle Base title.
	 * @param string $userId User ID.
	 * @param int $agentId Agent ID.
	 *
	 * @return string Unique title
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function ensureUniqueTitle(string $baseTitle, string $userId, int $agentId): string;

	/**
	 * Test chat functionality with custom configuration
	 *
	 * NOTE: This is a simplified version. The full testChat implementation
	 * is preserved in ChatService_ORIGINAL_2156.php backup if needed.
	 *
	 * @param string $provider Provider name ('openai', 'fireworks', 'ollama').
	 * @param array $config Provider-specific configuration.
	 * @param string $_testMessage Optional test message to send.
	 *
	 * @return array Test result with success status, message, and optional error.
	 *
	 * @spec exclude Facade plumbing: simplified stub returning a static result; real testing goes through
	 *       ResponseGenerationHandler (chat-ai). No standalone contract.
	 */
	public function testChat(
		string $provider,
		array $config,
		string $_testMessage = 'Hello! Please respond with a brief greeting.',
	): array;
}//end interface
