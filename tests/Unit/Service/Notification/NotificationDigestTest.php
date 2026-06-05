<?php

/**
 * Unit tests for NotificationDigest queue primitive.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Notification;

use OCA\OpenRegister\Service\Notification\NotificationDigest;
use PHPUnit\Framework\TestCase;

class NotificationDigestTest extends TestCase
{


    public function testEmptyDigestHasZeroCounts(): void
    {
        $digest = new NotificationDigest();
        $this->assertSame(0, $digest->recipientCount());
        $this->assertSame(0, $digest->totalPending());
        $this->assertSame(0, $digest->pendingCount(recipientId: 'jan'));

    }//end testEmptyDigestHasZeroCounts()


    public function testEnqueueAccumulatesPerRecipient(): void
    {
        $digest = new NotificationDigest();
        $digest->enqueue(recipientId: 'jan', event: ['type' => 'create', 'object' => 'a']);
        $digest->enqueue(recipientId: 'jan', event: ['type' => 'update', 'object' => 'a']);
        $digest->enqueue(recipientId: 'piet', event: ['type' => 'create', 'object' => 'b']);

        $this->assertSame(2, $digest->recipientCount());
        $this->assertSame(3, $digest->totalPending());
        $this->assertSame(2, $digest->pendingCount(recipientId: 'jan'));
        $this->assertSame(1, $digest->pendingCount(recipientId: 'piet'));

    }//end testEnqueueAccumulatesPerRecipient()


    public function testFlushReturnsBucketsAndClears(): void
    {
        $digest = new NotificationDigest();
        $digest->enqueue(recipientId: 'jan', event: ['type' => 'create']);
        $digest->enqueue(recipientId: 'piet', event: ['type' => 'update']);

        $buckets = $digest->flush();

        $this->assertArrayHasKey('jan', $buckets);
        $this->assertArrayHasKey('piet', $buckets);
        $this->assertSame([['type' => 'create']], $buckets['jan']);
        $this->assertSame([['type' => 'update']], $buckets['piet']);

        // After flush the queue MUST be empty.
        $this->assertSame(0, $digest->recipientCount());
        $this->assertSame(0, $digest->totalPending());
        $this->assertSame([], $digest->flush());

    }//end testFlushReturnsBucketsAndClears()


    public function testFlushPreservesEnqueueOrderPerRecipient(): void
    {
        $digest = new NotificationDigest();
        $digest->enqueue(recipientId: 'jan', event: ['n' => 1]);
        $digest->enqueue(recipientId: 'jan', event: ['n' => 2]);
        $digest->enqueue(recipientId: 'jan', event: ['n' => 3]);

        $buckets = $digest->flush();
        $ns      = array_map(fn($e) => $e['n'], $buckets['jan']);
        $this->assertSame([1, 2, 3], $ns);

    }//end testFlushPreservesEnqueueOrderPerRecipient()


}//end class
