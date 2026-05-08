<?php

/**
 * NotificationDigest — pending-notification aggregator.
 *
 * Buckets pending notification events by recipient so a digest job
 * can flush them in one delivery per recipient instead of N separate
 * dispatches. Pure-domain — no DB, no scheduling. The digest queue is
 * mutable; flushing returns the buckets and clears state.
 *
 * Used as the primitive layer beneath a future BatchNotificationJob
 * background job that periodically flushes the queue and dispatches a
 * digest message per recipient. The job composes this primitive with
 * NotificationCoalescer (already in the codebase) for content-side
 * deduplication.
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
 * @spec openspec/changes/notificatie-engine/specs/notificatie-engine/spec.md "Notifications MUST support batching and digest delivery"
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

/**
 * Aggregates pending notifications per recipient until flushed.
 */
class NotificationDigest
{

    /**
     * Pending events keyed by recipient identifier.
     *
     * @var array<string, array<int, array>>
     */
    private array $pendingByRecipient = [];

    /**
     * Add a notification event to the recipient's bucket.
     *
     * @param string $recipientId The recipient (uid, email, webhook id…).
     * @param array  $event       The event payload (free-form).
     *
     * @return void
     */
    public function enqueue(string $recipientId, array $event): void
    {
        if (isset($this->pendingByRecipient[$recipientId]) === false) {
            $this->pendingByRecipient[$recipientId] = [];
        }

        $this->pendingByRecipient[$recipientId][] = $event;

    }//end enqueue()

    /**
     * Number of recipients currently buffered.
     *
     * @return int
     */
    public function recipientCount(): int
    {
        return count($this->pendingByRecipient);

    }//end recipientCount()

    /**
     * Number of pending events for a single recipient (0 if absent).
     *
     * @param string $recipientId The recipient identifier.
     *
     * @return int
     */
    public function pendingCount(string $recipientId): int
    {
        return count(($this->pendingByRecipient[$recipientId] ?? []));

    }//end pendingCount()

    /**
     * Total pending events across all recipients.
     *
     * @return int
     */
    public function totalPending(): int
    {
        $total = 0;
        foreach ($this->pendingByRecipient as $events) {
            $total += count($events);
        }

        return $total;

    }//end totalPending()

    /**
     * Flush the queue: returns one bucket per recipient with their
     * pending events, in original enqueue order. Clears state.
     *
     * @return array<string, array<int, array>> recipientId → list of events
     */
    public function flush(): array
    {
        $buckets = $this->pendingByRecipient;
        $this->pendingByRecipient = [];
        return $buckets;

    }//end flush()
}//end class
