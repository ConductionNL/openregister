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

use OCA\OpenRegister\Mcp\Service\Chat\TitleClaim;
use OCA\OpenRegister\Mcp\Db\ConversationScope;
use OCA\OpenRegister\Db\ListQuery;
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
	 * Find one page of a user's conversations, newest first.
	 *
	 * @param ConversationScope $scope The owning user, optionally the organisation, and whether
	 *                                 soft-deleted conversations are included.
	 * @param ListQuery         $page  The limit and offset.
	 *
	 * @return \OCA\OpenRegister\Mcp\Db\Conversation[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Mcp\Db\Conversation>
	 */
	public function findByUser(ConversationScope $scope, ListQuery $page): array;

	/**
	 * Find one page of a user's soft-deleted conversations (archive)
	 *
	 * @param ConversationScope $scope The owning user and, optionally, the organisation.
	 * @param ListQuery         $page  The limit and offset.
	 *
	 * @return \OCA\OpenRegister\Mcp\Db\Conversation[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Mcp\Db\Conversation>
	 */
	public function findDeletedByUser(ConversationScope $scope, ListQuery $page): array;

	/**
	 * Find the live conversation titles of a user-agent pair that start with a wanted title
	 *
	 * Used to check for duplicate conversation names and generate unique titles: the
	 * wanted title itself and its numbered variants all match.
	 *
	 * @param TitleClaim $claim The wanted title and the user-agent pair.
	 *
	 * @return array Array of matching conversation titles
	 *
	 * @psalm-return list<mixed>
	 */
	public function findTitlesByUserAgent(TitleClaim $claim): array;

	/**
	 * Count conversations for a user
	 *
	 * @param ConversationScope $scope The owning user, optionally the organisation, and whether
	 *                                 soft-deleted conversations are included.
	 *
	 * @return int Total count
	 */
	public function countByUser(ConversationScope $scope): int;

	/**
	 * Count soft-deleted conversations for a user (archived)
	 *
	 * @param ConversationScope $scope The owning user and, optionally, the organisation.
	 *
	 * @return int Count of archived conversations
	 */
	public function countDeletedByUser(ConversationScope $scope): int;

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
	 * @param ConversationScope $scope The user and, optionally, their current organisation.
	 *
	 * @return bool True if user can access
	 */
	public function canUserAccessConversation(Entity $conversation, ConversationScope $scope): bool;

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
