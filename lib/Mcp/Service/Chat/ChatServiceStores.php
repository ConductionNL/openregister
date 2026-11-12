<?php

/**
 * The stores the chat service reads and writes conversations, messages and agents through.
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

use OCA\OpenRegister\Mcp\Db\AgentMapper;
use OCA\OpenRegister\Mcp\Db\ConversationMapper;
use OCA\OpenRegister\Notifications\Db\MessageMapperInterface;

/**
 * A dependency bundle of {@see \OCA\OpenRegister\Mcp\Service\ChatService}.
 *
 * @spec openspec/specs/chat-ai/spec.md
 */
final class ChatServiceStores {
	/**
	 * Bundle the services.
	 *
	 * @param ConversationMapper     $conversationMapper Conversation rows.
	 * @param MessageMapperInterface $messageMapper      Message rows.
	 * @param AgentMapper            $agentMapper        Agent rows.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function __construct(
		public readonly ConversationMapper $conversationMapper,
		public readonly MessageMapperInterface $messageMapper,
		public readonly AgentMapper $agentMapper,
	) {
	}//end __construct()
}//end class
