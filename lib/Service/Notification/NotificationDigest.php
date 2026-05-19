<?php

/**
 * NotificationDigest
 *
 * Per-recipient in-memory event aggregator used by the digest/batching layer.
 * Pure-domain — no DB, no scheduling — so the contract is fully unit-tested.
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
 * @spec openspec/changes/notificatie-engine/tasks.md#task-6
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

/**
 * Aggregates notification events per recipient for later digest delivery.
 *
 * The future BatchNotificationJob instantiates this, enqueues events during
 * a window, then flushes all buckets in one pass.
 *
 * @psalm-suppress UnusedClass
 */
class NotificationDigest
{

    /**
     * Per-recipient event queues.
     *
     * @var array<string, list<array<string,mixed>>>
     */
    private array $queues = [];

    /**
     * Enqueue an event for a recipient.
     *
     * @param string              $recipientId Unique recipient identifier (uid or email).
     * @param array<string,mixed> $event       Notification event payload.
     *
     * @return void
     *
     * @spec openspec/changes/notificatie-engine/tasks.md#task-6
     */
    public function enqueue(string $recipientId, array $event): void
    {
        if (isset($this->queues[$recipientId]) === false) {
            $this->queues[$recipientId] = [];
        }

        $this->queues[$recipientId][] = $event;
    }//end enqueue()

    /**
     * Flush all queues and return one bucket per recipient.
     *
     * Clears internal state after flush.
     *
     * @return array<string, list<array<string,mixed>>> Map of recipientId → ordered events.
     *
     * @spec openspec/changes/notificatie-engine/tasks.md#task-6
     */
    public function flush(): array
    {
        $buckets      = $this->queues;
        $this->queues = [];
        return $buckets;
    }//end flush()

    /**
     * Number of distinct recipients with pending events.
     *
     * @return int
     *
     * @spec openspec/changes/notificatie-engine/tasks.md#task-6
     */
    public function recipientCount(): int
    {
        return count($this->queues);
    }//end recipientCount()

    /**
     * Number of pending events for a specific recipient.
     *
     * @param string $recipientId Recipient identifier.
     *
     * @return int
     *
     * @spec openspec/changes/notificatie-engine/tasks.md#task-6
     */
    public function pendingCount(string $recipientId): int
    {
        return count($this->queues[$recipientId] ?? []);
    }//end pendingCount()

    /**
     * Total pending events across all recipients.
     *
     * @return int
     *
     * @spec openspec/changes/notificatie-engine/tasks.md#task-6
     */
    public function totalPending(): int
    {
        return array_sum(array_map('count', $this->queues));
    }//end totalPending()
}//end class
