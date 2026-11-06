<?php

/**
 * OpenRegister chat mappers
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

use OCA\OpenRegister\Mcp\Db\AgentMapper;
use OCA\OpenRegister\Mcp\Db\ConversationMapper;
use OCA\OpenRegister\Db\FeedbackMapper;
use OCA\OpenRegister\Notifications\Db\MessageMapperInterface;

/**
 * The mappers and repositories of the chat API.
 *
 * @spec openspec/specs/chat-ai/spec.md
 */
final class ChatMappers {
	/**
	 * Bundle the services.
	 *
	 * @param ConversationMapper $conversationMapper Conversation mapper for database operations
	 * @param MessageMapperInterface      $messageMapper      Message mapper for database operations
	 * @param FeedbackMapper     $feedbackMapper     Feedback mapper for database operations
	 * @param AgentMapper        $agentMapper        Agent mapper for database operations
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function __construct(
		public readonly ConversationMapper $conversationMapper,
		public readonly MessageMapperInterface $messageMapper,
		public readonly FeedbackMapper $feedbackMapper,
		public readonly AgentMapper $agentMapper,
	) {
	}//end __construct()
}//end class
