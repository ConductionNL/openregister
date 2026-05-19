<?php

/**
 * NotificationReadState
 *
 * Pure-domain in-memory read/unread tracker.
 * The future DB-backed NotificationReadStateMapper will follow this contract.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/notificatie-engine/tasks.md#task-13
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

/**
 * Per-user, per-notification read/unread state tracker.
 *
 * @psalm-suppress UnusedClass
 */
class NotificationReadState
{

    /**
     * Set of (userId, notificationId) tuples that are marked read.
     *
     * Key: "{userId}::{notificationId}"
     *
     * @var array<string, true>
     */
    private array $readTuples = [];

    /**
     * Mark a notification as read for a user.
     *
     * Idempotent — same call twice still reads as true.
     *
     * @param string $userId         User identifier.
     * @param string $notificationId Notification identifier.
     *
     * @return void
     *
     * @spec openspec/changes/notificatie-engine/tasks.md#task-13
     */
    public function markRead(string $userId, string $notificationId): void
    {
        $this->readTuples[$this->key(userId: $userId, notificationId: $notificationId)] = true;
    }//end markRead()

    /**
     * Mark a notification as unread for a user.
     *
     * @param string $userId         User identifier.
     * @param string $notificationId Notification identifier.
     *
     * @return void
     *
     * @spec openspec/changes/notificatie-engine/tasks.md#task-13
     */
    public function markUnread(string $userId, string $notificationId): void
    {
        unset($this->readTuples[$this->key(userId: $userId, notificationId: $notificationId)]);
    }//end markUnread()

    /**
     * Check whether a notification is read for a user.
     *
     * @param string $userId         User identifier.
     * @param string $notificationId Notification identifier.
     *
     * @return bool
     *
     * @spec openspec/changes/notificatie-engine/tasks.md#task-13
     */
    public function isRead(string $userId, string $notificationId): bool
    {
        return isset($this->readTuples[$this->key(userId: $userId, notificationId: $notificationId)]);
    }//end isRead()

    /**
     * Total number of read (userId, notificationId) tuples across all users.
     *
     * @return int
     *
     * @spec openspec/changes/notificatie-engine/tasks.md#task-13
     */
    public function readCount(): int
    {
        return count($this->readTuples);
    }//end readCount()

    /**
     * Build composite key.
     *
     * @param string $userId         User identifier.
     * @param string $notificationId Notification identifier.
     *
     * @return string
     */
    private function key(string $userId, string $notificationId): string
    {
        return $userId.'::'.$notificationId;
    }//end key()
}//end class
