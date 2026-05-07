<?php

/**
 * NotificationReadState — cross-channel read/unread tracker.
 *
 * Pure-domain in-memory tracker for whether a `(userId, notificationId)`
 * tuple is read. Mirrors the DB-backed mapper that the persistent
 * read-state extension would use, but stays in-memory so the read-state
 * semantics can be unit-tested without touching a database.
 *
 * The contract:
 *   - newly-recorded notifications start UNREAD;
 *   - markRead is idempotent (same call twice → still read);
 *   - markUnread restores the unread state;
 *   - reading is per-user-per-notification: marking it read for jan
 *     does not affect piet's view.
 *
 * The future DB-backed mapper layered on top of this primitive
 * (`oc_openregister_notification_readstate` table with a unique
 * `(user_id, notification_id)` index) follows exactly these semantics.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/notificatie-engine/specs/notificatie-engine/spec.md "Read/unread tracking MUST be maintained per user per notification"
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

/**
 * Tracks read/unread state per (userId, notificationId) tuple.
 */
class NotificationReadState
{

    /**
     * Set of "read" tuples; key = `<userId>|<notificationId>`.
     *
     * @var array<string, true>
     */
    private array $read = [];

    /**
     * Mark a notification as read for a user.
     *
     * @param string $userId         The user id.
     * @param string $notificationId The notification id.
     *
     * @return void
     */
    public function markRead(string $userId, string $notificationId): void
    {
        $this->read[$this->key(userId: $userId, notificationId: $notificationId)] = true;

    }//end markRead()

    /**
     * Reverse a markRead — bring the notification back to unread.
     *
     * @param string $userId         The user id.
     * @param string $notificationId The notification id.
     *
     * @return void
     */
    public function markUnread(string $userId, string $notificationId): void
    {
        unset($this->read[$this->key(userId: $userId, notificationId: $notificationId)]);

    }//end markUnread()

    /**
     * Test whether a notification has been read by the user.
     *
     * @param string $userId         The user id.
     * @param string $notificationId The notification id.
     *
     * @return bool
     */
    public function isRead(string $userId, string $notificationId): bool
    {
        return isset($this->read[$this->key(userId: $userId, notificationId: $notificationId)]);

    }//end isRead()

    /**
     * Number of "read" rows currently tracked across all users.
     *
     * @return int
     */
    public function readCount(): int
    {
        return count($this->read);

    }//end readCount()

    /**
     * Build the storage key for a tuple.
     *
     * @param string $userId         The user id.
     * @param string $notificationId The notification id.
     *
     * @return string
     */
    private function key(string $userId, string $notificationId): string
    {
        return $userId.'|'.$notificationId;

    }//end key()
}//end class
