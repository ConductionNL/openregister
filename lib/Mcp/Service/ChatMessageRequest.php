<?php

/**
 * OpenRegister Chat Message Request
 *
 * One user message sent to a conversation, with the options that shape the answer.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author	Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link	  https://www.OpenRegister.app
 *
 * @spec openspec/specs/chat-ai/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp\Service;

use OCA\OpenRegister\Kernel\Contracts\ChatStreamChannelInterface;

/**
 * The input of ChatService::processMessage() as one value: the message, then the options
 * that shape the answer.
 *
 * @spec openspec/specs/chat-ai/spec.md
 */
final class ChatMessageRequest {

	/**
	 * View filters for retrieval.
	 *
	 * @var array
	 */
	public array $selectedViews = [];

	/**
	 * Tool UUIDs the model may use.
	 *
	 * @var array
	 */
	public array $selectedTools = [];

	/**
	 * Retrieval setting overrides.
	 *
	 * @var array
	 */
	public array $ragSettings = [];

	/**
	 * CnAiContext snapshot from the frontend.
	 *
	 * @var array
	 */
	public array $cnAiContext = [];

	/**
	 * Streaming channel, null for blocking callers.
	 *
	 * @var ChatStreamChannelInterface|null
	 */
	public ?ChatStreamChannelInterface $channel = null;

	/**
	 * Constructor.
	 *
	 * @param int    $conversationId Conversation ID.
	 * @param string $userId         User sending the message.
	 * @param string $userMessage    Message text.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function __construct(
		public readonly int $conversationId,
		public readonly string $userId,
		public readonly string $userMessage,
	) {
	}//end __construct()
}//end class
