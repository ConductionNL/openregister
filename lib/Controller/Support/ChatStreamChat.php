<?php

/**
 * OpenRegister chat stream chat
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Db\ConversationMapper;
use OCA\OpenRegister\Service\ChatService;
use Psr\Log\LoggerInterface;

/**
 * Services of the chat stream API.
 *
 * @spec openspec/specs/chat-ai/spec.md
 */
final class ChatStreamChat {
	/**
	 * Bundle the services.
	 *
	 * @param ConversationMapper $conversationMapper Conversation mapper.
	 * @param AgentMapper        $agentMapper        Agent mapper.
	 * @param ChatService        $chatService        Chat service.
	 * @param LoggerInterface    $logger             Logger.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function __construct(
		public readonly ConversationMapper $conversationMapper,
		public readonly AgentMapper $agentMapper,
		public readonly ChatService $chatService,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
