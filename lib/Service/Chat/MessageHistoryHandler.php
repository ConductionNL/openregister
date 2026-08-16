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

namespace OCA\OpenRegister\Service\Chat;

use DateTime;
use LLPhant\Chat\Message as LLPhantMessage;
use OCA\OpenRegister\Db\ConversationMapper;
use OCA\OpenRegister\Db\Message;
use OCA\OpenRegister\Db\MessageMapper;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

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
	 * @var MessageMapper
	 */
	private MessageMapper $messageMapper;

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
	 * @param MessageMapper $messageMapper Message mapper.
	 * @param ConversationMapper $conversationMapper Conversation mapper.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function __construct(
		MessageMapper $messageMapper,
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
	 * @SuppressWarnings(PHPMD.StaticAccess)         LLPhantMessage factory methods are standard LLPhant pattern
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Message role handling requires multiple conditional branches
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
			if (empty($role) === false && empty($content) === false) {
				// Use static factory methods based on role.
				if ($role === 'user') {
					$history[] = LLPhantMessage::user($content);
				} elseif ($role === 'assistant') {
					$history[] = LLPhantMessage::assistant($content);
				} elseif ($role === 'system') {
					$history[] = LLPhantMessage::system($content);
				}

				if ($role !== 'user' && $role !== 'assistant' && $role !== 'system') {
					$this->logger->warning(
						message: '[MessageHistoryHandler] Unknown message role',
						context: [
							'file' => __FILE__,
							'line' => __LINE__,
							'role' => $role,
						]
					);
				}
			}//end if

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
			}//end if
		}//end foreach

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
	 * Store a message in the database
	 *
	 * Persists a chat message with optional RAG sources metadata.
	 *
	 * @param int $conversationId Conversation ID.
	 * @param string $role Message role (user or assistant).
	 * @param string $content Message content.
	 * @param array|null $sources Optional RAG sources.
	 * @param array|null $context Optional CnAiContext snapshot the user
	 *                            sent with the message (orchestrator §8).
	 *
	 * @return Message Stored message entity
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function storeMessage(
		int $conversationId,
		string $role,
		string $content,
		?array $sources = null,
		?array $context = null,
	): Message {
		$message = new Message();
		$message->setUuid(Uuid::v4()->toRfc4122());
		$message->setConversationId($conversationId);
		$message->setRole($role);
		$message->setContent($content);
		$message->setCreated(new DateTime());

		// Add sources metadata if provided.
		if ($sources !== null && empty($sources) === false) {
			$message->setMetadata(['sources' => $sources]);
		}

		// Persist the CnAiContext snapshot the frontend sent with the
		// message so future replays + the LLM can ground answers in the
		// user's scope at send-time. Only set when non-empty; null/empty
		// leaves the column at its DEFAULT '{}' to avoid noise in the
		// audit trail.
		if ($context !== null && empty($context) === false) {
			$message->setContext($context);
		}

		$this->messageMapper->insert($message);

		$this->logger->debug(
			message: '[MessageHistoryHandler] Message stored',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'messageId' => $message->getId(),
				'conversationId' => $conversationId,
				'role' => $role,
				'hasSources' => $sources !== null && empty($sources) === false,
			]
		);

		return $message;
	}//end storeMessage()
}//end class
