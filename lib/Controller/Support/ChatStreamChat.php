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

use OCA\OpenRegister\Kernel\Contracts\AgentMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ConversationMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ChatServiceInterface;
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
	 * @param ConversationMapperInterface $conversationMapper Conversation mapper.
	 * @param AgentMapperInterface        $agentMapper        Agent mapper.
	 * @param ChatServiceInterface        $chatService        Chat service.
	 * @param LoggerInterface    $logger             Logger.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function __construct(
		public readonly ConversationMapperInterface $conversationMapper,
		public readonly AgentMapperInterface $agentMapper,
		public readonly ChatServiceInterface $chatService,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
