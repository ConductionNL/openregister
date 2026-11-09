<?php

/**
 * OpenRegister chat stream turn
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

use OCA\OpenRegister\Mcp\Db\Conversation;

/**
 * One streamed chat turn: who asks what, in which conversation.
 *
 * @spec openspec/specs/chat-ai/spec.md
 */
final class ChatStreamTurn {
	/**
	 * The calling user's id.
	 *
	 * @var string
	 */
	public string $userId = '';

	/**
	 * The trimmed user message.
	 *
	 * @var string
	 */
	public string $message = '';

	/**
	 * The agent uuid from the request, or the fallback agent.
	 *
	 * @var string
	 */
	public string $agentUuid = '';

	/**
	 * The conversation uuid from the request, '' for a new conversation.
	 *
	 * @var string
	 */
	public string $conversationUuid = '';

	/**
	 * The context exactly as the client sent it.
	 *
	 * @var mixed
	 */
	public mixed $context = null;

	/**
	 * The resolved conversation.
	 *
	 * @var Conversation|null
	 */
	public ?Conversation $conversation = null;
}//end class
