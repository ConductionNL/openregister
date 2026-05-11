<?php

/**
 * Unit tests for the NotificationDispatchLog entity.
 *
 * Verifies that every typed field round-trips through the accessors
 * and that jsonSerialize() returns the documented shape.
 *
 * @category Tests\Unit\Db
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\NotificationDispatchLog;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NotificationDispatchLog entity.
 */
class NotificationDispatchLogTest extends TestCase
{

    public function testAllFieldsRoundTrip(): void
    {
        $entity = new NotificationDispatchLog();
        $now    = new DateTime('2026-05-11T12:00:00+00:00');

        $entity->setNotificationSlug('reminderT30');
        $entity->setIdempotencyKey('uuid-123-T30-2026-06-01');
        $entity->setDispatchedAt($now);

        $this->assertSame('reminderT30', $entity->getNotificationSlug());
        $this->assertSame('uuid-123-T30-2026-06-01', $entity->getIdempotencyKey());
        $this->assertSame($now, $entity->getDispatchedAt());
    }//end testAllFieldsRoundTrip()

    public function testJsonSerializeReturnsDocumentedShape(): void
    {
        $entity = new NotificationDispatchLog();
        $entity->setNotificationSlug('reminderT30');
        $entity->setIdempotencyKey('uuid-123-T30-2026-06-01');
        $entity->setDispatchedAt(new DateTime('2026-05-11T12:00:00+00:00'));

        $serialized = $entity->jsonSerialize();

        $this->assertSame('reminderT30', $serialized['notificationSlug']);
        $this->assertSame('uuid-123-T30-2026-06-01', $serialized['idempotencyKey']);
        $this->assertSame('2026-05-11T12:00:00+00:00', $serialized['dispatchedAt']);
    }//end testJsonSerializeReturnsDocumentedShape()

    public function testJsonSerializeWithNullDispatchedAt(): void
    {
        $entity = new NotificationDispatchLog();
        $entity->setNotificationSlug('test');
        $entity->setIdempotencyKey('key');

        $serialized = $entity->jsonSerialize();
        $this->assertNull($serialized['dispatchedAt']);
    }//end testJsonSerializeWithNullDispatchedAt()
}//end class
