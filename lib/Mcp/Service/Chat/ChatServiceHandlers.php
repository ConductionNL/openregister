<?php

/**
 * The handlers the chat service delegates a chat turn to.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Mcp\Service\Chat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/chat-ai/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp\Service\Chat;

/**
 * A dependency bundle of {@see \OCA\OpenRegister\Mcp\Service\ChatService}.
 *
 * @spec openspec/specs/chat-ai/spec.md
 */
final class ChatServiceHandlers {
	/**
	 * Bundle the services.
	 *
	 * @param ContextRetrievalHandler       $contextHandler      Retrieves RAG context.
	 * @param ResponseGenerationHandler     $responseHandler     Generates the reply.
	 * @param ConversationManagementHandler $conversationHandler Titles and summarises conversations.
	 * @param MessageHistoryHandler         $historyHandler      Builds and stores message history.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function __construct(
		public readonly ContextRetrievalHandler $contextHandler,
		public readonly ResponseGenerationHandler $responseHandler,
		public readonly ConversationManagementHandler $conversationHandler,
		public readonly MessageHistoryHandler $historyHandler,
	) {
	}//end __construct()
}//end class
