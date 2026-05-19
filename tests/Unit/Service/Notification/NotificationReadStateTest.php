<?php

/**
 * NotificationReadStateTest
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/notificatie-engine/tasks.md#task-13
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

use OCA\OpenRegister\Service\Notification\NotificationReadState;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NotificationReadState pure-domain tracker.
 */
class NotificationReadStateTest extends TestCase
{

    private NotificationReadState $state;

    protected function setUp(): void
    {
        $this->state = new NotificationReadState();
    }

    /**
     * New tuples start unread.
     */
    public function testNewTuplesStartUnread(): void
    {
        self::assertFalse($this->state->isRead(userId: 'alice', notificationId: 'n1'));
    }

    /**
     * markRead flips state to read.
     */
    public function testMarkReadFlipsState(): void
    {
        $this->state->markRead(userId: 'alice', notificationId: 'n1');
        self::assertTrue($this->state->isRead(userId: 'alice', notificationId: 'n1'));
    }

    /**
     * markRead is idempotent.
     */
    public function testMarkReadIsIdempotent(): void
    {
        $this->state->markRead(userId: 'alice', notificationId: 'n1');
        $this->state->markRead(userId: 'alice', notificationId: 'n1');
        self::assertTrue($this->state->isRead(userId: 'alice', notificationId: 'n1'));
        self::assertSame(1, $this->state->readCount());
    }

    /**
     * markUnread restores unread state.
     */
    public function testMarkUnreadRestoresState(): void
    {
        $this->state->markRead(userId: 'alice', notificationId: 'n1');
        $this->state->markUnread(userId: 'alice', notificationId: 'n1');
        self::assertFalse($this->state->isRead(userId: 'alice', notificationId: 'n1'));
        self::assertSame(0, $this->state->readCount());
    }

    /**
     * Per-user-per-notification scoping: readCount = number of read tuples.
     */
    public function testPerUserPerNotificationScoping(): void
    {
        $this->state->markRead(userId: 'alice', notificationId: 'n1');
        $this->state->markRead(userId: 'bob', notificationId: 'n1');
        $this->state->markRead(userId: 'alice', notificationId: 'n2');

        self::assertTrue($this->state->isRead(userId: 'alice', notificationId: 'n1'));
        self::assertTrue($this->state->isRead(userId: 'bob', notificationId: 'n1'));
        self::assertTrue($this->state->isRead(userId: 'alice', notificationId: 'n2'));
        self::assertFalse($this->state->isRead(userId: 'bob', notificationId: 'n2'));
        self::assertSame(3, $this->state->readCount());
    }
}
