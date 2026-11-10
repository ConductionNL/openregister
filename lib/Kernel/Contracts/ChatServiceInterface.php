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

use OCA\OpenRegister\Mcp\Service\Chat\ChatProbe;
use OCA\OpenRegister\Mcp\Service\Chat\TitleClaim;
use OCA\OpenRegister\Mcp\Service\ChatMessageRequest;

/**
 * Every public method of ChatService. Callers depend on this interface, not on the class.
 */
interface ChatServiceInterface {

	/**
	 * Process a chat message and generate AI response
	 *
	 * Main orchestration method that coordinates all handlers.
	 *
	 * @param ChatMessageRequest $request The message, its conversation and user, and the options that
	 *                                    shape the answer (views, tools, RAG settings, CnAiContext and
	 *                                    the streaming channel, null for blocking callers).
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
	public function processMessage(ChatMessageRequest $request): array;

	/**
	 * Ensure conversation title is unique
	 *
	 * Delegates to ConversationManagementHandler.
	 *
	 * @param TitleClaim $claim The wanted title and the user-agent pair it must be unique for.
	 *
	 * @return string Unique title
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function ensureUniqueTitle(TitleClaim $claim): string;

	/**
	 * Test chat functionality with custom configuration
	 *
	 * NOTE: This is a simplified version. The full testChat implementation
	 * is preserved in ChatService_ORIGINAL_2156.php backup if needed.
	 *
	 * @param ChatProbe $probe The provider, its configuration and the test message.
	 *
	 * @return array Test result with success status, message, and optional error.
	 *
	 * @spec exclude Facade plumbing: simplified stub returning a static result; real testing goes through
	 *       ResponseGenerationHandler (chat-ai). No standalone contract.
	 */
	public function testChat(ChatProbe $probe): array;
}//end interface
