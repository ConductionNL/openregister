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

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Service\Chat\StreamYieldChannel;

/**
 * The input of ChatService::processMessage() as one value.
 *
 * @spec openspec/specs/chat-ai/spec.md
 *
 * @psalm-immutable
 */
final class ChatMessageRequest {

	/**
	 * Constructor.
	 *
	 * @param int                     $conversationId Conversation ID.
	 * @param string                  $userId         User sending the message.
	 * @param string                  $userMessage    Message text.
	 * @param array                   $selectedViews  View filters for retrieval.
	 * @param array                   $selectedTools  Tool UUIDs the model may use.
	 * @param array                   $ragSettings    Retrieval setting overrides.
	 * @param array                   $cnAiContext    CnAiContext snapshot from the frontend.
	 * @param StreamYieldChannel|null $channel        Streaming channel, null for blocking callers.
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function __construct(
		public readonly int $conversationId,
		public readonly string $userId,
		public readonly string $userMessage,
		public readonly array $selectedViews,
		public readonly array $selectedTools,
		public readonly array $ragSettings,
		public readonly array $cnAiContext,
		public readonly ?StreamYieldChannel $channel,
	) {
	}//end __construct()
}//end class
