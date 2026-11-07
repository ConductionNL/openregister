<?php

/**
 * ConversationMapperInterface: the public surface of ConversationMapper, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp\Db
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

namespace OCA\OpenRegister\Kernel\Contracts;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;

/**
 * Every public method of ConversationMapper. Callers depend on this interface, not on the class.
 */
interface ConversationMapperInterface {

	/**
	 * Insert a new conversation entity
	 *
	 * Inserts conversation entity into database with automatic UUID and timestamp
	 * generation. Dispatches ConversationCreatedEvent after successful insertion.
	 *
	 * @param Entity $entity The conversation entity to insert
	 *
	 * @return \OCA\OpenRegister\Mcp\Db\Conversation The inserted conversation entity with database-generated ID
	 */
	public function insert(Entity $entity): Entity;

	/**
	 * Update a conversation entity
	 *
	 * Updates conversation entity in database with automatic updated timestamp.
	 * Dispatches ConversationUpdatedEvent with both old and new entity states.
	 *
	 * @param Entity $entity The conversation entity to update
	 *
	 * @return \OCA\OpenRegister\Mcp\Db\Conversation The updated conversation entity
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If conversation not found
	 */
	public function update(Entity $entity): Entity;

	/**
	 * Delete a conversation entity
	 *
	 * @param Entity $entity The conversation entity to delete
	 *
	 * @return \OCA\OpenRegister\Mcp\Db\Conversation The deleted conversation entity
	 *
	 * @psalm-suppress PossiblyUnusedReturnValue
	 */
	public function delete(Entity $entity): Entity;

	/**
	 * Find a conversation by its ID
	 *
	 * @param int $id \OCA\OpenRegister\Mcp\Db\Conversation ID
	 *
	 * @return \OCA\OpenRegister\Mcp\Db\Conversation The conversation entity
	 *
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function find(int $id): Entity;

	/**
	 * Find a conversation by its UUID
	 *
	 * @param string $uuid \OCA\OpenRegister\Mcp\Db\Conversation UUID
	 *
	 * @return \OCA\OpenRegister\Mcp\Db\Conversation The conversation entity
	 *
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function findByUuid(string $uuid): Entity;

	/**
	 * Find all conversations for a user
	 *
	 * @param string $userId User ID
	 * @param string|null $organisation Optional organisation UUID filter
	 * @param bool $includeDeleted Whether to include soft-deleted conversations
	 * @param int $limit Maximum number of results
	 * @param int $offset Offset for pagination
	 *
	 * @return \OCA\OpenRegister\Mcp\Db\Conversation[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Mcp\Db\Conversation>
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Include deleted toggle is intentional
	 */
	public function findByUser(
		string $userId,
		?string $organisation = null,
		bool $includeDeleted = false,
		int $limit = 50,
		int $offset = 0,
	): array;

	/**
	 * Find all soft-deleted conversations for a user (archive)
	 *
	 * @param string $userId User ID
	 * @param null|string $organisation Optional organisation filter
	 * @param int $limit Maximum number of results
	 * @param int $offset Offset for pagination
	 *
	 * @return \OCA\OpenRegister\Mcp\Db\Conversation[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Mcp\Db\Conversation>
	 */
	public function findDeletedByUser(
		string $userId,
		?string $organisation = null,
		int $limit = 50,
		int $offset = 0,
	): array;

	/**
	 * Find conversations by user and agent with matching title pattern
	 *
	 * Used to check for duplicate conversation names and generate unique titles.
	 *
	 * @param string $userId User ID
	 * @param int $agentId Agent ID
	 * @param string $titlePattern Title pattern to match (e.g., "New \OCA\OpenRegister\Mcp\Db\Conversation%")
	 *
	 * @return array Array of matching conversation titles
	 *
	 * @psalm-return list<mixed>
	 */
	public function findTitlesByUserAgent(
		string $userId,
		int $agentId,
		string $titlePattern,
	): array;

	/**
	 * Count conversations for a user
	 *
	 * @param string $userId User ID
	 * @param string|null $organisation Optional organisation UUID filter
	 * @param bool $includeDeleted Whether to include soft-deleted conversations
	 *
	 * @return int Total count
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Include deleted toggle is intentional
	 */
	public function countByUser(
		string $userId,
		?string $organisation = null,
		bool $includeDeleted = false,
	): int;

	/**
	 * Count soft-deleted conversations for a user (archived)
	 *
	 * @param string $userId User ID
	 * @param string|null $organisation Optional organisation filter
	 *
	 * @return int Count of archived conversations
	 */
	public function countDeletedByUser(
		string $userId,
		?string $organisation = null,
	): int;

	/**
	 * Soft delete a conversation
	 *
	 * @param int $id \OCA\OpenRegister\Mcp\Db\Conversation ID
	 *
	 * @return \OCA\OpenRegister\Mcp\Db\Conversation The updated conversation entity
	 *
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 *
	 * @psalm-suppress PossiblyUnusedReturnValue
	 */
	public function softDelete(int $id): Entity;

	/**
	 * Restore a soft-deleted conversation
	 *
	 * @param int $id \OCA\OpenRegister\Mcp\Db\Conversation ID
	 *
	 * @return \OCA\OpenRegister\Mcp\Db\Conversation The updated conversation entity
	 *
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function restore(int $id): Entity;

	/**
	 * Check if user can access a conversation
	 *
	 * Access rules:
	 * - User must be the owner of the conversation
	 * - Conversation must belong to the user's current organisation (if provided)
	 *
	 * @param \OCA\OpenRegister\Mcp\Db\Conversation $conversation Conversation entity
	 * @param string $userId User ID
	 * @param string|null $organisationUuid Current organisation UUID (optional)
	 *
	 * @return bool True if user can access
	 */
	public function canUserAccessConversation(
		Entity $conversation,
		string $userId,
		?string $organisationUuid = null,
	): bool;

	/**
	 * Check if user can modify a conversation
	 *
	 * Modification rules:
	 * - User must be the owner of the conversation
	 *
	 * @param \OCA\OpenRegister\Mcp\Db\Conversation $conversation Conversation entity
	 * @param string $userId User ID
	 *
	 * @return bool True if user can modify
	 */
	public function canUserModifyConversation(Entity $conversation, string $userId): bool;
}//end interface
