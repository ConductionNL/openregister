<?php

/**
 * NotificationDigestTest
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/notificatie-engine/tasks.md#task-6
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

use OCA\OpenRegister\Service\Notification\NotificationDigest;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NotificationDigest pure-domain aggregator.
 */
class NotificationDigestTest extends TestCase
{

    private NotificationDigest $digest;

    protected function setUp(): void
    {
        $this->digest = new NotificationDigest();
    }

    /**
     * Empty digest has zero counts.
     */
    public function testEmptyDigestHasZeroCounts(): void
    {
        self::assertSame(0, $this->digest->recipientCount());
        self::assertSame(0, $this->digest->pendingCount(recipientId: 'alice'));
        self::assertSame(0, $this->digest->totalPending());
    }

    /**
     * Enqueue accumulates per recipient.
     */
    public function testEnqueueAccumulatesPerRecipient(): void
    {
        $this->digest->enqueue(recipientId: 'alice', event: ['type' => 'created']);
        $this->digest->enqueue(recipientId: 'alice', event: ['type' => 'updated']);
        $this->digest->enqueue(recipientId: 'bob', event: ['type' => 'deleted']);

        self::assertSame(2, $this->digest->recipientCount());
        self::assertSame(2, $this->digest->pendingCount(recipientId: 'alice'));
        self::assertSame(1, $this->digest->pendingCount(recipientId: 'bob'));
        self::assertSame(3, $this->digest->totalPending());
    }

    /**
     * Flush returns buckets and clears state.
     */
    public function testFlushReturnsBucketsAndClears(): void
    {
        $this->digest->enqueue(recipientId: 'alice', event: ['type' => 'created']);
        $this->digest->enqueue(recipientId: 'bob', event: ['type' => 'updated']);

        $buckets = $this->digest->flush();

        self::assertArrayHasKey('alice', $buckets);
        self::assertArrayHasKey('bob', $buckets);
        self::assertSame(0, $this->digest->totalPending());
        self::assertSame(0, $this->digest->recipientCount());
    }

    /**
     * Flush preserves per-recipient enqueue order.
     */
    public function testFlushPreservesEnqueueOrder(): void
    {
        $e1 = ['type' => 'created', 'seq' => 1];
        $e2 = ['type' => 'updated', 'seq' => 2];
        $e3 = ['type' => 'deleted', 'seq' => 3];

        $this->digest->enqueue(recipientId: 'alice', event: $e1);
        $this->digest->enqueue(recipientId: 'alice', event: $e2);
        $this->digest->enqueue(recipientId: 'alice', event: $e3);

        $buckets = $this->digest->flush();

        self::assertSame([$e1, $e2, $e3], $buckets['alice']);
    }
}
