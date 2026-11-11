<?php

/**
 * FeedbackMapperInterface: what callers outside the component use of FeedbackMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCA\OpenRegister\Mcp\Db\Conversation;
use OCA\OpenRegister\Notifications\Db\Message;
use OCP\AppFramework\Db\Entity;

/**
 * The chat feedback rows the chat and conversation endpoints read and write.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface FeedbackMapperInterface {

	/**
	 * Delete all feedback for a conversation
	 *
	 * @param int $conversationId Conversation ID
	 *
	 * @return void
	 */
	public function deleteByConversation(int $conversationId): void;

	/**
	 * Find feedback for a specific message
	 *
	 * @param int $messageId Message ID
	 * @param string $userId User ID (to ensure user can only see their own feedback)
	 *
	 * @return Feedback|null
	 */
	public function findByMessage(int $messageId, string $userId): ?Feedback;

	/**
	 * Override insert to generate UUID and timestamps
	 *
	 * @param Entity $entity Entity to insert
	 *
	 * @return Feedback Inserted entity
	 * @psalm-return Feedback
	 */
	public function insert(Entity $entity): Feedback;

	/**
	 * Override update to set updated timestamp
	 *
	 * @param Entity $entity Entity to update
	 *
	 * @return Feedback Updated entity
	 * @psalm-return Feedback
	 */
	public function update(Entity $entity): Feedback;
}//end interface
