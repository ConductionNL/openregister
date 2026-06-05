<?php

/**
 * Unit tests for NotificationReadState.
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

use OCA\OpenRegister\Service\Notification\NotificationReadState;
use PHPUnit\Framework\TestCase;

class NotificationReadStateTest extends TestCase
{


    public function testNewNotificationsStartUnread(): void
    {
        $state = new NotificationReadState();
        $this->assertFalse($state->isRead(userId: 'jan', notificationId: 'n-1'));
        $this->assertSame(0, $state->readCount());

    }//end testNewNotificationsStartUnread()


    public function testMarkReadFlipsTheState(): void
    {
        $state = new NotificationReadState();
        $state->markRead(userId: 'jan', notificationId: 'n-1');
        $this->assertTrue($state->isRead(userId: 'jan', notificationId: 'n-1'));
        $this->assertSame(1, $state->readCount());

    }//end testMarkReadFlipsTheState()


    public function testMarkReadIsIdempotent(): void
    {
        $state = new NotificationReadState();
        $state->markRead(userId: 'jan', notificationId: 'n-1');
        $state->markRead(userId: 'jan', notificationId: 'n-1');
        $this->assertSame(1, $state->readCount());

    }//end testMarkReadIsIdempotent()


    public function testMarkUnreadRestoresUnreadState(): void
    {
        $state = new NotificationReadState();
        $state->markRead(userId: 'jan', notificationId: 'n-1');
        $state->markUnread(userId: 'jan', notificationId: 'n-1');
        $this->assertFalse($state->isRead(userId: 'jan', notificationId: 'n-1'));
        $this->assertSame(0, $state->readCount());

    }//end testMarkUnreadRestoresUnreadState()


    public function testReadStateIsScopedPerUserPerNotification(): void
    {
        $state = new NotificationReadState();
        $state->markRead(userId: 'jan', notificationId: 'n-1');

        // Other users see the same notification as still unread.
        $this->assertFalse($state->isRead(userId: 'piet', notificationId: 'n-1'));

        // Jan sees a different notification as still unread.
        $this->assertFalse($state->isRead(userId: 'jan', notificationId: 'n-2'));

        // Read count counts read tuples, not notifications.
        $state->markRead(userId: 'piet', notificationId: 'n-1');
        $this->assertSame(2, $state->readCount());

    }//end testReadStateIsScopedPerUserPerNotification()


}//end class
