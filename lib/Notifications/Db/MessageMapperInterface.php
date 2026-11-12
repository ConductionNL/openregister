<?php

/**
 * MessageMapperInterface: the public surface of MessageMapper, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Notifications
 * @package  OCA\OpenRegister\Notifications\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Db;

use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Mcp\Db\Conversation;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;

/**
 * Every public method of MessageMapper. Callers depend on this interface, not on the class.
 */
interface MessageMapperInterface {

	/**
	 * Find a message by its ID
	 *
	 * Retrieves message entity by ID. Throws exception if message not found.
	 *
	 * @param int $id Message ID to find
	 *
	 * @return Message The found message entity
	 *
	 * @throws DoesNotExistException If message not found
	 * @throws MultipleObjectsReturnedException If multiple messages found (should not happen)
	 */
	public function find(int $id): Message;

	/**
	 * Find all messages in a conversation
	 *
	 * Retrieves all messages for a specific conversation with pagination support.
	 * Results are ordered by creation date ascending (oldest first) for chronological display.
	 *
	 * @param int       $conversationId Conversation ID to filter messages by
	 * @param ListQuery $page           The page: limit (default 100) and offset (default 0)
	 *
	 * @return Message[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Notifications\Db\Message>
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function findByConversation(int $conversationId, ListQuery $page = new ListQuery(limit: 100)): array;

	/**
	 * Find recent messages in a conversation
	 *
	 * Gets the most recent N messages from a conversation and returns them
	 * in chronological order (oldest first) for display purposes.
	 *
	 * @param int $conversationId Conversation ID to filter messages by
	 * @param int $limit Number of recent messages to get (default: 10)
	 *
	 * @return Message[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Notifications\Db\Message>
	 */
	public function findRecentByConversation(int $conversationId, int $limit = 10): array;

	/**
	 * Count messages in a conversation
	 *
	 * Counts total number of messages in a specific conversation.
	 * Useful for pagination and statistics.
	 *
	 * @param int $conversationId Conversation ID to count messages for
	 *
	 * @return int Total message count (0 or positive integer)
	 */
	public function countByConversation(int $conversationId): int;

	/**
	 * Delete all messages in a conversation
	 *
	 * Used when hard-deleting a conversation.
	 *
	 * @param int $conversationId Conversation ID
	 *
	 * @return int Number of messages deleted
	 *
	 * @psalm-suppress PossiblyUnusedReturnValue
	 */
	public function deleteByConversation(int $conversationId): int|\OCP\DB\IResult;

	/**
	 * Insert through the mapper (QBMapper).
	 *
	 * @param Message $entity The entity.
	 *
	 * @return Message The stored entity.
	 */
	public function insert(Entity $entity): Entity;
}//end interface
