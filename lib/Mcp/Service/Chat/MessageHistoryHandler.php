<?php

/**
 * OpenRegister Chat Message History Handler
 *
 * Handler for message storage and history management.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Chat
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/chat-ai/spec.md
 */

namespace OCA\OpenRegister\Mcp\Service\Chat;

use DateTime;
use LLPhant\Chat\Message as LLPhantMessage;
use OCA\OpenRegister\Mcp\Db\ConversationMapper;
use OCA\OpenRegister\Notifications\Db\Message;
use OCA\OpenRegister\Notifications\Db\MessageMapperInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use OCA\OpenRegister\Mcp\Service\ChatMessageRequest;

/**
 * MessageHistoryHandler
 *
 * Handles message storage and conversation history building.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Chat
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */
class MessageHistoryHandler {
	/**
	 * Number of recent messages to keep in context
	 *
	 * @var int
	 */
	private const RECENT_MESSAGES_COUNT = 10;

	/**
	 * Message mapper
	 *
	 * @var MessageMapperInterface
	 */
	private MessageMapperInterface $messageMapper;

	/**
	 * Conversation mapper
	 *
	 * @var ConversationMapper
	 */
	private ConversationMapper $conversationMapper;

	/**
	 * Logger
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Constructor
	 *
	 * @param MessageMapperInterface $messageMapper Message mapper.
	 * @param ConversationMapper $conversationMapper Conversation mapper.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function __construct(
		MessageMapperInterface $messageMapper,
		ConversationMapper $conversationMapper,
		LoggerInterface $logger,
	) {
		$this->messageMapper = $messageMapper;
		$this->conversationMapper = $conversationMapper;
		$this->logger = $logger;
	}//end __construct()

	/**
	 * Build message history array for LLM
	 *
	 * Converts recent Message entities to LLPhantMessage format for LLM context.
	 *
	 * @param int $conversationId Conversation ID.
	 *
	 * @return array Array of LLPhantMessage objects
	 *
	 * @psalm-return list<LLPhantMessage>
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function buildMessageHistory(int $conversationId): array {
		// Get recent messages.
		$messages = $this->messageMapper->findRecentByConversation(
			$conversationId,
			self::RECENT_MESSAGES_COUNT
		);

		$this->logger->debug(
			message: '[MessageHistoryHandler] Building message history',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'conversationId' => $conversationId,
				'messageCount' => count($messages),
			]
		);

		$history = [];
		foreach ($messages as $message) {
			$llmMessage = $this->toLlmMessage(message: $message);
			if ($llmMessage !== null) {
				$history[] = $llmMessage;
			}
		}

		$this->logger->info(
			message: '[MessageHistoryHandler] Message history built',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'historyCount' => count($history),
			]
		);

		return $history;
	}//end buildMessageHistory()

	/**
	 * Convert one stored message to an LLPhant message, logging skipped ones.
	 *
	 * @param Message $message Stored message.
	 *
	 * @return LLPhantMessage|null Null when the message is skipped
	 */
	private function toLlmMessage(Message $message): ?LLPhantMessage {
		$content = $message->getContent();
		$role = $message->getRole();

		$this->logger->debug(
			message: '[MessageHistoryHandler] Adding message to history',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'role' => $role,
				'contentLength' => strlen($content ?? ''),
				'hasContent' => empty($content) === false,
				'hasRole' => empty($role) === false,
			]
		);

		// Only add messages that have both role and content.
		if (empty($role) === true || empty($content) === true) {
			$this->logger->warning(
				message: '[MessageHistoryHandler] Skipping message with missing role or content',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'hasRole' => empty($role) === false,
					'hasContent' => empty($content) === false,
				]
			);
			return null;
		}

		return $this->llmMessageFor(role: $role, content: $content);
	}//end toLlmMessage()

	/**
	 * The LLPhant message for a role, or null (logged) for an unsupported role.
	 *
	 * @param string $role    Message role.
	 * @param string $content Message content.
	 *
	 * @return LLPhantMessage|null
	 */
	private function llmMessageFor(string $role, string $content): ?LLPhantMessage {
		if (in_array($role, ['user', 'assistant', 'system'], true) === false) {
			$this->logUnknownRole(role: $role);
			return null;
		}

		// Use static factory methods based on role.
		return match ($role) {
			'user' => LLPhantMessage::user($content),
			'assistant' => LLPhantMessage::assistant($content),
			default => LLPhantMessage::system($content),
		};
	}//end llmMessageFor()

	/**
	 * Log a message role the LLM history does not support.
	 *
	 * @param string $role Message role.
	 *
	 * @return void
	 */
	private function logUnknownRole(string $role): void {
		$this->logger->warning(
			message: '[MessageHistoryHandler] Unknown message role',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'role' => $role,
			]
		);
	}//end logUnknownRole()

	/**
	 * Store the user's message of a turn
	 *
	 * Persists the message with the CnAiContext snapshot the user sent with it
	 * (orchestrator §8), so future replays and the LLM can ground answers in the
	 * user's scope at send-time. An empty snapshot leaves the column at its
	 * DEFAULT '{}' to avoid noise in the audit trail.
	 *
	 * @param ChatMessageRequest $request The turn: conversation, message text and CnAiContext.
	 *
	 * @return Message Stored message entity
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function storeUserMessage(ChatMessageRequest $request): Message {
		$message = $this->draftMessage(role: Message::ROLE_USER, content: $request->userMessage);
		$message->setConversationId($request->conversationId);
		if ($this->hasEntries(value: $request->cnAiContext) === true) {
			$message->setContext($request->cnAiContext);
		}

		return $this->persistMessage(message: $message, sources: null);
	}//end storeUserMessage()

	/**
	 * Store the assistant's reply of a turn
	 *
	 * Persists the reply with its optional RAG sources metadata.
	 *
	 * @param int        $conversationId Conversation ID.
	 * @param string     $content        Reply content.
	 * @param array|null $sources        Optional RAG sources.
	 *
	 * @return Message Stored message entity
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function storeAssistantMessage(int $conversationId, string $content, ?array $sources): Message {
		$message = $this->draftMessage(role: Message::ROLE_ASSISTANT, content: $content);
		$message->setConversationId($conversationId);

		return $this->persistMessage(message: $message, sources: $sources);
	}//end storeAssistantMessage()

	/**
	 * A new message with its uuid, role, content and creation time.
	 *
	 * @param string $role    Message role (user or assistant).
	 * @param string $content Message content.
	 *
	 * @return Message The unsaved message.
	 */
	private function draftMessage(string $role, string $content): Message {
		$message = new Message();
		$message->setUuid(Uuid::v4()->toRfc4122());
		$message->setRole($role);
		$message->setContent($content);
		$message->setCreated(new DateTime());

		return $message;
	}//end draftMessage()

	/**
	 * Attach the RAG sources when there are any, insert the message and log it.
	 *
	 * @param Message    $message The unsaved message.
	 * @param array|null $sources Optional RAG sources.
	 *
	 * @return Message Stored message entity
	 */
	private function persistMessage(Message $message, ?array $sources): Message {
		if ($this->hasEntries(value: $sources) === true) {
			$message->setSources($sources);
		}

		$this->messageMapper->insert($message);

		$this->logger->debug(
			message: '[MessageHistoryHandler] Message stored',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'messageId' => $message->getId(),
				'conversationId' => $message->getConversationId(),
				'role' => $message->getRole(),
				'hasSources' => $this->hasEntries(value: $sources),
			]
		);

		return $message;
	}//end persistMessage()

	/**
	 * Whether an optional array is set and non-empty.
	 *
	 * @param array|null $value Optional array.
	 *
	 * @return bool
	 */
	private function hasEntries(?array $value): bool {
		return $value !== null && empty($value) === false;
	}//end hasEntries()
}//end class
